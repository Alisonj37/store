<?php

declare(strict_types=1);

namespace RoboJackSparrow\Content\Prompts;

use InvalidArgumentException;

/**
 * Centralized prompt templates for the Content Engine pipeline. Templates
 * use {{placeholder}} tokens filled in by get().
 */
final class PromptLibrary
{
    private const TEMPLATES = [

        'outline_generation' => <<<'PROMPT'
Voce e um editor de conteudo SEO senior escrevendo em {{language}}, com tom {{tone}}.

Fonte original:
Titulo: {{title}}
Conteudo: {{content}}

Pesquisa factual verificada (use para checar fatos e enriquecer o angulo, nao copie literalmente):
{{research_briefing}}

Crie a estrutura de um artigo original de aproximadamente {{word_count}} palavras, otimizado para SEO,
baseado na fonte acima e reforcado pelos fatos verificados. Responda APENAS com um JSON valido, sem
texto adicional e sem blocos de codigo markdown, no formato exato:

{
  "title": "Titulo otimizado para SEO (ate 60 caracteres)",
  "meta_description": "Meta descricao (ate 160 caracteres)",
  "sections": [
    {"title": "Titulo da secao", "level": 2}
  ],
  "focus_keywords": ["palavra-chave 1", "palavra-chave 2"],
  "image_prompt": "Descricao curta em ingles para gerar a imagem de destaque"
}
PROMPT,

        'section_generation' => <<<'PROMPT'
Voce esta escrevendo a secao {{section_index}} de {{total_sections}} de um artigo.

Estrutura completa do artigo:
{{outline_context}}

Secao atual a escrever: "{{section_title}}" (nivel H{{section_level}})

Conteudo da fonte original (base factual):
{{source_content}}

Pesquisa factual verificada (fatos e fontes adicionais):
{{research_briefing}}

Escreva o corpo HTML desta secao (paragrafos <p>, listas <ul>/<ol> quando fizer sentido).
NAO inclua a tag de titulo (<h{{section_level}}>) - ela sera adicionada automaticamente.
NAO repita conteudo ja coberto nas secoes anteriores (veja o historico de contexto).
Responda apenas com o HTML da secao, sem comentarios ou explicacoes adicionais.
PROMPT,

        'faq_extraction' => <<<'PROMPT'
Com base no artigo abaixo, extraia de 3 a 6 perguntas frequentes (FAQ) que um leitor faria,
com respostas curtas e diretas baseadas apenas no conteudo do artigo.

Artigo:
{{content}}

Responda APENAS com um JSON valido, sem texto adicional e sem blocos de codigo markdown, no formato:

[
  {"question": "Pergunta?", "answer": "Resposta curta e direta."}
]
PROMPT,

    ];

    public function get(string $key, array $vars = []): string
    {
        if (!isset(self::TEMPLATES[$key])) {
            throw new InvalidArgumentException("Unknown prompt template: {$key}");
        }

        return $this->render(self::TEMPLATES[$key], $vars);
    }

    private function render(string $template, array $vars): string
    {
        $replacements = [];

        foreach ($vars as $name => $value) {
            $replacements['{{' . $name . '}}'] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return strtr($template, $replacements);
    }
}
