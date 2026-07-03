<?php

declare(strict_types=1);

namespace RoboJackSparrow\Admin\Menu;

use RoboJackSparrow\Cron\ScrapeFrequency;
use RoboJackSparrow\Database\Repositories\SettingRepository;
use RoboJackSparrow\Database\Repositories\SourceRepository;
use RoboJackSparrow\Publisher\WordPress\CategoryNameResolver;

/**
 * Pagina dedicada para ligar/desligar o piloto automatico e ver, de relance,
 * quais fontes (RSS ou URL de site) estao configuradas para alimenta-lo.
 * O CRUD completo de fontes continua na pagina Fontes (SourcesPage); aqui o
 * foco e a decisao "esta ligado ou nao e o que ele vai fazer quando gerar".
 */
class AutopilotPage
{
    private const NONCE_ACTION = 'rjs_autopilot';
    private const SOURCE_TOGGLE_NONCE_ACTION = 'rjs_autopilot_toggle_source';

    private const PUBLISH_STATUS_CHOICES = [
        'publish' => 'Publicar imediatamente',
        'draft'   => 'Salvar como rascunho',
        'future'  => 'Agendar (usa o intervalo padrao)',
    ];

    private const SOURCE_TYPE_LABELS = [
        'rss'     => 'Feed RSS',
        'scraper' => 'URL de site',
    ];

    public function __construct(
        private SettingRepository $settings,
        private SourceRepository $sources
    ) {
    }

    public function render(): string
    {
        $this->handleSubmission();

        $enabled = $this->isEnabled();
        $publishStatus = (string) $this->settings->get('rjs_autopilot_publish_status', 'draft');

        $html = '<div class="wrap"><h1>Piloto Automatico</h1>';
        $html .= $this->renderStatusBanner($enabled);
        $html .= $this->renderForm($enabled, $publishStatus);
        $html .= '<h2>Fontes configuradas</h2>';
        $html .= $this->renderSourcesSummary();
        $html .= '</div>';

        return $html;
    }

    private function isEnabled(): bool
    {
        return (string) $this->settings->get('rjs_autopilot_enabled', '0') === '1';
    }

    private function renderStatusBanner(bool $enabled): string
    {
        return $enabled
            ? '<div class="notice notice-success inline"><p><strong>Piloto automatico ligado.</strong> '
                . 'Fontes ativas (RSS ou URL de site) geram e publicam artigos automaticamente, no intervalo da fonte.</p></div>'
            : '<div class="notice notice-warning inline"><p><strong>Piloto automatico desligado.</strong> '
                . 'Novos itens encontrados nas fontes ficam pendentes e precisam ser gerados manualmente '
                . '(botao "Gerar" na pagina Artigos, ou a pagina Gerar Artigo).</p></div>';
    }

    private function renderForm(bool $enabled, string $publishStatus): string
    {
        $nonce = wp_create_nonce(self::NONCE_ACTION);
        $checked = $enabled ? ' checked' : '';

        $options = '';
        foreach (self::PUBLISH_STATUS_CHOICES as $value => $label) {
            $selected = $value === $publishStatus ? ' selected' : '';
            $options .= sprintf('<option value="%s"%s>%s</option>', esc_attr($value), $selected, esc_html($label));
        }

        return '<form method="post">'
            . '<input type="hidden" name="rjs_action" value="save_autopilot">'
            . '<input type="hidden" name="rjs_nonce" value="' . esc_attr($nonce) . '">'
            . '<p><label><input type="checkbox" name="rjs_autopilot_enabled" value="1"' . $checked . '> Habilitar piloto automatico</label></p>'
            . '<p><label>Status de publicacao ao gerar automaticamente<br><select name="rjs_autopilot_publish_status">' . $options . '</select></label></p>'
            . '<p><button type="submit" class="button button-primary">Salvar</button></p>'
            . '</form>';
    }

    private function renderSourcesSummary(): string
    {
        $sources = $this->sources->findAll();
        $sourcesUrl = admin_url('admin.php?page=robojacksparrow-sources');

        if ($sources === []) {
            return '<p>Nenhuma fonte cadastrada ainda. '
                . '<a href="' . esc_url($sourcesUrl) . '">Adicionar uma fonte (feed RSS ou URL de site)</a>.</p>';
        }

        $nonce = wp_create_nonce(self::SOURCE_TOGGLE_NONCE_ACTION);

        $html = '<table class="widefat striped"><thead><tr>'
            . '<th>Nome</th><th>Tipo</th><th>Categoria</th><th>Frequencia</th><th>Ativo</th><th>Ultima coleta</th><th>Acao</th>'
            . '</tr></thead><tbody>';

        $frequencyLabels = ScrapeFrequency::choices();

        foreach ($sources as $source) {
            $type = self::SOURCE_TYPE_LABELS[$source->source_type] ?? (string) $source->source_type;
            $sourceFrequency = (string) ($source->scrape_frequency ?? '');
            $frequency = $frequencyLabels[$sourceFrequency] ?? $sourceFrequency;
            $categoryName = CategoryNameResolver::nameFor(isset($source->category_id) ? (int) $source->category_id : null) ?? '-';
            $isActive = (int) $source->is_active === 1;

            $html .= '<tr>';
            $html .= '<td>' . esc_html((string) $source->source_name) . '</td>';
            $html .= '<td>' . esc_html($type) . '</td>';
            $html .= '<td>' . esc_html($categoryName) . '</td>';
            $html .= '<td>' . esc_html($frequency) . '</td>';
            $html .= '<td>' . ($isActive ? 'Sim' : 'Nao (pausada)') . '</td>';
            $html .= '<td>' . esc_html((string) ($source->last_scraped_at ?? 'nunca')) . '</td>';
            $html .= '<td>' . $this->renderToggleButton((int) $source->id, $isActive, $nonce) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '<p><a href="' . esc_url($sourcesUrl) . '" class="button">Gerenciar fontes</a></p>';

        return $html;
    }

    /**
     * A quick pause/resume right here too - this is often the first place
     * an admin looks when a source needs to stop immediately, not just the
     * Fontes page.
     */
    private function renderToggleButton(int $sourceId, bool $isActive, string $nonce): string
    {
        $label = $isActive ? 'Pausar' : 'Ativar';

        return '<form method="post" style="display:inline">'
            . '<input type="hidden" name="rjs_action" value="toggle_source">'
            . '<input type="hidden" name="rjs_nonce" value="' . esc_attr($nonce) . '">'
            . '<input type="hidden" name="source_id" value="' . $sourceId . '">'
            . '<button type="submit" class="button">' . esc_html($label) . '</button>'
            . '</form>';
    }

    private function handleSubmission(): void
    {
        $action = (string) ($_POST['rjs_action'] ?? '');

        if ($action === 'toggle_source') {
            $this->handleToggleSource();

            return;
        }

        if ($action !== 'save_autopilot') {
            return;
        }

        if (!current_user_can('manage_options') || !wp_verify_nonce((string) ($_POST['rjs_nonce'] ?? ''), self::NONCE_ACTION)) {
            return;
        }

        $this->settings->set('rjs_autopilot_enabled', isset($_POST['rjs_autopilot_enabled']) ? '1' : '0');

        $publishStatus = (string) ($_POST['rjs_autopilot_publish_status'] ?? 'draft');
        if (array_key_exists($publishStatus, self::PUBLISH_STATUS_CHOICES)) {
            $this->settings->set('rjs_autopilot_publish_status', $publishStatus);
        }
    }

    private function handleToggleSource(): void
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce((string) ($_POST['rjs_nonce'] ?? ''), self::SOURCE_TOGGLE_NONCE_ACTION)) {
            return;
        }

        $id = (int) ($_POST['source_id'] ?? 0);
        $source = $id > 0 ? $this->sources->find($id) : null;

        if ($source !== null) {
            $this->sources->update($id, ['is_active' => $source->is_active ? 0 : 1]);
        }
    }
}
