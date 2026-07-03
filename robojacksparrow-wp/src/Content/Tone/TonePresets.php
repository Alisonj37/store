<?php

declare(strict_types=1);

namespace RoboJackSparrow\Content\Tone;

/**
 * Curated tone/niche presets, used both by the admin UI (Configuracoes and
 * Gerar Artigo) and by ContentEngine to build the actual instruction text
 * sent to the LLM. A preset is a full behavioral instruction, not just a
 * single adjective, so the same plugin install can credibly write for
 * different niches (afiliado, noticia, tecnologia, etc.) depending on
 * what's selected per article or as the site-wide default.
 */
final class TonePresets
{
    /**
     * @var array<string, array{label: string, instructions: string}>
     */
    private const PRESETS = [
        'geral' => [
            'label'        => 'Geral (equilibrado)',
            'instructions' => 'Tom profissional e informativo, equilibrado e direto, adequado para um publico amplo.',
        ],
        'noticia' => [
            'label'        => 'Noticia (jornalistico)',
            'instructions' => 'Tom jornalistico: comece pelo mais importante (lide), responda quem, o que, quando, onde e '
                . 'por que logo no primeiro paragrafo, va do mais para o menos importante, seja factual e direto, '
                . 'evite opiniao pessoal e adjetivos exagerados.',
        ],
        'afiliado' => [
            'label'        => 'Afiliado (persuasivo)',
            'instructions' => 'Tom persuasivo orientado a conversao: destaque beneficios praticos, use gatilhos de decisao '
                . '(escassez, prova social, comparacao) com honestidade, e feche com uma chamada para acao (CTA) natural. '
                . 'Nao exagere nem prometa resultados que a fonte nao sustenta.',
        ],
        'tecnologia' => [
            'label'        => 'Tecnologia',
            'instructions' => 'Tom tecnico mas acessivel: explique termos tecnicos quando necessario, foque em '
                . 'especificacoes, comparacoes e implicacoes praticas para quem vai usar o produto/tecnologia.',
        ],
        'saude' => [
            'label'        => 'Saude e bem-estar',
            'instructions' => 'Tom cuidadoso e responsavel: baseie-se apenas nos fatos verificados fornecidos, evite '
                . 'alegacoes medicas categoricas ou nao verificadas, sugira consultar um profissional quando pertinente, '
                . 'mantenha um tom acolhedor.',
        ],
        'financas' => [
            'label'        => 'Financas',
            'instructions' => 'Tom claro e didatico sobre numeros e termos financeiros, cauteloso ao explicar riscos, '
                . 'evite soar como uma recomendacao de investimento definitiva, seja objetivo.',
        ],
        'lifestyle' => [
            'label'        => 'Lifestyle',
            'instructions' => 'Tom conversacional e envolvente, proximo do leitor, pode usar primeira pessoa quando fizer '
                . 'sentido, mantendo a naturalidade sem perder a clareza.',
        ],
        'tutorial' => [
            'label'        => 'Tutorial / Guia pratico',
            'instructions' => 'Tom instrucional passo a passo: seja claro e sequencial, numere etapas quando fizer sentido, '
                . 'foque em "como fazer" e nos resultados praticos de cada passo.',
        ],
        'review' => [
            'label'        => 'Analise / Review',
            'instructions' => 'Tom analitico e imparcial: pese pontos positivos e negativos com base nos fatos '
                . 'disponiveis e feche com um veredito claro.',
        ],
    ];

    private const DEFAULT_PRESET = 'geral';

    /**
     * @return array<string, string> preset key => label, for building <select> options.
     */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::PRESETS as $key => $meta) {
            $choices[$key] = $meta['label'];
        }

        return $choices;
    }

    public static function isValid(string $preset): bool
    {
        return isset(self::PRESETS[$preset]);
    }

    public static function defaultPreset(): string
    {
        return self::DEFAULT_PRESET;
    }

    /**
     * Full instruction text for a preset key. Falls back to the default
     * preset's instructions when the key is unknown (e.g. a stale/removed
     * preset stored on an old article).
     */
    public static function instructionsFor(string $preset): string
    {
        return self::PRESETS[$preset]['instructions'] ?? self::PRESETS[self::DEFAULT_PRESET]['instructions'];
    }
}
