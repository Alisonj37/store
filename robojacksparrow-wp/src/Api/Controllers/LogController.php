<?php

declare(strict_types=1);

namespace RoboJackSparrow\Api\Controllers;

use RoboJackSparrow\Api\Dto\ApiResponse;
use RoboJackSparrow\Api\Middleware\AuthMiddleware;
use RoboJackSparrow\Database\Repositories\LogRepository;
use WP_REST_Request;
use WP_REST_Response;

class LogController
{
    private const MAX_LIMIT = 200;

    public function __construct(private LogRepository $logs)
    {
    }

    public function registerRoutes(string $namespace): void
    {
        register_rest_route($namespace, '/logs', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'index'],
                'permission_callback' => [AuthMiddleware::class, 'check'],
            ],
        ]);
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $limit = (int) ($request->get_param('limit') ?: 50);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $level = $request->get_param('level');
        $level = is_string($level) && $level !== '' ? $level : null;

        $items = $this->logs->findRecent($limit, $level);

        return ApiResponse::success([
            'items' => array_map([$this, 'format'], $items),
        ]);
    }

    private function format(object $log): array
    {
        return [
            'id'         => (int) $log->id,
            'article_id' => isset($log->article_id) ? (int) $log->article_id : null,
            'level'      => $log->level,
            'source'     => $log->source,
            'message'    => $log->message,
            'created_at' => $log->created_at,
        ];
    }
}
