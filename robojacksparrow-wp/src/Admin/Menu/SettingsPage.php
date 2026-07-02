<?php

declare(strict_types=1);

namespace RoboJackSparrow\Admin\Menu;

use RoboJackSparrow\Database\Repositories\SettingRepository;

class SettingsPage
{
    private const NONCE_ACTION = 'rjs_settings';

    /**
     * @var array<string, array{label: string, default: string}>
     */
    private const OPTIONS = [
        'rjs_content_language'  => ['label' => 'Idioma do conteudo', 'default' => 'pt_BR'],
        'rjs_content_tone'      => ['label' => 'Tom do conteudo', 'default' => 'professional'],
        'rjs_target_word_count' => ['label' => 'Tamanho alvo (palavras)', 'default' => '1500'],
        'rjs_watermark_enabled' => ['label' => 'Marca dagua habilitada (1/0)', 'default' => '1'],
    ];

    public function __construct(private SettingRepository $settings)
    {
    }

    public function render(): string
    {
        $this->handleSubmission();

        $html = '<div class="wrap"><h1>Configuracoes</h1><form method="post">';
        $html .= '<input type="hidden" name="rjs_action" value="save_settings">';
        $html .= '<input type="hidden" name="rjs_nonce" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '">';

        foreach (self::OPTIONS as $key => $meta) {
            $value = $this->settings->get($key, $meta['default']);
            $html .= sprintf(
                '<p><label>%s<br><input type="text" name="%s" value="%s"></label></p>',
                esc_html($meta['label']),
                esc_attr($key),
                esc_attr((string) $value)
            );
        }

        $html .= '<p><button type="submit" class="button button-primary">Salvar</button></p></form></div>';

        return $html;
    }

    private function handleSubmission(): void
    {
        if (($_POST['rjs_action'] ?? '') !== 'save_settings') {
            return;
        }

        if (!current_user_can('manage_options') || !wp_verify_nonce((string) ($_POST['rjs_nonce'] ?? ''), self::NONCE_ACTION)) {
            return;
        }

        foreach (array_keys(self::OPTIONS) as $key) {
            if (!isset($_POST[$key])) {
                continue;
            }

            $value = sanitize_text_field((string) $_POST[$key]);
            $this->settings->set($key, $value);

            // Bridge: also mirror into WP's native options so existing
            // get_option() call sites (e.g. ContentEngine, Fase 5) keep
            // working without needing to depend on SettingRepository.
            update_option($key, $value);
        }
    }
}
