<?php

declare(strict_types=1);

namespace RoboJackSparrow\Admin\Menu;

use RoboJackSparrow\Admin\View;
use RoboJackSparrow\Database\Repositories\LogRepository;

class LogsPage
{
    private const PER_PAGE = 50;

    public function __construct(private LogRepository $logs)
    {
    }

    public function render(): string
    {
        $level = isset($_GET['level']) ? sanitize_text_field((string) $_GET['level']) : '';
        $level = $level !== '' ? $level : null;

        $rows = $this->logs->findRecent(self::PER_PAGE, $level);

        $html = '<div class="wrap"><h1>Logs</h1>';
        $html .= View::table($rows, [
            'created_at' => 'Data',
            'level'      => 'Nivel',
            'source'     => 'Origem',
            'message'    => 'Mensagem',
        ]);
        $html .= '</div>';

        return $html;
    }
}
