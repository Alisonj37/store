<?php

declare(strict_types=1);

namespace RoboJackSparrow\Admin;

/**
 * Pequenos helpers de renderizacao HTML compartilhados pelas paginas de
 * admin (PHP puro, sem build step / sem React).
 */
final class View
{
    public static function statusBadge(string $status): string
    {
        return sprintf(
            '<span class="rjs-badge rjs-badge-%s">%s</span>',
            esc_attr($status),
            esc_html($status)
        );
    }

    /**
     * @param array<int, object|array<string, mixed>> $rows
     * @param array<string, string> $columns Campo => rotulo da coluna.
     */
    public static function table(array $rows, array $columns, string $emptyMessage = 'Nenhum registro encontrado.'): string
    {
        if ($rows === []) {
            return '<p>' . esc_html($emptyMessage) . '</p>';
        }

        $html = '<table class="widefat striped"><thead><tr>';
        foreach ($columns as $label) {
            $html .= '<th>' . esc_html($label) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach (array_keys($columns) as $field) {
                $value = is_object($row) ? ($row->{$field} ?? '') : ($row[$field] ?? '');
                $html .= '<td>' . esc_html((string) $value) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }
}
