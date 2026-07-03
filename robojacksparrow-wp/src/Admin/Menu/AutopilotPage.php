<?php

declare(strict_types=1);

namespace RoboJackSparrow\Admin\Menu;

use RoboJackSparrow\Database\Repositories\SettingRepository;
use RoboJackSparrow\Database\Repositories\SourceRepository;

/**
 * Pagina dedicada para ligar/desligar o piloto automatico e ver, de relance,
 * quais fontes (RSS ou URL de site) estao configuradas para alimenta-lo.
 * O CRUD completo de fontes continua na pagina Fontes (SourcesPage); aqui o
 * foco e a decisao "esta ligado ou nao e o que ele vai fazer quando gerar".
 */
class AutopilotPage
{
    private const NONCE_ACTION = 'rjs_autopilot';

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

        $html = '<table class="widefat striped"><thead><tr>'
            . '<th>Nome</th><th>Tipo</th><th>Ativo</th><th>Ultima coleta</th>'
            . '</tr></thead><tbody>';

        foreach ($sources as $source) {
            $type = self::SOURCE_TYPE_LABELS[$source->source_type] ?? (string) $source->source_type;

            $html .= '<tr>';
            $html .= '<td>' . esc_html((string) $source->source_name) . '</td>';
            $html .= '<td>' . esc_html($type) . '</td>';
            $html .= '<td>' . ((int) $source->is_active === 1 ? 'Sim' : 'Nao') . '</td>';
            $html .= '<td>' . esc_html((string) ($source->last_scraped_at ?? 'nunca')) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '<p><a href="' . esc_url($sourcesUrl) . '" class="button">Gerenciar fontes</a></p>';

        return $html;
    }

    private function handleSubmission(): void
    {
        if (($_POST['rjs_action'] ?? '') !== 'save_autopilot') {
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
}
