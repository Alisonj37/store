<?php

declare(strict_types=1);

namespace RoboJackSparrow\Admin\Menu;

use RoboJackSparrow\Admin\View;
use RoboJackSparrow\Database\Repositories\QueueRepository;

class QueuePage
{
    private const PER_PAGE = 20;

    public function __construct(private QueueRepository $queue)
    {
    }

    public function render(): string
    {
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;
        $status = $this->currentStatusFilter();

        $rows = $this->queue->findRecent(self::PER_PAGE, $offset, $status);
        $total = $this->queue->count($status);

        $html = '<div class="wrap"><h1>Fila</h1>';
        $html .= sprintf('<p>%d job(s) no total.</p>', $total);
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

    private function currentStatusFilter(): ?string
    {
        $status = isset($_GET['status']) ? sanitize_text_field((string) $_GET['status']) : '';

        return $status !== '' ? $status : null;
    }
}
