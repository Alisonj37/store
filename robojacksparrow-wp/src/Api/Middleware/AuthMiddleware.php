<?php

declare(strict_types=1);

namespace RoboJackSparrow\Api\Middleware;

/**
 * Permission callback compartilhado por todos os endpoints REST do
 * plugin: exige a mesma capability usada pelas paginas do wp-admin
 * (Fase 8), e se apoia na autenticacao/nonce nativos da REST API do
 * WordPress (cookie + X-WP-Nonce, ou Application Passwords).
 */
final class AuthMiddleware
{
    public static function check(): bool
    {
        return current_user_can('manage_options');
    }
}
