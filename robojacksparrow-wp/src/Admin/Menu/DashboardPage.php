<?php

declare(strict_types=1);

namespace RoboJackSparrow\Admin\Menu;

use RoboJackSparrow\Admin\View;
use RoboJackSparrow\Ai\HealthMonitor;
use RoboJackSparrow\Database\Repositories\ArticleRepository;
use RoboJackSparrow\Database\Repositories\LogRepository;
use RoboJackSparrow\Database\Repositories\QueueRepository;

class DashboardPage
{
    private const LLM_PROVIDERS = ['openai', 'anthropic', 'groq', 'gemini', 'deepseek'];

    public function __construct(
        private ArticleRepository $articles,
        private QueueRepository $queue,
        private LogRepository $logs,
        private HealthMonitor $health
    ) {
    }

    public function render(): string
    {
        $html = '<div class="wrap"><h1>RoboJackSparrow WP</h1>';

        $html .= '<h2>Artigos</h2>';
        $html .= $this->renderCounts($this->articles->countByStatus());

        $html .= '<h2>Fila</h2>';
        $html .= $this->renderCounts($this->queue->countByStatus());

        $html .= '<h2>Saude dos Provedores de IA</h2>';
        $html .= $this->renderHealth();

        $html .= '<h2>Logs Recentes</h2>';
        $html .= View::table($this->logs->findRecent(5), [
            'created_at' => 'Data',
            'level'      => 'Nivel',
            'source'     => 'Origem',
            'message'    => 'Mensagem',
        ]);

        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<string, int> $counts
     */
    private function renderCounts(array $counts): string
    {
        if ($counts === []) {
            return '<p>Nenhum registro ainda.</p>';
        }

        $html = '<ul class="rjs-stat-list">';
        foreach ($counts as $status => $total) {
            $html .= sprintf('<li>%s: <strong>%d</strong></li>', esc_html((string) $status), $total);
        }
        $html .= '</ul>';

        return $html;
    }

    private function renderHealth(): string
    {
        $html = '<ul class="rjs-stat-list">';

        foreach (self::LLM_PROVIDERS as $provider) {
            $status = $this->health->getStatus($provider);
            $html .= sprintf(
                '<li>%s: %s (%dms, %s%% sucesso)</li>',
                esc_html($provider),
                View::statusBadge($status->getStatus()),
                $status->getAvgLatencyMs(),
                esc_html(number_format($status->getSuccessRate24h(), 1))
            );
        }

        $html .= '</ul>';

        return $html;
    }
}
