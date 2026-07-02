<?php

declare(strict_types=1);

namespace RoboJackSparrow\Admin\Menu;

use RoboJackSparrow\Admin\View;
use RoboJackSparrow\Database\Repositories\SourceRepository;

class SourcesPage
{
    private const NONCE_ACTION = 'rjs_sources';

    public function __construct(private SourceRepository $sources)
    {
    }

    public function render(): string
    {
        $this->handleSubmission();

        $html = '<div class="wrap"><h1>Fontes RSS</h1>';
        $html .= $this->renderForm();
        $html .= View::table($this->sources->findAll(), [
            'id'               => 'ID',
            'source_name'      => 'Nome',
            'source_url'       => 'URL',
            'scrape_frequency' => 'Frequencia',
            'is_active'        => 'Ativo',
        ]);
        $html .= '</div>';

        return $html;
    }

    private function handleSubmission(): void
    {
        $action = (string) ($_POST['rjs_action'] ?? '');

        if ($action === '') {
            return;
        }

        if (!current_user_can('manage_options') || !wp_verify_nonce((string) ($_POST['rjs_nonce'] ?? ''), self::NONCE_ACTION)) {
            return;
        }

        match ($action) {
            'add_source'    => $this->handleAdd(),
            'delete_source' => $this->handleDelete(),
            'toggle_source' => $this->handleToggle(),
            default         => null,
        };
    }

    private function handleAdd(): void
    {
        $name = sanitize_text_field((string) ($_POST['source_name'] ?? ''));
        $url = esc_url_raw((string) ($_POST['source_url'] ?? ''));
        $frequency = sanitize_text_field((string) ($_POST['scrape_frequency'] ?? '4_hours'));

        if ($name === '' || $url === '') {
            return;
        }

        $this->sources->create([
            'source_name'      => $name,
            'source_url'       => $url,
            'source_type'      => 'rss',
            'scrape_frequency' => $frequency,
            'is_active'        => 1,
        ]);
    }

    private function handleDelete(): void
    {
        $id = (int) ($_POST['source_id'] ?? 0);
        if ($id > 0) {
            $this->sources->delete($id);
        }
    }

    private function handleToggle(): void
    {
        $id = (int) ($_POST['source_id'] ?? 0);
        $source = $id > 0 ? $this->sources->find($id) : null;

        if ($source !== null) {
            $this->sources->update($id, ['is_active' => $source->is_active ? 0 : 1]);
        }
    }

    private function renderForm(): string
    {
        $nonce = wp_create_nonce(self::NONCE_ACTION);

        return '<form method="post">'
            . '<input type="hidden" name="rjs_action" value="add_source">'
            . '<input type="hidden" name="rjs_nonce" value="' . esc_attr($nonce) . '">'
            . '<p><label>Nome<br><input type="text" name="source_name" required></label></p>'
            . '<p><label>URL do Feed<br><input type="url" name="source_url" required></label></p>'
            . '<p><button type="submit" class="button button-primary">Adicionar Fonte</button></p>'
            . '</form>';
    }
}
