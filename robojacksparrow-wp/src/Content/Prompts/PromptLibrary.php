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

        'article_generation' => <<<'PROMPT'
Voce e um redator de conteudo SEO senior escrevendo em {{language}}.

Tom de voz para este artigo: {{tone_instructions}}

REGRA MAIS IMPORTANTE - ORIGINALIDADE: o artigo tem que ser 100% original, escrito inteiramente com suas
proprias palavras e sua propria estrutura de frases. A fonte abaixo e APENAS uma referencia factual/tematica -
nunca copie ou parafraseie frases dela quase literalmente, nunca reutilize a mesma ordem de paragrafos ou
frases da fonte. Escreva como se estivesse relatando o mesmo assunto do zero, com seu proprio angulo.

Fonte original (somente para referencia factual, NAO copiar trechos):
Titulo: {{title}}
Conteudo: {{content}}

Pesquisa factual verificada (use para checar fatos e enriquecer o angulo, nao copie literalmente):
{{research_briefing}}

Escreva um artigo ORIGINAL de aproximadamente {{word_count}} palavras, otimizado para SEO, organizado em
secoes com subtitulos (H2 ou H3), cada uma com paragrafos <p> e listas <ul>/<ol> quando fizer sentido.
Inclua tambem de 3 a 6 perguntas frequentes (FAQ) baseadas no conteudo do artigo que voce escreveu, com
respostas curtas e diretas.

Responda APENAS com um JSON valido, sem texto adicional e sem blocos de codigo markdown, no formato exato:

{
  "title": "Titulo otimizado para SEO (ate 60 caracteres)",
  "meta_description": "Meta descricao (ate 160 caracteres)",
  "sections": [
    {"title": "Titulo da secao", "level": 2, "html": "<p>Corpo HTML da secao, sem a tag de titulo</p>"}
  ],
  "faqs": [
    {"question": "Pergunta?", "answer": "Resposta curta e direta."}
  ],
  "focus_keywords": ["palavra-chave 1", "palavra-chave 2"],
  "image_prompt": "Descricao curta em ingles para gerar a imagem de destaque"
}
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
