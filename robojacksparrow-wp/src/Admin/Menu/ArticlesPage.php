<?php

declare(strict_types=1);

namespace RoboJackSparrow\Admin\Menu;

use RoboJackSparrow\Database\Repositories\ArticleRepository;
use RoboJackSparrow\Queue\QueueManager;
use RoboJackSparrow\Queue\Worker;

class ArticlesPage
{
    private const PER_PAGE = 20;
    private const NONCE_ACTION = 'rjs_articles';

    public function __construct(
        private ArticleRepository $articles,
        private ?QueueManager $queue = null,
        private ?Worker $worker = null
    ) {
    }

    public function render(): string
    {
        $notice = $this->handleSubmission();

        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;
        $status = $this->currentStatusFilter();

        $rows = $this->articles->findRecent(self::PER_PAGE, $offset, $status);
        $total = $this->articles->count($status);

        $html = '<div class="wrap"><h1>Artigos</h1>';

        if ($notice !== null) {
            $html .= $notice;
        }

        $html .= sprintf('<p>%d artigo(s) no total.</p>', $total);
        $html .= $this->renderTable($rows);
        $html .= '</div>';

        return $html;
    }

    /**
     * @param object[] $rows
     */
    private function renderTable(array $rows): string
    {
        if ($rows === []) {
            return '<p>Nenhum registro encontrado.</p>';
        }

        $nonce = wp_create_nonce(self::NONCE_ACTION);

        $html = '<table class="widefat striped"><thead><tr>';
        $html .= '<th>ID</th><th>Fonte</th><th>Status</th><th>Titulo</th><th>Criado em</th><th>Acoes</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            $html .= '<td>' . esc_html((string) $row->id) . '</td>';
            $html .= '<td>' . esc_html((string) $row->source_type) . '</td>';
            $html .= '<td>' . esc_html((string) $row->status) . '</td>';
            $html .= '<td>' . esc_html((string) ($row->source_title ?? '')) . '</td>';
            $html .= '<td>' . esc_html((string) $row->created_at) . '</td>';
            $html .= '<td>' . $this->renderRowActions($row, $nonce) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    private function renderRowActions(object $row, string $nonce): string
    {
        if ((string) $row->status === 'pending') {
            return '<form method="post" style="display:inline">'
                . '<input type="hidden" name="rjs_action" value="generate_now">'
                . '<input type="hidden" name="rjs_nonce" value="' . esc_attr($nonce) . '">'
                . '<input type="hidden" name="article_id" value="' . (int) $row->id . '">'
                . '<button type="submit" class="button">Gerar</button>'
                . '</form>';
        }

        if ((string) $row->status === 'published' && !empty($row->wordpress_post_id)) {
            $editUrl = get_edit_post_link((int) $row->wordpress_post_id);
            $viewUrl = (string) ($row->wordpress_post_url ?? '');

            return '<a href="' . esc_url((string) $editUrl) . '" class="button">Editar</a> '
                . '<a href="' . esc_url($viewUrl) . '" class="button" target="_blank" rel="noopener noreferrer">Ver</a>';
        }

        return '';
    }

    private function handleSubmission(): ?string
    {
        if (($_POST['rjs_action'] ?? '') !== 'generate_now') {
            return null;
        }

        if (!current_user_can('manage_options') || !wp_verify_nonce((string) ($_POST['rjs_nonce'] ?? ''), self::NONCE_ACTION)) {
            return '<div class="notice notice-error"><p>Nao foi possivel validar a solicitacao.</p></div>';
        }

        $articleId = (int) ($_POST['article_id'] ?? 0);
        $article = $articleId > 0 ? $this->articles->find($articleId) : null;

        if ($article === null || $article->status !== 'pending' || $this->queue === null) {
            return '<div class="notice notice-error"><p>Artigo nao encontrado ou ja processado.</p></div>';
        }

        $this->queue->enqueue($articleId, 'scrape');
        $this->processSynchronously($articleId);

        return $this->buildResultNotice($articleId);
    }

    /**
     * See GenerateArticlePage::processSynchronously() for why this runs
     * inline instead of waiting for the next passive WP-Cron tick.
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

        return '<div class="notice notice-warning"><p>Artigo #' . $articleId . ' enviado para a fila (status atual: '
            . esc_html((string) $article->status) . '). Atualize esta pagina em alguns instantes para ver o resultado. '
            . 'Se continuar assim apos algumas atualizacoes, veja a coluna <strong>Detalhes</strong> na pagina '
            . '<strong>Logs</strong> (filtre por Post ID ' . $articleId . ') para o motivo exato da falha.</p></div>';
    }

    private function currentStatusFilter(): ?string
    {
        $status = isset($_GET['status']) ? sanitize_text_field((string) $_GET['status']) : '';

        return $status !== '' ? $status : null;
    }
}
