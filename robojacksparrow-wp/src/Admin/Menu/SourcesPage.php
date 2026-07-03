<?php

declare(strict_types=1);

namespace RoboJackSparrow\Admin\Menu;

use RoboJackSparrow\Cron\ScrapeFrequency;
use RoboJackSparrow\Database\Repositories\SourceRepository;

class SourcesPage
{
    private const NONCE_ACTION = 'rjs_sources';

    private const SOURCE_TYPES = [
        'rss'     => 'Feed RSS',
        'scraper' => 'URL de site (descobre links novos na pagina)',
    ];

    public function __construct(private SourceRepository $sources)
    {
    }

    public function render(): string
    {
        $notice = $this->handleSubmission();

        $html = '<div class="wrap"><h1>Fontes</h1>';
        $html .= '<p class="description">Fontes RSS sao lidas como feed; fontes do tipo "URL de site" sao visitadas periodicamente e novos links de artigo encontrados na pagina viram novos artigos (deteccao heuristica, sem garantia de 100% de precisao).</p>';

        if ($notice !== null) {
            $html .= $notice;
        }

        $editing = $this->sourceBeingEdited();
        $html .= $this->renderForm($editing);
        $html .= $this->renderTable();
        $html .= '</div>';

        return $html;
    }

    private function sourceBeingEdited(): ?object
    {
        $id = (int) ($_GET['edit'] ?? 0);

        return $id > 0 ? $this->sources->find($id) : null;
    }

    private function handleSubmission(): ?string
    {
        $action = (string) ($_POST['rjs_action'] ?? '');

        if ($action === '') {
            return null;
        }

        if (!current_user_can('manage_options') || !wp_verify_nonce((string) ($_POST['rjs_nonce'] ?? ''), self::NONCE_ACTION)) {
            return '<div class="notice notice-error"><p>Nao foi possivel validar a solicitacao.</p></div>';
        }

        return match ($action) {
            'add_source'    => $this->handleAdd(),
            'update_source' => $this->handleUpdate(),
            'delete_source' => $this->handleDelete(),
            'toggle_source' => $this->handleToggle(),
            default         => null,
        };
    }

    private function handleAdd(): ?string
    {
        $name = sanitize_text_field((string) ($_POST['source_name'] ?? ''));
        $url = esc_url_raw((string) ($_POST['source_url'] ?? ''));
        $frequency = (string) ($_POST['scrape_frequency'] ?? '');
        $frequency = ScrapeFrequency::isValid($frequency) ? $frequency : ScrapeFrequency::defaultKey();
        $type = (string) ($_POST['source_type'] ?? 'rss');
        $type = array_key_exists($type, self::SOURCE_TYPES) ? $type : 'rss';
        $categoryId = (int) ($_POST['category_id'] ?? 0);

        if ($name === '' || $url === '') {
            return '<div class="notice notice-error"><p>Informe nome e URL.</p></div>';
        }

        $this->sources->create([
            'source_name'      => $name,
            'source_url'       => $url,
            'source_type'      => $type,
            'scrape_frequency' => $frequency,
            'category_id'      => $categoryId > 0 ? $categoryId : null,
            'is_active'        => 1,
        ]);

        return '<div class="notice notice-success"><p>Fonte adicionada.</p></div>';
    }

    private function handleUpdate(): ?string
    {
        $id = (int) ($_POST['source_id'] ?? 0);
        if ($id <= 0 || $this->sources->find($id) === null) {
            return '<div class="notice notice-error"><p>Fonte nao encontrada.</p></div>';
        }

        $name = sanitize_text_field((string) ($_POST['source_name'] ?? ''));
        $url = esc_url_raw((string) ($_POST['source_url'] ?? ''));
        $frequency = (string) ($_POST['scrape_frequency'] ?? '');
        $frequency = ScrapeFrequency::isValid($frequency) ? $frequency : ScrapeFrequency::defaultKey();
        $type = (string) ($_POST['source_type'] ?? 'rss');
        $type = array_key_exists($type, self::SOURCE_TYPES) ? $type : 'rss';
        $categoryId = (int) ($_POST['category_id'] ?? 0);

        if ($name === '' || $url === '') {
            return '<div class="notice notice-error"><p>Informe nome e URL.</p></div>';
        }

        $this->sources->update($id, [
            'source_name'      => $name,
            'source_url'       => $url,
            'source_type'      => $type,
            'scrape_frequency' => $frequency,
            'category_id'      => $categoryId > 0 ? $categoryId : null,
        ]);

        return '<div class="notice notice-success"><p>Fonte atualizada.</p></div>';
    }

    private function handleDelete(): ?string
    {
        $id = (int) ($_POST['source_id'] ?? 0);
        if ($id > 0) {
            $this->sources->delete($id);
        }

        return '<div class="notice notice-success"><p>Fonte excluida.</p></div>';
    }

    private function handleToggle(): ?string
    {
        $id = (int) ($_POST['source_id'] ?? 0);
        $source = $id > 0 ? $this->sources->find($id) : null;

        if ($source !== null) {
            $this->sources->update($id, ['is_active' => $source->is_active ? 0 : 1]);
        }

        return null;
    }

    private function renderForm(?object $editing): string
    {
        $nonce = wp_create_nonce(self::NONCE_ACTION);

        $typeOptions = '';
        foreach (self::SOURCE_TYPES as $value => $label) {
            $selected = $editing !== null && $value === $editing->source_type ? ' selected' : '';
            $typeOptions .= sprintf('<option value="%s"%s>%s</option>', esc_attr($value), $selected, esc_html($label));
        }

        $currentFrequency = $editing->scrape_frequency ?? ScrapeFrequency::defaultKey();
        $frequencyOptions = '';
        foreach (ScrapeFrequency::choices() as $value => $label) {
            $selected = $value === $currentFrequency ? ' selected' : '';
            $frequencyOptions .= sprintf('<option value="%s"%s>%s</option>', esc_attr($value), $selected, esc_html($label));
        }

        $currentCategoryId = $editing !== null ? (int) ($editing->category_id ?? 0) : 0;
        $categoryOptions = $this->renderCategoryOptions($currentCategoryId);

        $isEditing = $editing !== null;
        $title = $isEditing ? 'Editar Fonte' : 'Adicionar Fonte';
        $action = $isEditing ? 'update_source' : 'add_source';
        $submitLabel = $isEditing ? 'Salvar Alteracoes' : 'Adicionar Fonte';

        $html = '<h2>' . esc_html($title) . '</h2>';
        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="rjs_action" value="' . esc_attr($action) . '">';
        $html .= '<input type="hidden" name="rjs_nonce" value="' . esc_attr($nonce) . '">';

        if ($isEditing) {
            $html .= '<input type="hidden" name="source_id" value="' . (int) $editing->id . '">';
        }

        $html .= '<p><label>Nome<br><input type="text" name="source_name" value="' . esc_attr((string) ($editing->source_name ?? '')) . '" required></label></p>';
        $html .= '<p><label>Tipo de fonte<br><select name="source_type">' . $typeOptions . '</select></label></p>';
        $html .= '<p><label>URL (feed RSS ou pagina do site)<br><input type="url" name="source_url" value="' . esc_attr((string) ($editing->source_url ?? '')) . '" required></label></p>';
        $html .= '<p><label>Frequencia de coleta<br><select name="scrape_frequency">' . $frequencyOptions . '</select></label></p>';
        $html .= '<p><label>Categoria (usada nos artigos gerados a partir desta fonte)<br><select name="category_id">' . $categoryOptions . '</select></label></p>';
        $html .= '<p><button type="submit" class="button button-primary">' . esc_html($submitLabel) . '</button>';

        if ($isEditing) {
            $html .= ' <a href="' . esc_url(remove_query_arg('edit')) . '" class="button">Cancelar</a>';
        }

        $html .= '</p></form>';

        return $html;
    }

    private function renderCategoryOptions(int $selectedId): string
    {
        $categories = function_exists('get_categories') ? get_categories(['hide_empty' => false]) : [];

        $options = '<option value="0">Sem categoria</option>';
        foreach ($categories as $category) {
            $selected = (int) $category->term_id === $selectedId ? ' selected' : '';
            $options .= sprintf(
                '<option value="%d"%s>%s</option>',
                (int) $category->term_id,
                $selected,
                esc_html($category->name)
            );
        }

        return $options;
    }

    private function renderTable(): string
    {
        $rows = $this->sources->findAll();

        if ($rows === []) {
            return '<p>Nenhum registro encontrado.</p>';
        }

        $nonce = wp_create_nonce(self::NONCE_ACTION);
        $frequencyLabels = ScrapeFrequency::choices();

        $html = '<h2>Fontes cadastradas</h2>';
        $html .= '<table class="widefat striped"><thead><tr>'
            . '<th>Nome</th><th>Tipo</th><th>URL</th><th>Categoria</th><th>Frequencia</th><th>Ativo</th><th>Acoes</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $source) {
            $typeLabel = self::SOURCE_TYPES[$source->source_type] ?? (string) $source->source_type;
            $frequencyLabel = $frequencyLabels[$source->scrape_frequency] ?? (string) $source->scrape_frequency;
            $isActive = (int) $source->is_active === 1;

            $html .= '<tr>';
            $html .= '<td>' . esc_html((string) $source->source_name) . '</td>';
            $html .= '<td>' . esc_html($typeLabel) . '</td>';
            $html .= '<td>' . esc_html((string) $source->source_url) . '</td>';
            $html .= '<td>' . esc_html($this->categoryName((int) ($source->category_id ?? 0))) . '</td>';
            $html .= '<td>' . esc_html($frequencyLabel) . '</td>';
            $html .= '<td>' . ($isActive ? 'Sim' : 'Nao (pausada)') . '</td>';
            $html .= '<td>' . $this->renderRowActions((int) $source->id, $isActive, $nonce) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    private function categoryName(int $categoryId): string
    {
        if ($categoryId <= 0 || !function_exists('get_term')) {
            return '-';
        }

        $term = get_term($categoryId, 'category');

        return ($term !== null && !is_wp_error($term)) ? (string) $term->name : '-';
    }

    private function renderRowActions(int $sourceId, bool $isActive, string $nonce): string
    {
        $toggleLabel = $isActive ? 'Pausar' : 'Ativar';

        $html = '<div style="display:flex;gap:0.5em;flex-wrap:wrap">';

        $html .= '<form method="post" style="display:inline">'
            . '<input type="hidden" name="rjs_action" value="toggle_source">'
            . '<input type="hidden" name="rjs_nonce" value="' . esc_attr($nonce) . '">'
            . '<input type="hidden" name="source_id" value="' . $sourceId . '">'
            . '<button type="submit" class="button">' . esc_html($toggleLabel) . '</button>'
            . '</form>';

        $html .= '<a href="' . esc_url(add_query_arg('edit', $sourceId)) . '" class="button">Editar</a>';

        $html .= '<form method="post" style="display:inline">'
            . '<input type="hidden" name="rjs_action" value="delete_source">'
            . '<input type="hidden" name="rjs_nonce" value="' . esc_attr($nonce) . '">'
            . '<input type="hidden" name="source_id" value="' . $sourceId . '">'
            . '<button type="submit" class="button" onclick="return confirm(\'Excluir esta fonte?\')">Excluir</button>'
            . '</form>';

        $html .= '</div>';

        return $html;
    }
}
