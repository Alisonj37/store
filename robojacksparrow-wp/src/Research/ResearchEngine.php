<?php

declare(strict_types=1);

namespace RoboJackSparrow\Research;

use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Research\Dto\ResearchData;
use RoboJackSparrow\Research\Dto\ResearchSource;
use RoboJackSparrow\Scraper\Dto\ScrapedContent;
use Throwable;

/**
 * Orquestra o enriquecimento factual de uma fonte raspada: chama o Tavily
 * para pesquisa em tempo real e monta um ResearchData com fatos
 * verificados + fontes citaveis. Se o Tavily nao estiver configurado ou
 * falhar, cai de volta para o conteudo raspado/RSS diretamente.
 */
class ResearchEngine
{
    public function __construct(
        private ?TavilyDriver $tavily,
        private Logger $logger
    ) {
    }

    public function enrich(ScrapedContent $source): ResearchData
    {
        if ($this->tavily === null || !$this->tavily->isConfigured()) {
            $this->logger->info('Tavily not configured, falling back to scraped/RSS content', [
                'url' => $source->getUrl(),
            ]);

            return $this->fallback($source);
        }

        try {
            $query = $this->buildQuery($source);
            $result = $this->tavily->search($query);

            $this->logger->info('Tavily enrichment succeeded', [
                'url'           => $source->getUrl(),
                'query'         => $query,
                'sources_found' => count($result['results']),
            ]);

            return new ResearchData(
                facts: $this->extractFacts($result['results']),
                sources: $result['results'],
                answer: $result['answer'],
                entities: $this->extractEntities($source, $result['answer']),
                fromFallback: false
            );
        } catch (Throwable $e) {
            $this->logger->warning('Tavily enrichment failed, falling back to scraped/RSS content', [
                'url'   => $source->getUrl(),
                'error' => $e->getMessage(),
            ]);

            return $this->fallback($source);
        }
    }

    private function buildQuery(ScrapedContent $source): string
    {
        $title = trim($source->getTitle());

        return $title !== '' ? $title : $this->firstSentences(trim($source->getText()), 2);
    }

    /**
     * @param ResearchSource[] $results
     * @return string[]
     */
    private function extractFacts(array $results): array
    {
        $facts = [];

        foreach ($results as $result) {
            $snippet = $this->firstSentences(trim($result->getContent()), 2);
            if ($snippet !== '') {
                $facts[] = $snippet;
            }
        }

        return $facts;
    }

    /**
     * Lightweight heuristic entity extraction (capitalized word sequences).
     * No external NLP service is used, to stay compatible with shared
     * hosting (no Redis, no external processes beyond the HTTP APIs).
     *
     * @return string[]
     */
    private function extractEntities(ScrapedContent $source, ?string $answer): array
    {
        $text = trim($source->getTitle() . ' ' . ($answer ?? ''));

        if ($text === '') {
            return [];
        }

        preg_match_all('/\b(?:[A-Z][\p{L}0-9]{2,}(?:\s+[A-Z][\p{L}0-9]{2,}){0,3})\b/u', $text, $matches);

        $entities = [];
        foreach ($matches[0] as $candidate) {
            $candidate = trim($candidate);
            if (mb_strlen($candidate) >= 3) {
                $entities[$candidate] = true;
            }
        }

        return array_keys($entities);
    }

    private function firstSentences(string $text, int $count): string
    {
        if ($text === '') {
            return '';
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];

        return trim(implode(' ', array_slice($sentences, 0, $count)));
    }

    /**
     * Sem chave do Tavily ou apos falha: usa o proprio conteudo
     * raspado/RSS como base factual, sem verificacao externa.
     */
    private function fallback(ScrapedContent $source): ResearchData
    {
        $snippet = $this->firstSentences(trim($source->getText()), 3);

        $sources = [];
        if ($source->getUrl() !== '') {
            $sources[] = new ResearchSource(
                url: $source->getUrl(),
                title: $source->getTitle(),
                content: $snippet,
                score: null
            );
        }

        return new ResearchData(
            facts: $snippet !== '' ? [$snippet] : [],
            sources: $sources,
            answer: null,
            entities: $this->extractEntities($source, null),
            fromFallback: true
        );
    }
}
