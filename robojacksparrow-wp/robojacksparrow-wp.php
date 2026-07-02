<?php
/**
 * Plugin Name: RoboJackSparrow WP
 * Plugin URI: https://robojacksparrow.com
 * Description: Automacao de conteudo WordPress nativa - scraping, pesquisa em tempo real, geracao de conteudo com IA e publicacao automatizada. Sem dependencia de n8n ou Google Sheets.
 * Version: 3.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: RoboJackSparrow
 * License: Proprietary
 * Text Domain: robojacksparrow
 * Domain Path: /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('RJS_VERSION', '3.0.0');
define('RJS_PLUGIN_FILE', __FILE__);
define('RJS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RJS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RJS_PLUGIN_BASENAME', plugin_basename(__FILE__));

/*
 * Prefer Composer's autoloader when available (local dev / CI). Falls back to a
 * lightweight PSR-4 autoloader so the plugin also works on shared hosting where
 * `composer install` was never run.
 */
if (file_exists(RJS_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once RJS_PLUGIN_DIR . 'vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'RoboJackSparrow\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = RJS_PLUGIN_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($path)) {
            require_once $path;
        }
    });
}

register_activation_hook(RJS_PLUGIN_FILE, ['RoboJackSparrow\\Core\\Plugin', 'activate']);
register_deactivation_hook(RJS_PLUGIN_FILE, ['RoboJackSparrow\\Core\\Plugin', 'deactivate']);

add_action('plugins_loaded', ['RoboJackSparrow\\Core\\Plugin', 'instance']);
