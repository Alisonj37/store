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

REGRA DE TAMANHO - OBRIGATORIA: o artigo tem que ter NO MINIMO {{min_word_count}} e NO MAXIMO {{max_word_count}}
palavras no total, somando todas as secoes (nao conte o FAQ nesse total). Isso nao e uma sugestao aproximada -
NUNCA entregue um artigo com menos de {{min_word_count}} palavras. Mire em aproximadamente {{target_word_count}}
palavras dentro desse intervalo. Se o assunto parecer curto, aprofunde com mais contexto, exemplos, implicacoes
praticas e detalhes relevantes ate atingir o minimo exigido, escreva secoes adicionais (H2/H3) se for preciso
para atingir o tamanho.

Escreva um artigo ORIGINAL, otimizado para SEO, organizado em secoes com subtitulos (H2 ou H3), cada uma com
paragrafos <p> e listas <ul>/<ol> quando fizer sentido. Inclua tambem de 3 a 6 perguntas frequentes (FAQ)
baseadas no conteudo do artigo que voce escreveu, com respostas curtas e diretas.

REGRA DE SEO - PALAVRA-CHAVE DE FOCO: escolha uma unica palavra-chave de foco principal para o artigo (a mais
relevante para busca) e garanta que ela apareca literalmente: no titulo (title), na meta descricao
(meta_description), no primeiro paragrafo do artigo, e em pelo menos um subtitulo (H2). Coloque essa
palavra-chave de foco como o PRIMEIRO item da lista focus_keywords.

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
