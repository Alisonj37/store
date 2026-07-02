<?php

declare(strict_types=1);

namespace RoboJackSparrow\Research;

use RoboJackSparrow\Research\Dto\ResearchSource;

/**
 * Provedor principal de pesquisa em tempo real. Enriquece o briefing
 * factual com dados verificados ANTES da geracao de conteudo (Fase 5).
 *
 * Setting associado: rjs_tavily_api_key
 */
class TavilyDriver
{
    private const API_URL = 'https://api.tavily.com/search';
    private const TIMEOUT = 30;
    private const SEARCH_DEPTH = 'advanced';
    private const MAX_RESULTS = 5;

    public function __construct(private string $apiKey)
    {
    }

    public function getName(): string
    {
        return 'tavily';
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    /**
     * @return array{answer: ?string, results: ResearchSource[]}
     */
    public function search(string $query): array
    {
        if (!$this->isConfigured()) {
            throw new ResearchException('Tavily API key is not configured');
        }

        $response = wp_remote_post(self::API_URL, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'query'          => $query,
                'search_depth'   => self::SEARCH_DEPTH,
                'include_answer' => true,
                'max_results'    => self::MAX_RESULTS,
            ]),
        ]);

        if (is_wp_error($response)) {
            throw new ResearchException("Tavily request failed for \"{$query}\": " . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $message = is_array($body) ? (string) ($body['detail']['error'] ?? $body['error'] ?? "HTTP {$code}") : "HTTP {$code}";
            throw new ResearchException("Tavily API error for \"{$query}\": {$message}");
        }

        if (!is_array($body)) {
            throw new ResearchException("Tavily returned an unexpected payload for \"{$query}\"");
        }

        $answer = isset($body['answer']) && is_string($body['answer']) && trim($body['answer']) !== ''
            ? $body['answer']
            : null;

        return [
            'answer'  => $answer,
            'results' => $this->mapResults((array) ($body['results'] ?? [])),
        ];
    }

    /**
     * @return ResearchSource[]
     */
    private function mapResults(array $results): array
    {
        $mapped = [];

        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $mapped[] = new ResearchSource(
                url: (string) ($result['url'] ?? ''),
                title: (string) ($result['title'] ?? ''),
                content: (string) ($result['content'] ?? ''),
                score: isset($result['score']) ? (float) $result['score'] : null
            );
        }

        return $mapped;
    }
}
