<?php

declare(strict_types=1);

namespace RoboJackSparrow\Admin\Menu;

use RoboJackSparrow\Content\Tone\TonePresets;
use RoboJackSparrow\Database\Repositories\SettingRepository;

class SettingsPage
{
    private const NONCE_ACTION = 'rjs_settings';

    /**
     * @var array<string, array{label: string, default: string}>
     */
    private const TEXT_OPTIONS = [
        'rjs_content_language'  => ['label' => 'Idioma do conteudo', 'default' => 'pt_BR'],
        'rjs_target_word_count' => ['label' => 'Tamanho alvo (palavras)', 'default' => '1500'],
    ];

    private const CHECKBOX_OPTIONS = [
        'rjs_watermark_enabled'  => ['label' => 'Marca dagua habilitada', 'default' => '1'],
    ];

    /**
     * @var array<string, array{label: string, default: string, choices: array<string, string>}>
     */
    private const SELECT_OPTIONS = [
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
     * Campo com sugestoes (via <datalist>, sem dropdown fechado): os nomes
     * de modelo dos provedores mudam com frequencia demais para travar em
     * uma lista fixa, entao o admin pode escolher uma sugestao conhecida OU
     * digitar qualquer identificador que a API do provedor aceite.
     *
     * @var array<string, array{label: string, default: string, suggestions: string[]}>
     */
    private const MODEL_OPTIONS = [
        'rjs_openai_model'    => [
            'label'       => 'Modelo OpenAI',
            'default'     => '',
            'suggestions' => ['gpt-4o', 'gpt-4o-mini', 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4.1-nano', 'o3-mini', 'o1'],
        ],
        'rjs_anthropic_model' => [
            'label'       => 'Modelo Anthropic',
            'default'     => '',
            'suggestions' => ['claude-3-5-sonnet-20241022', 'claude-3-5-haiku-20241022', 'claude-3-opus-20240229', 'claude-3-haiku-20240307'],
        ],
        'rjs_groq_model'      => [
            'label'       => 'Modelo Groq',
            'default'     => '',
            'suggestions' => ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'mixtral-8x7b-32768', 'gemma2-9b-it'],
        ],
        'rjs_gemini_model'    => [
            'label'       => 'Modelo Gemini',
            'default'     => '',
            'suggestions' => ['gemini-1.5-pro', 'gemini-1.5-flash', 'gemini-1.5-flash-8b', 'gemini-2.0-flash'],
        ],
        'rjs_deepseek_model'  => [
            'label'       => 'Modelo DeepSeek',
            'default'     => '',
            'suggestions' => ['deepseek-chat', 'deepseek-reasoner'],
        ],
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
        $html .= $this->renderToneSelect();

        $html .= '<h2>Provedores Preferidos</h2>';
        $html .= $this->renderSelect('rjs_preferred_llm_provider', self::SELECT_OPTIONS['rjs_preferred_llm_provider']);
        $html .= $this->renderSelect('rjs_preferred_image_provider', self::SELECT_OPTIONS['rjs_preferred_image_provider']);
        $html .= $this->renderCheckbox('rjs_watermark_enabled', self::CHECKBOX_OPTIONS['rjs_watermark_enabled']);

        $html .= '<h2>Modelos por Provedor</h2>';
        $html .= '<p class="description">Escolha uma sugestao ou digite o identificador do modelo. Deixe em branco para usar o padrao de cada provedor.</p>';
        foreach (self::MODEL_OPTIONS as $key => $meta) {
            $html .= $this->renderModelField($key, $meta);
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
     * Tom de voz padrao do site, escolhido entre presets de nicho (afiliado,
     * noticia, tecnologia, etc.) - ver TonePresets. Cada artigo pode
     * sobrepor isso individualmente na pagina Gerar Artigo.
     */
    private function renderToneSelect(): string
    {
        $value = (string) $this->settings->get('rjs_content_tone', TonePresets::defaultPreset());

        $options = '';
        foreach (TonePresets::choices() as $choiceValue => $choiceLabel) {
            $selected = $choiceValue === $value ? ' selected' : '';
            $options .= sprintf('<option value="%s"%s>%s</option>', esc_attr($choiceValue), $selected, esc_html($choiceLabel));
        }

        return sprintf(
            '<p><label>Tom de voz padrao (por nicho)<br><select name="rjs_content_tone">%s</select></label></p>',
            $options
        );
    }

    /**
     * @param array{label: string, default: string, suggestions: string[]} $meta
     */
    private function renderModelField(string $key, array $meta): string
    {
        $value = $this->settings->get($key, $meta['default']);
        $listId = $key . '_suggestions';

        $options = '';
        foreach ($meta['suggestions'] as $suggestion) {
            $options .= sprintf('<option value="%s">', esc_attr($suggestion));
        }

        return sprintf(
            '<p><label>%s<br><input type="text" name="%s" value="%s" list="%s" placeholder="ex: %s"><datalist id="%s">%s</datalist></label></p>',
            esc_html($meta['label']),
            esc_attr($key),
            esc_attr((string) $value),
            esc_attr($listId),
            esc_attr($meta['suggestions'][0] ?? ''),
            esc_attr($listId),
            $options
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

        $tone = (string) ($_POST['rjs_content_tone'] ?? '');
        if (TonePresets::isValid($tone)) {
            $this->settings->set('rjs_content_tone', $tone);
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
