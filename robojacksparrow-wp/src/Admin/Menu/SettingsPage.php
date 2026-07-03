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
    private const TEXT_OPTIONS = [
        'rjs_content_language'  => ['label' => 'Idioma do conteudo', 'default' => 'pt_BR'],
        'rjs_content_tone'      => ['label' => 'Tom do conteudo', 'default' => 'professional'],
        'rjs_target_word_count' => ['label' => 'Tamanho alvo (palavras)', 'default' => '1500'],
    ];

    private const CHECKBOX_OPTIONS = [
        'rjs_watermark_enabled'  => ['label' => 'Marca dagua habilitada', 'default' => '1'],
        'rjs_autopilot_enabled'  => ['label' => 'Piloto automatico habilitado (RSS gera e publica artigos sozinho)', 'default' => '0'],
    ];

    /**
     * @var array<string, array{label: string, default: string, choices: array<string, string>}>
     */
    private const SELECT_OPTIONS = [
        'rjs_autopilot_publish_status' => [
            'label'   => 'Status de publicacao no piloto automatico',
            'default' => 'draft',
            'choices' => [
                'publish' => 'Publicar imediatamente',
                'draft'   => 'Salvar como rascunho',
                'future'  => 'Agendar (usa o intervalo padrao)',
            ],
        ],
        'rjs_preferred_llm_provider' => [
            'label'   => 'Provedor de IA (texto) preferido',
            'default' => '',
            'choices' => [
                ''          => 'Automatico (melhor disponibilidade)',
                'openai'    => 'OpenAI',
                'anthropic' => 'Anthropic',
                'groq'      => 'Groq',
                'gemini'    => 'Gemini',
                'deepseek'  => 'DeepSeek',
            ],
        ],
        'rjs_preferred_image_provider' => [
            'label'   => 'Provedor de imagem preferido',
            'default' => '',
            'choices' => [
                ''         => 'Automatico (cascata padrao)',
                'kei_ia'   => 'kei.ia',
                'replicate' => 'Replicate',
                'unsplash' => 'Unsplash',
                'pexels'   => 'Pexels',
                'pixabay'  => 'Pixabay',
            ],
        ],
    ];

    /**
     * Campos de texto livre (nao um dropdown fechado): os nomes de modelo
     * dos provedores mudam com frequencia e nem sempre e possivel confirmar
     * o identificador exato de um modelo novo, entao o admin digita o
     * identificador que a API do provedor espera (ex.: "gpt-4o-mini").
     *
     * @var array<string, array{label: string, default: string}>
     */
    private const MODEL_OPTIONS = [
        'rjs_openai_model'    => ['label' => 'Modelo OpenAI', 'default' => ''],
        'rjs_anthropic_model' => ['label' => 'Modelo Anthropic', 'default' => ''],
        'rjs_groq_model'      => ['label' => 'Modelo Groq', 'default' => ''],
        'rjs_gemini_model'    => ['label' => 'Modelo Gemini', 'default' => ''],
        'rjs_deepseek_model'  => ['label' => 'Modelo DeepSeek', 'default' => ''],
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

        $html .= '<h2>Conteudo</h2>';
        foreach (self::TEXT_OPTIONS as $key => $meta) {
            $html .= $this->renderText($key, $meta);
        }

        $html .= '<h2>Piloto Automatico</h2>';
        $html .= $this->renderCheckbox('rjs_autopilot_enabled', self::CHECKBOX_OPTIONS['rjs_autopilot_enabled']);
        $html .= $this->renderSelect('rjs_autopilot_publish_status', self::SELECT_OPTIONS['rjs_autopilot_publish_status']);
        $html .= '<p class="description">Quando desabilitado, novos itens de fontes RSS ficam pendentes e precisam ser gerados manualmente na pagina Artigos.</p>';

        $html .= '<h2>Provedores Preferidos</h2>';
        $html .= $this->renderSelect('rjs_preferred_llm_provider', self::SELECT_OPTIONS['rjs_preferred_llm_provider']);
        $html .= $this->renderSelect('rjs_preferred_image_provider', self::SELECT_OPTIONS['rjs_preferred_image_provider']);
        $html .= $this->renderCheckbox('rjs_watermark_enabled', self::CHECKBOX_OPTIONS['rjs_watermark_enabled']);

        $html .= '<h2>Modelos por Provedor</h2>';
        $html .= '<p class="description">Deixe em branco para usar o modelo padrao de cada provedor.</p>';
        foreach (self::MODEL_OPTIONS as $key => $meta) {
            $html .= $this->renderText($key, $meta);
        }

        $html .= '<p><button type="submit" class="button button-primary">Salvar</button></p></form></div>';

        return $html;
    }

    /**
     * @param array{label: string, default: string} $meta
     */
    private function renderText(string $key, array $meta): string
    {
        $value = $this->settings->get($key, $meta['default']);

        return sprintf(
            '<p><label>%s<br><input type="text" name="%s" value="%s"></label></p>',
            esc_html($meta['label']),
            esc_attr($key),
            esc_attr((string) $value)
        );
    }

    /**
     * @param array{label: string, default: string} $meta
     */
    private function renderCheckbox(string $key, array $meta): string
    {
        $value = (string) $this->settings->get($key, $meta['default']);
        $checked = $value === '1' ? ' checked' : '';

        return sprintf(
            '<p><label><input type="checkbox" name="%s" value="1"%s> %s</label></p>',
            esc_attr($key),
            $checked,
            esc_html($meta['label'])
        );
    }

    /**
     * @param array{label: string, default: string, choices: array<string, string>} $meta
     */
    private function renderSelect(string $key, array $meta): string
    {
        $value = (string) $this->settings->get($key, $meta['default']);

        $options = '';
        foreach ($meta['choices'] as $choiceValue => $choiceLabel) {
            $selected = $choiceValue === $value ? ' selected' : '';
            $options .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr($choiceValue),
                $selected,
                esc_html($choiceLabel)
            );
        }

        return sprintf(
            '<p><label>%s<br><select name="%s">%s</select></label></p>',
            esc_html($meta['label']),
            esc_attr($key),
            $options
        );
    }

    private function handleSubmission(): void
    {
        if (($_POST['rjs_action'] ?? '') !== 'save_settings') {
            return;
        }

        if (!current_user_can('manage_options') || !wp_verify_nonce((string) ($_POST['rjs_nonce'] ?? ''), self::NONCE_ACTION)) {
            return;
        }

        foreach (array_keys(self::TEXT_OPTIONS) as $key) {
            if (!isset($_POST[$key])) {
                continue;
            }

            $this->settings->set($key, sanitize_text_field((string) $_POST[$key]));
        }

        foreach (array_keys(self::MODEL_OPTIONS) as $key) {
            if (!isset($_POST[$key])) {
                continue;
            }

            $this->settings->set($key, sanitize_text_field((string) $_POST[$key]));
        }

        foreach (array_keys(self::CHECKBOX_OPTIONS) as $key) {
            // Unchecked checkboxes are simply absent from $_POST.
            $this->settings->set($key, isset($_POST[$key]) ? '1' : '0');
        }

        foreach (self::SELECT_OPTIONS as $key => $meta) {
            if (!isset($_POST[$key]) || !array_key_exists((string) $_POST[$key], $meta['choices'])) {
                continue;
            }

            $this->settings->set($key, sanitize_text_field((string) $_POST[$key]));
        }
    }
}
