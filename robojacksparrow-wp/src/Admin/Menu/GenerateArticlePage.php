<?php

declare(strict_types=1);

namespace RoboJackSparrow\Admin\Menu;

use RoboJackSparrow\Content\Tone\TonePresets;
use RoboJackSparrow\Database\Repositories\ArticleRepository;
use RoboJackSparrow\Queue\QueueManager;
use RoboJackSparrow\Queue\Worker;

/**
 * "Gerar Artigo": cria um artigo a partir de uma URL informada manualmente
 * e o enfileira imediatamente no job 'scrape', entrando na mesma cadeia
 * (scrape -> generate_content -> generate_image -> publish) usada pelo RSS
 * e pela API REST - ver JobRegistry.
 */
class GenerateArticlePage
{
    private const NONCE_ACTION = 'rjs_generate_article';

    private const LLM_PROVIDERS = [
        ''          => 'Automatico',
        'openai'    => 'OpenAI',
        'anthropic' => 'Anthropic',
        'groq'      => 'Groq',
        'gemini'    => 'Gemini',
        'deepseek'  => 'DeepSeek',
    ];

    private const IMAGE_PROVIDERS = [
        ''          => 'Automatico',
        'kei_ia'    => 'kei.ia',
        'replicate' => 'Replicate',
        'unsplash'  => 'Unsplash',
        'pexels'    => 'Pexels',
        'pixabay'   => 'Pixabay',
    ];

    private const PUBLISH_MODES = [
        'publish' => 'Publicar imediatamente',
        'draft'   => 'Salvar como rascunho',
        'future'  => 'Agendar',
    ];

    /**
     * Sugestoes de modelo (via <datalist>) somadas de todos os provedores,
     * ja que a selecao de provedor acima e um <select> estatico e nao ha
     * JS nesta pagina para filtrar a lista por provedor escolhido.
     */
    private const MODEL_SUGGESTIONS = [
        'gpt-4o', 'gpt-4o-mini', 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4.1-nano', 'o3-mini', 'o1',
        'claude-3-5-sonnet-20241022', 'claude-3-5-haiku-20241022', 'claude-3-opus-20240229', 'claude-3-haiku-20240307',
        'llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'mixtral-8x7b-32768',
        'gemini-1.5-pro', 'gemini-1.5-flash', 'gemini-2.0-flash',
        'deepseek-chat', 'deepseek-reasoner',
    ];

    public function __construct(
        private ArticleRepository $articles,
        private QueueManager $queue,
        private ?Worker $worker = null
    ) {
    }

    public function render(): string
    {
        $notice = $this->handleSubmission();

        $html = '<div class="wrap"><h1>Gerar Artigo</h1>';

        if ($notice !== null) {
            $html .= $notice;
        }

        $html .= $this->renderForm();
        $html .= '</div>';

        return $html;
    }

    private function renderForm(): string
    {
        $nonce = wp_create_nonce(self::NONCE_ACTION);

        $html = '<form method="post">';
        $html .= '<input type="hidden" name="rjs_action" value="generate_article">';
        $html .= '<input type="hidden" name="rjs_nonce" value="' . esc_attr($nonce) . '">';

        $html .= '<p><label>URL da fonte<br><input type="url" name="source_url" required style="width:400px"></label></p>';
        $html .= $this->renderCategorySelect();
        $html .= $this->renderSelect('assigned_llm', 'Provedor de IA (texto)', self::LLM_PROVIDERS);
        $html .= $this->renderModelField();
        $html .= $this->renderToneSelect();
        $html .= $this->renderSelect('assigned_image_source', 'Provedor de imagem', self::IMAGE_PROVIDERS);
        $html .= $this->renderSelect('publish_mode', 'Publicacao', self::PUBLISH_MODES);
        $html .= '<p><label>Agendar para (usado somente se "Agendar" estiver selecionado)<br>';
        $html .= '<input type="datetime-local" name="scheduled_at"></label></p>';

        $html .= '<p><button type="submit" class="button button-primary">Gerar Artigo</button></p>';
        $html .= '</form>';

        return $html;
    }

    private function renderCategorySelect(): string
    {
        $categories = function_exists('get_categories') ? get_categories(['hide_empty' => false]) : [];

        $options = '<option value="">Sem categoria</option>';
        foreach ($categories as $category) {
            $options .= sprintf(
                '<option value="%d">%s</option>',
                (int) $category->term_id,
                esc_html($category->name)
            );
        }

        return sprintf(
            '<p><label>Categoria<br><select name="category_id">%s</select></label></p>',
            $options
        );
    }

    private function renderModelField(): string
    {
        $options = '';
        foreach (self::MODEL_SUGGESTIONS as $suggestion) {
            $options .= sprintf('<option value="%s">', esc_attr($suggestion));
        }

        return '<p><label>Modelo (opcional, deixe em branco para usar o padrao configurado)<br>'
            . '<input type="text" name="assigned_llm_model" list="rjs_generate_model_suggestions" placeholder="ex: gpt-4o-mini" style="width:300px">'
            . '<datalist id="rjs_generate_model_suggestions">' . $options . '</datalist>'
            . '</label></p>';
    }

    private function renderToneSelect(): string
    {
        return $this->renderSelect('assigned_tone', 'Tom de voz (por nicho)', ['' => 'Usar o padrao do site'] + TonePresets::choices());
    }

    /**
     * @param array<string, string> $choices
     */
    private function renderSelect(string $name, string $label, array $choices): string
    {
        $options = '';
        foreach ($choices as $value => $choiceLabel) {
            $options .= sprintf('<option value="%s">%s</option>', esc_attr($value), esc_html($choiceLabel));
        }

        return sprintf(
            '<p><label>%s<br><select name="%s">%s</select></label></p>',
            esc_html($label),
            esc_attr($name),
            $options
        );
    }

    private function handleSubmission(): ?string
    {
        if (($_POST['rjs_action'] ?? '') !== 'generate_article') {
            return null;
        }

        if (!current_user_can('manage_options') || !wp_verify_nonce((string) ($_POST['rjs_nonce'] ?? ''), self::NONCE_ACTION)) {
            return '<div class="notice notice-error"><p>Nao foi possivel validar a solicitacao.</p></div>';
        }

        $url = esc_url_raw((string) ($_POST['source_url'] ?? ''));
        if ($url === '') {
            return '<div class="notice notice-error"><p>Informe uma URL valida.</p></div>';
        }

        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $categoryName = $categoryId > 0 && function_exists('get_term')
            ? $this->categoryName($categoryId)
            : null;

        $assignedLlm = $this->pickChoice((string) ($_POST['assigned_llm'] ?? ''), self::LLM_PROVIDERS);
        $assignedLlmModel = trim(sanitize_text_field((string) ($_POST['assigned_llm_model'] ?? '')));
        $assignedTone = (string) ($_POST['assigned_tone'] ?? '');
        $assignedTone = TonePresets::isValid($assignedTone) ? $assignedTone : '';
        $assignedImage = $this->pickChoice((string) ($_POST['assigned_image_source'] ?? ''), self::IMAGE_PROVIDERS);
        $publishMode = $this->pickChoice((string) ($_POST['publish_mode'] ?? 'publish'), self::PUBLISH_MODES, 'publish');

        $scheduledAt = null;
        if ($publishMode === 'future') {
            $scheduledAt = $this->parseScheduledAt((string) ($_POST['scheduled_at'] ?? ''));
            if ($scheduledAt === null) {
                return '<div class="notice notice-error"><p>Informe uma data/hora valida para agendar.</p></div>';
            }
        }

        $articleId = $this->articles->create([
            'source_type'          => 'manual',
            'source_url'           => $url,
            'status'               => 'pending',
            'category_id'          => $categoryId > 0 ? $categoryId : null,
            'category_name'        => $categoryName,
            'assigned_llm'         => $assignedLlm === '' ? 'auto' : $assignedLlm,
            'assigned_llm_model'   => $assignedLlmModel !== '' ? $assignedLlmModel : null,
            'assigned_tone'        => $assignedTone !== '' ? $assignedTone : null,
            'assigned_image_source' => $assignedImage === '' ? 'auto' : $assignedImage,
            'target_post_status'   => $publishMode,
            'scheduled_at'         => $scheduledAt,
            'priority'             => 5,
        ]);

        $this->queue->enqueue($articleId, 'scrape');
        $this->processSynchronously($articleId);

        return $this->buildResultNotice($articleId);
    }

    /**
     * Shared hosting has no background worker process - jobs are normally
     * only picked up by the next WP-Cron tick (up to a minute later, and
     * only if something else happens to trigger WP-Cron). That makes a
     * manual "Gerar Artigo" submission look like nothing happened. Since a
     * freshly enqueued job is immediately available, draining the queue
     * synchronously here lets the admin see start-to-finish progress (and
     * the resulting edit/view links) in the same page load instead.
     */
    private function processSynchronously(int $articleId): void
    {
        if ($this->worker === null) {
            return;
        }

        for ($i = 0; $i < 3; $i++) {
            $this->worker->processNextBatch();

            $article = $this->articles->find($articleId);
            if ($article !== null && in_array($article->status, ['published', 'error'], true)) {
                break;
            }
        }
    }

    private function buildResultNotice(int $articleId): string
    {
        $article = $this->articles->find($articleId);
        if ($article === null) {
            return '<div class="notice notice-error"><p>Artigo nao encontrado apos o processamento.</p></div>';
        }

        if ($article->status === 'published') {
            $editUrl = get_edit_post_link((int) $article->wordpress_post_id);
            $viewUrl = (string) ($article->wordpress_post_url ?? '');

            return '<div class="notice notice-success"><p>Artigo #' . $articleId . ' gerado e publicado com sucesso.</p>'
                . '<p><a href="' . esc_url((string) $editUrl) . '" class="button button-primary">Editar</a> '
                . '<a href="' . esc_url($viewUrl) . '" class="button" target="_blank" rel="noopener noreferrer">Ver artigo</a></p></div>';
        }

        if ($article->status === 'error') {
            return '<div class="notice notice-error"><p>Falha ao gerar o artigo #' . $articleId . ': '
                . esc_html((string) ($article->error_message ?? 'erro desconhecido')) . '</p></div>';
        }

        return '<div class="notice notice-warning"><p>Artigo #' . $articleId . ' criado e enviado para a fila (status atual: '
            . esc_html((string) $article->status) . '). A geracao pode levar alguns minutos - atualize esta pagina '
            . 'ou acompanhe na aba <strong>Artigos</strong>.</p></div>';
    }

    /**
     * @param array<string, string> $choices
     */
    private function pickChoice(string $value, array $choices, string $default = ''): string
    {
        return array_key_exists($value, $choices) ? $value : $default;
    }

    private function categoryName(int $categoryId): ?string
    {
        $term = get_term($categoryId, 'category');

        return ($term !== null && !is_wp_error($term)) ? (string) $term->name : null;
    }

    private function parseScheduledAt(string $value): ?string
    {
        if (trim($value) === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

        return $parsed !== false ? $parsed->format('Y-m-d H:i:s') : null;
    }
}
