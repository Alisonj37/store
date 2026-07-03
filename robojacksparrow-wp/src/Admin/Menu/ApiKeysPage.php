<?php

declare(strict_types=1);

namespace RoboJackSparrow\Admin\Menu;

use RoboJackSparrow\Database\Repositories\SettingRepository;

class ApiKeysPage
{
    private const NONCE_ACTION = 'rjs_api_keys';

    /**
     * @var array<string, string>
     */
    private const KEYS = [
        'rjs_tavily_api_key'    => 'Tavily (pesquisa em tempo real)',
        'rjs_kei_api_key'       => 'kei.ia (imagens - provider principal)',
        'rjs_replicate_api_key' => 'Replicate (imagens - fallback)',
        'rjs_firecrawl_api_key' => 'Firecrawl (scraper - fallback do Jina.ai)',
        'rjs_openai_api_key'    => 'OpenAI',
        'rjs_anthropic_api_key' => 'Anthropic',
        'rjs_groq_api_key'      => 'Groq',
        'rjs_gemini_api_key'    => 'Gemini',
        'rjs_deepseek_api_key'  => 'DeepSeek',
        'rjs_unsplash_api_key'  => 'Unsplash',
        'rjs_pexels_api_key'    => 'Pexels',
        'rjs_pixabay_api_key'   => 'Pixabay',
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

        foreach (self::KEYS as $key => $label) {
            $masked = $this->settings->getMasked($key);
            $html .= sprintf(
                '<p><label>%s<br><input type="password" name="%s" placeholder="%s" autocomplete="off"></label></p>',
                esc_html($label),
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

        foreach (self::KEYS as $key => $label) {
            if (!isset($_POST[$key]) || trim((string) $_POST[$key]) === '') {
                // Campo em branco = mantem a chave existente (o placeholder
                // mostra apenas o valor mascarado, nunca o valor real).
                continue;
            }

            $value = sanitize_text_field((string) $_POST[$key]);
            $this->settings->set($key, $value, 'encrypted', true, $label);
        }
    }
}
