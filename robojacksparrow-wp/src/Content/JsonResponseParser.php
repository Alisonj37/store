<?php

declare(strict_types=1);

namespace RoboJackSparrow\Content;

final class JsonResponseParser
{
    /**
     * Decodes a JSON object/array from a raw LLM text response, defensively
     * stripping ```json ... ``` code fences some models add despite
     * `isJsonMode` / explicit instructions not to.
     *
     * The single-call article generation asks for a large JSON blob (full
     * article body, FAQs, SEO fields) in one completion, which is far more
     * likely to get cut off mid-response when a provider hits its token
     * limit than the small responses the old multi-call pipeline used. When
     * straight decoding fails, attemptRepair() salvages whatever complete
     * title/sections/faqs survived before the cut-off point instead of
     * discarding the whole (often mostly-usable) response.
     */
    public static function decode(string $raw): ?array
    {
        $trimmed = trim($raw);

        if (str_starts_with($trimmed, '```')) {
            $trimmed = (string) preg_replace('/^```[a-zA-Z]*\s*/', '', $trimmed);
            $trimmed = (string) preg_replace('/```\s*$/', '', $trimmed);
            $trimmed = trim($trimmed);
        }

        $data = json_decode($trimmed, true);

        if (is_array($data)) {
            return $data;
        }

        return self::attemptRepair($trimmed);
    }

    /**
     * Truncates a broken JSON string back to the last point where every
     * open object/array could still be validly closed, then closes them,
     * tracking string/escape state so brackets inside string values aren't
     * mistaken for structural ones.
     *
     * @return array<string, mixed>|null
     */
    private static function attemptRepair(string $json): ?array
    {
        $length = strlen($json);
        $stack = [];
        $inString = false;
        $escape = false;
        $lastSafeIndex = -1;
        // Snapshot of $stack at the moment lastSafeIndex was recorded - the
        // stack keeps mutating for the rest of the (broken) string after
        // that point (e.g. a later section opens a brace it never closes),
        // so closing with the stack's *final* state would emit the wrong
        // number/order of closing brackets. Only the snapshot from the safe
        // point itself describes what's actually still open there.
        $lastSafeStack = [];

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($char === '\\') {
                    $escape = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            switch ($char) {
                case '"':
                    $inString = true;
                    break;
                case '{':
                case '[':
                    $stack[] = $char;
                    break;
                case '}':
                case ']':
                    array_pop($stack);
                    if ($stack !== []) {
                        $lastSafeIndex = $i + 1;
                        $lastSafeStack = $stack;
                    }
                    break;
                case ',':
                    if ($stack !== []) {
                        $lastSafeIndex = $i;
                        $lastSafeStack = $stack;
                    }
                    break;
            }
        }

        if ($lastSafeIndex <= 0 || $lastSafeStack === []) {
            return null;
        }

        $repaired = substr($json, 0, $lastSafeIndex);
        for ($i = count($lastSafeStack) - 1; $i >= 0; $i--) {
            $repaired .= $lastSafeStack[$i] === '{' ? '}' : ']';
        }

        $decoded = json_decode($repaired, true);

        return is_array($decoded) ? $decoded : null;
    }
}
