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
    private const NONCE_ACTION = 'rjs_dashboard';

    public function __construct(
        private ArticleRepository $articles,
        private QueueRepository $queue,
        private LogRepository $logs,
        private HealthMonitor $health
    ) {
    }

    public function render(): string
    {
        $notice = $this->handleSubmission();

        $html = '<div class="wrap"><h1>RoboJackSparrow WP</h1>';

        if ($notice !== null) {
            $html .= $notice;
        }

        $html .= '<h2>Artigos</h2>';
        $html .= $this->renderCounts($this->articles->countByStatus());
        $html .= $this->renderClearErrorsForm();

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

    private function renderClearErrorsForm(): string
    {
        $nonce = wp_create_nonce(self::NONCE_ACTION);

        return '<form method="post" style="margin:0.5em 0 1em">'
            . '<input type="hidden" name="rjs_action" value="clear_error_articles">'
            . '<input type="hidden" name="rjs_nonce" value="' . esc_attr($nonce) . '">'
            . '<button type="submit" class="button" onclick="return confirm(\'Remover todos os artigos com erro?\')">Limpar artigos com erro</button>'
            . '</form>';
    }

    private function handleSubmission(): ?string
    {
        if (($_POST['rjs_action'] ?? '') !== 'clear_error_articles') {
            return null;
        }

        if (!current_user_can('manage_options') || !wp_verify_nonce((string) ($_POST['rjs_nonce'] ?? ''), self::NONCE_ACTION)) {
            return '<div class="notice notice-error"><p>Nao foi possivel validar a solicitacao.</p></div>';
        }

        $deleted = $this->articles->deleteErrorArticles();

        return '<div class="notice notice-success"><p>' . $deleted . ' artigo(s) com erro removido(s).</p></div>';
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
