<?php

declare(strict_types=1);

namespace RoboJackSparrow\Admin\Menu;

use RoboJackSparrow\Database\Repositories\SettingRepository;

class ApiKeysPage
{
    private const NONCE_ACTION = 'rjs_api_keys';

    /**
     * @var array<string, array{label: string, url: ?string}>
     */
    private const KEYS = [
        'rjs_tavily_api_key'    => ['label' => 'Tavily (pesquisa em tempo real)', 'url' => 'https://app.tavily.com/home'],
        // kei.ia nao tem documentacao publica confirmada no momento desta
        // implementacao; sem link para nao apontar para uma URL nao verificada.
        'rjs_kei_api_key'       => ['label' => 'kei.ia (imagens - provider principal)', 'url' => null],
        'rjs_replicate_api_key' => ['label' => 'Replicate (imagens - fallback)', 'url' => 'https://replicate.com/account/api-tokens'],
        'rjs_firecrawl_api_key' => ['label' => 'Firecrawl (scraper - fallback do Jina.ai)', 'url' => 'https://www.firecrawl.dev/app/api-keys'],
        'rjs_openai_api_key'    => ['label' => 'OpenAI', 'url' => 'https://platform.openai.com/api-keys'],
        'rjs_anthropic_api_key' => ['label' => 'Anthropic', 'url' => 'https://console.anthropic.com/settings/keys'],
        'rjs_groq_api_key'      => ['label' => 'Groq', 'url' => 'https://console.groq.com/keys'],
        'rjs_gemini_api_key'    => ['label' => 'Gemini', 'url' => 'https://aistudio.google.com/apikey'],
        'rjs_deepseek_api_key'  => ['label' => 'DeepSeek', 'url' => 'https://platform.deepseek.com/api_keys'],
        'rjs_unsplash_api_key'  => ['label' => 'Unsplash', 'url' => 'https://unsplash.com/oauth/applications'],
        'rjs_pexels_api_key'    => ['label' => 'Pexels', 'url' => 'https://www.pexels.com/api/'],
        'rjs_pixabay_api_key'   => ['label' => 'Pixabay', 'url' => 'https://pixabay.com/api/docs/'],
    ];

    public function __construct(private SettingRepository $settings)
    {
    }

    public function render(): string
    {
        $this->handleSubmission();

        $html = '<div class="wrap"><h1>API Keys</h1><form method="post">';
        $html .= '<input type="hidden" name="rjs_action" value="save_api_keys">';
        $html .= '<input type="hidden" name="rjs_nonce" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '">';

        foreach (self::KEYS as $key => $meta) {
            $masked = $this->settings->getMasked($key);
            $link = $meta['url'] !== null
                ? sprintf(
                    ' <a href="%s" target="_blank" rel="noopener noreferrer">Obter chave &#8599;</a>',
                    esc_url($meta['url'])
                )
                : '';
            $html .= sprintf(
                '<p><label>%s%s<br><input type="password" name="%s" placeholder="%s" autocomplete="off"></label></p>',
                esc_html($meta['label']),
                $link,
                esc_attr($key),
                esc_attr($masked ?? 'Nao configurado')
            );
        }

        $html .= '<p><button type="submit" class="button button-primary">Salvar Chaves</button></p></form></div>';

        return $html;
    }

    private function handleSubmission(): void
    {
        if (($_POST['rjs_action'] ?? '') !== 'save_api_keys') {
            return;
        }

        if (!current_user_can('manage_options') || !wp_verify_nonce((string) ($_POST['rjs_nonce'] ?? ''), self::NONCE_ACTION)) {
            return;
        }

        foreach (self::KEYS as $key => $meta) {
            if (!isset($_POST[$key]) || trim((string) $_POST[$key]) === '') {
                // Campo em branco = mantem a chave existente (o placeholder
                // mostra apenas o valor mascarado, nunca o valor real).
                continue;
            }

            $value = sanitize_text_field((string) $_POST[$key]);
            $this->settings->set($key, $value, 'encrypted', true, $meta['label']);
        }
    }
}
