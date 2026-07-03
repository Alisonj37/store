<?php

declare(strict_types=1);

namespace RoboJackSparrow\Admin\Menu;

use RoboJackSparrow\Admin\View;
use RoboJackSparrow\Database\Repositories\LogRepository;

class LogsPage
{
    private const PER_PAGE = 50;

    /**
     * Must match the menu slug registered in Admin::registerMenu() for this
     * page - kept as a hidden field in the filter form so the GET
     * submission (both "Filtrar" and "Limpar filtros") stays on this page.
     */
    private const PAGE_SLUG = 'robojacksparrow-logs';

    private const LEVELS = ['debug', 'info', 'warning', 'error', 'critical'];

    private const PERIODS = [
        ''      => 'Todos',
        'today' => 'Hoje',
        '7d'    => 'Ultimos 7 dias',
        '30d'   => 'Ultimos 30 dias',
    ];

    public function __construct(private LogRepository $logs)
    {
    }

    public function render(): string
    {
        // "Limpar filtros" is a submit button in the SAME form (not a
        // separate <a href> pointing at a rebuilt admin URL) so clearing
        // filters can never depend on admin_url()/menu-slug generation
        // being right - it's a plain form submission the browser handles
        // natively, and this flag alone decides to ignore every other
        // field the browser resubmits alongside it.
        $cleared = isset($_GET['rjs_clear_filters']);

        $level = $cleared ? null : $this->stringParam('level');
        $source = $cleared ? null : $this->stringParam('module');
        $period = $cleared ? null : $this->stringParam('period');
        $articleId = $cleared ? 0 : (int) ($_GET['post_id'] ?? 0);

        $rows = $this->logs->findFiltered([
            'level'      => $level,
            'source'     => $source,
            'article_id' => $articleId > 0 ? $articleId : null,
            'since'      => $this->periodToSince($period),
        ], self::PER_PAGE);

        $html = '<div class="wrap"><h1>Logs</h1>';
        $html .= $this->renderFilterForm($level, $source, $period, $articleId);
        $html .= View::table($rows, [
            'created_at' => 'Data',
            'level'      => 'Nivel',
            'source'     => 'Origem',
            'article_id' => 'Post ID',
            'message'    => 'Mensagem',
        ]);
        $html .= '</div>';

        return $html;
    }

    private function renderFilterForm(?string $level, ?string $source, ?string $period, int $articleId): string
    {
        $html = '<form method="get" style="margin:1em 0;display:flex;gap:1em;align-items:flex-end;flex-wrap:wrap">';
        $html .= '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '">';

        $html .= '<p style="margin:0"><label>Tipo<br>' . $this->select('level', ['' => 'Todos'] + array_combine(self::LEVELS, self::LEVELS), $level ?? '') . '</label></p>';
        $html .= '<p style="margin:0"><label>Modulo<br>' . $this->select('module', $this->moduleChoices(), $source ?? '') . '</label></p>';
        $html .= '<p style="margin:0"><label>Periodo<br>' . $this->select('period', self::PERIODS, $period ?? '') . '</label></p>';
        $html .= '<p style="margin:0"><label>Post ID<br><input type="number" name="post_id" min="0" value="' . ($articleId > 0 ? $articleId : '0') . '" style="width:6em"></label></p>';

        $html .= '<p style="margin:0"><button type="submit" name="rjs_apply_filters" value="1" class="button button-primary">Filtrar</button>'
            . ' <button type="submit" name="rjs_clear_filters" value="1" formnovalidate class="button">Limpar filtros</button></p>';

        $html .= '</form>';

        return $html;
    }

    /**
     * @return array<string, string>
     */
    private function moduleChoices(): array
    {
        $choices = ['' => 'Todos os modulos'];
        foreach ($this->logs->distinctSources() as $source) {
            $choices[$source] = $source;
        }

        return $choices;
    }

    /**
     * @param array<string, string> $choices
     */
    private function select(string $name, array $choices, string $selected): string
    {
        $options = '';
        foreach ($choices as $value => $label) {
            $isSelected = $value === $selected ? ' selected' : '';
            $options .= sprintf('<option value="%s"%s>%s</option>', esc_attr($value), $isSelected, esc_html($label));
        }

        return sprintf('<select name="%s">%s</select>', esc_attr($name), $options);
    }

    private function periodToSince(?string $period): ?string
    {
        return match ($period) {
            'today' => gmdate('Y-m-d 00:00:00'),
            '7d'    => gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS),
            '30d'   => gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS),
            default => null,
        };
    }

    private function stringParam(string $key): ?string
    {
        $value = isset($_GET[$key]) ? sanitize_text_field((string) $_GET[$key]) : '';

        return $value !== '' ? $value : null;
    }
}
