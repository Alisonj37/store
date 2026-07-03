<?php

declare(strict_types=1);

namespace RoboJackSparrow\Admin\Menu;

use RoboJackSparrow\Admin\View;
use RoboJackSparrow\Database\Repositories\QueueRepository;

class QueuePage
{
    private const PER_PAGE = 20;
    private const NONCE_ACTION = 'rjs_queue';

    public function __construct(private QueueRepository $queue)
    {
    }

    public function render(): string
    {
        $notice = $this->handleSubmission();

        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;
        $status = $this->currentStatusFilter();

        $rows = $this->queue->findRecent(self::PER_PAGE, $offset, $status);
        $total = $this->queue->count($status);

        $html = '<div class="wrap"><h1>Fila</h1>';

        if ($notice !== null) {
            $html .= $notice;
        }

        $html .= sprintf('<p>%d job(s) no total.</p>', $total);
        $html .= $this->renderClearForm();
        $html .= View::table($rows, [
            'id'          => 'ID',
            'article_id'  => 'Artigo',
            'job_type'    => 'Tipo',
            'status'      => 'Status',
            'attempts'    => 'Tentativas',
            'available_at' => 'Disponivel em',
        ]);
        $html .= '</div>';

        return $html;
    }

    private function renderClearForm(): string
    {
        $nonce = wp_create_nonce(self::NONCE_ACTION);

        return '<form method="post" style="margin-bottom:1em">'
            . '<input type="hidden" name="rjs_action" value="clear_finished_jobs">'
            . '<input type="hidden" name="rjs_nonce" value="' . esc_attr($nonce) . '">'
            . '<button type="submit" class="button" onclick="return confirm(\'Remover todos os jobs concluidos e com falha da fila?\')">Limpar fila (concluidos e com falha)</button>'
            . '</form>';
    }

    private function handleSubmission(): ?string
    {
        if (($_POST['rjs_action'] ?? '') !== 'clear_finished_jobs') {
            return null;
        }

        if (!current_user_can('manage_options') || !wp_verify_nonce((string) ($_POST['rjs_nonce'] ?? ''), self::NONCE_ACTION)) {
            return '<div class="notice notice-error"><p>Nao foi possivel validar a solicitacao.</p></div>';
        }

        $deleted = $this->queue->deleteCompletedAndFailed();

        return '<div class="notice notice-success"><p>' . $deleted . ' job(s) removido(s) da fila.</p></div>';
    }

    private function currentStatusFilter(): ?string
    {
        $status = isset($_GET['status']) ? sanitize_text_field((string) $_GET['status']) : '';

        return $status !== '' ? $status : null;
    }
}
