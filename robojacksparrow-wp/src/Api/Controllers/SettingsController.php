<?php

declare(strict_types=1);

namespace RoboJackSparrow\Api\Controllers;

use RoboJackSparrow\Api\Dto\ApiResponse;
use RoboJackSparrow\Api\Middleware\AuthMiddleware;
use RoboJackSparrow\Database\Repositories\SettingRepository;
use WP_REST_Request;
use WP_REST_Response;

class SettingsController
{
    public function __construct(private SettingRepository $settings)
    {
    }

    public function registerRoutes(string $namespace): void
    {
        register_rest_route($namespace, '/settings/(?P<key>[a-zA-Z0-9_]+)', [
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
        ]);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $key = (string) $request->get_param('key');
        $sensitive = $this->settings->isSensitive($key);

        $value = $sensitive ? $this->settings->getMasked($key) : $this->settings->get($key);

        if ($value === null) {
            return ApiResponse::error('Setting not found', 404);
        }

        return ApiResponse::success([
            'key'       => $key,
            'value'     => $value,
            'sensitive' => $sensitive,
        ]);
    }

    /**
     * Sensitive settings (API keys) are always stored encrypted and are
     * never echoed back in full - only their masked preview is ever
     * returned by show().
     */
    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $key = (string) $request->get_param('key');
        $value = $request->get_param('value');

        if ($value === null || trim((string) $value) === '') {
            return ApiResponse::error('Missing "value" parameter', 400);
        }

        $sensitive = $this->settings->isSensitive($key);
        $this->settings->set($key, (string) $value, $sensitive ? 'encrypted' : 'string', $sensitive);

        return ApiResponse::success(['key' => $key, 'updated' => true]);
    }
}
