<?php

declare(strict_types=1);

namespace RoboJackSparrow\Research\Dto;

final class ResearchData
{
    /**
     * @param string[] $facts
     * @param ResearchSource[] $sources
     * @param string[] $entities
     */
    public function __construct(
        private array $facts,
        private array $sources,
        private ?string $answer,
        private array $entities,
        private bool $fromFallback = false
    ) {
    }

    /**
     * @return string[]
     */
    public function getFacts(): array
    {
        return $this->facts;
    }

    /**
     * @return ResearchSource[]
     */
    public function getSources(): array
    {
        return $this->sources;
    }

    public function getAnswer(): ?string
    {
        return $this->answer;
    }

    /**
     * @return string[]
     */
    public function getEntities(): array
    {
        return $this->entities;
    }

    /**
     * True when Tavily was unavailable/unconfigured and this data was
     * derived directly from the scraped/RSS content instead.
     */
    public function isFromFallback(): bool
    {
        return $this->fromFallback;
    }

    /**
     * Assembles a compact factual briefing to inject into the content
     * generation prompt (Fase 5), as verified facts + cited sources.
     */
    public function toBriefing(): string
    {
        $lines = [];

        if ($this->answer !== null && trim($this->answer) !== '') {
            $lines[] = 'Resumo verificado: ' . trim($this->answer);
        }

        if ($this->facts !== []) {
            $lines[] = 'Fatos:';
            foreach ($this->facts as $fact) {
                $lines[] = '- ' . $fact;
            }
        }

        if ($this->sources !== []) {
            $lines[] = 'Fontes:';
            foreach ($this->sources as $source) {
                $label = $source->getTitle() !== '' ? $source->getTitle() : $source->getUrl();
                $lines[] = sprintf('- %s (%s)', $label, $source->getUrl());
            }
        }

        return implode("\n", $lines);
    }
}
