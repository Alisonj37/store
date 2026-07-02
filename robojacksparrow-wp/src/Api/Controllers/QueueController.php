<?php

declare(strict_types=1);

namespace RoboJackSparrow\Api\Controllers;

use RoboJackSparrow\Api\Dto\ApiResponse;
use RoboJackSparrow\Api\Middleware\AuthMiddleware;
use RoboJackSparrow\Database\Repositories\QueueRepository;
use WP_REST_Request;
use WP_REST_Response;

class QueueController
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private QueueRepository $queue)
    {
    }

    public function registerRoutes(string $namespace): void
    {
        register_rest_route($namespace, '/queue', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'index'],
                'permission_callback' => [AuthMiddleware::class, 'check'],
            ],
        ]);

        register_rest_route($namespace, '/queue/(?P<id>\d+)', [
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

        $items = $this->queue->findRecent($perPage, ($page - 1) * $perPage, $status);
        $total = $this->queue->count($status);

        return ApiResponse::success([
            'items'    => array_map([$this, 'format'], $items),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ]);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $job = $this->queue->find((int) $request->get_param('id'));

        if ($job === null) {
            return ApiResponse::error('Queue job not found', 404);
        }

        return ApiResponse::success($this->format($job));
    }

    private function format(object $job): array
    {
        return [
            'id'           => (int) $job->id,
            'article_id'   => (int) $job->article_id,
            'job_type'     => $job->job_type,
            'status'       => $job->status,
            'attempts'     => (int) $job->attempts,
            'max_attempts' => isset($job->max_attempts) ? (int) $job->max_attempts : null,
            'available_at' => $job->available_at,
            'created_at'   => $job->created_at,
        ];
    }
}
