<?php

declare(strict_types=1);

namespace RoboJackSparrow\Admin\Menu;

use RoboJackSparrow\Admin\View;
use RoboJackSparrow\Database\Repositories\ArticleRepository;

class ArticlesPage
{
    private const PER_PAGE = 20;

    public function __construct(private ArticleRepository $articles)
    {
    }

    public function render(): string
    {
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;
        $status = $this->currentStatusFilter();

        $rows = $this->articles->findRecent(self::PER_PAGE, $offset, $status);
        $total = $this->articles->count($status);

        $html = '<div class="wrap"><h1>Artigos</h1>';
        $html .= sprintf('<p>%d artigo(s) no total.</p>', $total);
        $html .= View::table($rows, [
            'id'           => 'ID',
            'source_type'  => 'Fonte',
            'status'       => 'Status',
            'source_title' => 'Titulo',
            'created_at'   => 'Criado em',
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
