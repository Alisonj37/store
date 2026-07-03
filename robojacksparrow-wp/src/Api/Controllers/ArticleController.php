<?php

declare(strict_types=1);

namespace RoboJackSparrow\Api\Controllers;

use RoboJackSparrow\Api\Dto\ApiResponse;
use RoboJackSparrow\Api\Middleware\AuthMiddleware;
use RoboJackSparrow\Database\Repositories\ArticleRepository;
use WP_REST_Request;
use WP_REST_Response;

class ArticleController
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private ArticleRepository $articles)
    {
    }

    public function registerRoutes(string $namespace): void
    {
        register_rest_route($namespace, '/articles', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'index'],
                'permission_callback' => [AuthMiddleware::class, 'check'],
            ],
        ]);

        register_rest_route($namespace, '/articles/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'show'],
                'permission_callback' => [AuthMiddleware::class, 'check'],
            ],
        ]);
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $page = max(1, (int) ($request->get_param('page') ?: 1));
        $perPage = (int) ($request->get_param('per_page') ?: 20);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        $status = $request->get_param('status');
        $status = is_string($status) && $status !== '' ? $status : null;

        $items = $this->articles->findRecent($perPage, ($page - 1) * $perPage, $status);
        $total = $this->articles->count($status);

        return ApiResponse::success([
            'items'    => array_map([$this, 'format'], $items),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ]);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $article = $this->articles->find((int) $request->get_param('id'));

        if ($article === null) {
            return ApiResponse::error('Article not found', 404);
        }

        return ApiResponse::success($this->format($article));
    }

    private function format(object $article): array
    {
        return [
            'id'                 => (int) $article->id,
            'status'             => $article->status,
            'source_type'        => $article->source_type,
            'source_title'       => $article->source_title ?? null,
            'wordpress_post_id'  => isset($article->wordpress_post_id) ? (int) $article->wordpress_post_id : null,
            'wordpress_post_url' => $article->wordpress_post_url ?? null,
            'created_at'         => $article->created_at,
            'updated_at'         => $article->updated_at ?? null,
        ];
    }
}
