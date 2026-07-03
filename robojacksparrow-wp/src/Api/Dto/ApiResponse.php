<?php

declare(strict_types=1);

namespace RoboJackSparrow\Api\Dto;

use WP_REST_Response;

/**
 * Envelope de resposta padrao para todos os endpoints REST do plugin.
 */
final class ApiResponse
{
    public static function success(mixed $data, int $status = 200): WP_REST_Response
    {
        return new WP_REST_Response(['success' => true, 'data' => $data], $status);
    }

    public static function error(string $message, int $status = 400): WP_REST_Response
    {
        return new WP_REST_Response(['success' => false, 'message' => $message], $status);
    }
}
