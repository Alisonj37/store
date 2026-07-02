<?php

declare(strict_types=1);

namespace RoboJackSparrow\Content;

final class JsonResponseParser
{
    /**
     * Decodes a JSON object/array from a raw LLM text response, defensively
     * stripping ```json ... ``` code fences some models add despite
     * `isJsonMode` / explicit instructions not to.
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

        return is_array($data) ? $data : null;
    }
}
