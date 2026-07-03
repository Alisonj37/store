<?php

declare(strict_types=1);

namespace RoboJackSparrow\Api\Controllers;

use RoboJackSparrow\Api\Dto\ApiResponse;
use RoboJackSparrow\Api\Middleware\AuthMiddleware;
use RoboJackSparrow\Database\Repositories\SourceRepository;
use WP_REST_Request;
use WP_REST_Response;

class SourceController
{
    public function __construct(private SourceRepository $sources)
    {
    }

    public function registerRoutes(string $namespace): void
    {
        register_rest_route($namespace, '/sources', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'index'],
                'permission_callback' => [AuthMiddleware::class, 'check'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create'],
                'permission_callback' => [AuthMiddleware::class, 'check'],
            ],
        ]);

        register_rest_route($namespace, '/sources/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'show'],
                'permission_callback' => [AuthMiddleware::class, 'check'],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [$this, 'update'],
                'permission_callback' => [AuthMiddleware::class, 'check'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'destroy'],
                'permission_callback' => [AuthMiddleware::class, 'check'],
            ],
        ]);
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        return ApiResponse::success([
            'items' => array_map([$this, 'format'], $this->sources->findAll()),
        ]);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $source = $this->sources->find((int) $request->get_param('id'));

        if ($source === null) {
            return ApiResponse::error('Source not found', 404);
        }

        return ApiResponse::success($this->format($source));
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $name = sanitize_text_field((string) $request->get_param('source_name'));
        $url = esc_url_raw((string) $request->get_param('source_url'));

        if ($name === '' || $url === '') {
            return ApiResponse::error('"source_name" and "source_url" are required', 400);
        }

        $frequency = (string) ($request->get_param('scrape_frequency') ?: '4_hours');

        $id = $this->sources->create([
            'source_name'      => $name,
            'source_url'       => $url,
            'source_type'      => 'rss',
            'scrape_frequency' => sanitize_text_field($frequency),
            'is_active'        => 1,
        ]);

        $source = $this->sources->find($id);

        return ApiResponse::success($source !== null ? $this->format($source) : ['id' => $id], 201);
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request->get_param('id');

        if ($this->sources->find($id) === null) {
            return ApiResponse::error('Source not found', 404);
        }

        $data = [];

        foreach (['source_name', 'source_url', 'scrape_frequency'] as $field) {
            $value = $request->get_param($field);
            if ($value !== null) {
                $data[$field] = $field === 'source_url' ? esc_url_raw((string) $value) : sanitize_text_field((string) $value);
            }
        }

        $isActive = $request->get_param('is_active');
        if ($isActive !== null) {
            $data['is_active'] = (int) (bool) $isActive;
        }

        if ($data !== []) {
            $this->sources->update($id, $data);
        }

        return ApiResponse::success($this->format($this->sources->find($id)));
    }

    public function destroy(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request->get_param('id');

        if ($this->sources->find($id) === null) {
            return ApiResponse::error('Source not found', 404);
        }

        $this->sources->delete($id);

        return ApiResponse::success(['id' => $id, 'deleted' => true]);
    }

    private function format(object $source): array
    {
        return [
            'id'               => (int) $source->id,
            'source_name'      => $source->source_name,
            'source_url'       => $source->source_url,
            'source_type'      => $source->source_type,
            'is_active'        => (bool) $source->is_active,
            'scrape_frequency' => $source->scrape_frequency,
            'last_scraped_at'  => $source->last_scraped_at ?? null,
        ];
    }
}
