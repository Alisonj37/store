<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap: stubs the subset of the WordPress function/class API
 * this plugin relies on, so the test suite runs without a real WordPress
 * install or MySQL server (none is available in CI / this environment).
 *
 * The stateful table stubs (wpdb) live in RoboJackSparrow\Tests\FakeWpdb,
 * reset per-test via RoboJackSparrow\Tests\TestCase::setUp().
 */

error_reporting(E_ALL);

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/robojacksparrow-fake-wp/');
}
if (!defined('AUTH_KEY')) {
    define('AUTH_KEY', 'phpunit-auth-key-not-a-real-secret');
}
if (!defined('SECURE_AUTH_KEY')) {
    define('SECURE_AUTH_KEY', 'phpunit-secure-auth-key-not-a-real-secret');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

@mkdir(ABSPATH . 'wp-admin/includes', 0777, true);
foreach (['image.php', 'file.php', 'media.php'] as $file) {
    if (!is_file(ABSPATH . 'wp-admin/includes/' . $file)) {
        file_put_contents(ABSPATH . 'wp-admin/includes/' . $file, "<?php\n");
    }
}

define('RJS_VERSION', '3.0.0');
define('RJS_PLUGIN_FILE', dirname(__DIR__) . '/robojacksparrow-wp.php');
define('RJS_PLUGIN_DIR', dirname(__DIR__) . '/');
define('RJS_PLUGIN_URL', 'http://example.test/wp-content/plugins/robojacksparrow-wp/');
define('RJS_PLUGIN_BASENAME', 'robojacksparrow-wp/robojacksparrow-wp.php');

require dirname(__DIR__) . '/vendor/autoload.php';

// ---- Hook system (records callbacks; tests fire them explicitly via do_action()) --

$GLOBALS['__rjs_hooks'] = ['action' => [], 'filter' => []];

function add_action($hook, $callback, $priority = 10, $args = 1): void
{
    $GLOBALS['__rjs_hooks']['action'][$hook][] = $callback;
}

function add_filter($hook, $callback, $priority = 10, $args = 1): void
{
    $GLOBALS['__rjs_hooks']['filter'][$hook][] = $callback;
}

function apply_filters($hook, $value, ...$args)
{
    foreach ($GLOBALS['__rjs_hooks']['filter'][$hook] ?? [] as $callback) {
        $value = $callback($value, ...$args);
    }

    return $value;
}

function do_action($hook, ...$args): void
{
    foreach ($GLOBALS['__rjs_hooks']['action'][$hook] ?? [] as $callback) {
        $callback(...$args);
    }
}

function register_activation_hook($file, $callback): void {}
function register_deactivation_hook($file, $callback): void {}

// ---- Plugin bootstrap helpers -----------------------------------------------

function plugin_dir_path($file) { return dirname($file) . '/'; }
function plugin_dir_url($file) { return RJS_PLUGIN_URL; }
function plugin_basename($file) { return RJS_PLUGIN_BASENAME; }
function load_plugin_textdomain(...$args) { return true; }

// ---- Options API (also used as the Encryption fallback-secret store) -------

$GLOBALS['__rjs_options'] = [];

function get_option($key, $default = false)
{
    return $GLOBALS['__rjs_options'][$key] ?? $default;
}

function update_option($key, $value): bool
{
    $GLOBALS['__rjs_options'][$key] = $value;

    return true;
}

// ---- Cron -------------------------------------------------------------------

$GLOBALS['__rjs_cron'] = [];

function wp_next_scheduled($hook) { return $GLOBALS['__rjs_cron'][$hook] ?? false; }
function wp_schedule_event($timestamp, $recurrence, $hook) { $GLOBALS['__rjs_cron'][$hook] = $timestamp; return true; }
function wp_unschedule_event($timestamp, $hook) { unset($GLOBALS['__rjs_cron'][$hook]); return true; }
function wp_clear_scheduled_hook($hook) { unset($GLOBALS['__rjs_cron'][$hook]); return true; }

// ---- Misc formatting / sanitization -----------------------------------------

function current_time($type) { return gmdate('Y-m-d H:i:s'); }
function wp_json_encode($data) { return json_encode($data); }
function wp_mkdir_p($dir) { return @mkdir($dir, 0755, true); }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_url_raw($url) { return trim((string) $url); }
function sanitize_text_field($text) { return trim(strip_tags((string) $text)); }
function sanitize_title($title) {
    $slug = strtolower(trim((string) $title));
    $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-');
}
function wp_strip_all_tags($text) { return trim(strip_tags((string) $text)); }

// ---- Admin / capabilities / nonces ------------------------------------------

$GLOBALS['__rjs_is_admin'] = true;
function is_admin() { return $GLOBALS['__rjs_is_admin']; }

$GLOBALS['__rjs_can'] = true;
function current_user_can($cap) { return $GLOBALS['__rjs_can']; }
function wp_create_nonce($action) { return 'nonce-' . $action; }
function wp_verify_nonce($nonce, $action) { return $nonce === ('nonce-' . $action); }

$GLOBALS['__rjs_menu'] = [];
$GLOBALS['__rjs_submenu'] = [];
function add_menu_page($pageTitle, $menuTitle, $cap, $slug, $callback = '', $icon = '') { $GLOBALS['__rjs_menu'][$slug] = $callback; }
function add_submenu_page($parent, $pageTitle, $menuTitle, $cap, $slug, $callback = '') { $GLOBALS['__rjs_submenu'][$slug] = $callback; }

// ---- REST API -----------------------------------------------------------------

$GLOBALS['__rjs_routes'] = [];
function register_rest_route($namespace, $route, $args): void
{
    $GLOBALS['__rjs_routes'][$namespace . ' ' . $route][] = $args[0];
}

class WP_REST_Request
{
    public function __construct(private array $params = [])
    {
    }

    public function get_param($key)
    {
        return $this->params[$key] ?? null;
    }
}

class WP_REST_Response
{
    public function __construct(private array $data, private int $status = 200)
    {
    }

    public function get_data(): array
    {
        return $this->data;
    }

    public function get_status(): int
    {
        return $this->status;
    }
}

// ---- WP_Error / HTTP API ------------------------------------------------------

class WP_Error
{
    public function __construct(private string $code = '', private string $message = '')
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

/**
 * Tests register fixtures via RoboJackSparrow\Tests\HttpFixtures before
 * exercising code that calls wp_remote_get()/wp_remote_post().
 */
function wp_remote_get($url, $args = [])
{
    return \RoboJackSparrow\Tests\HttpFixtures::handle('GET', $url, $args);
}

function wp_remote_post($url, $args = [])
{
    return \RoboJackSparrow\Tests\HttpFixtures::handle('POST', $url, $args);
}

function wp_remote_retrieve_body($response) { return is_array($response) ? ($response['body'] ?? '') : ''; }
function wp_remote_retrieve_response_code($response) { return is_array($response) ? ($response['response']['code'] ?? 0) : 0; }

// ---- WordPress post/media/taxonomy API (Publisher, Fase 7) -------------------
// Delegates to the global $wpdb fake (tests/FakeWpdb.php), the same instance
// the repositories under test read/write through.

function wp_insert_post($data, $wpError = false)
{
    global $wpdb;

    return $wpdb->insertPost($data);
}

function get_permalink($postId) { return "https://example.test/?p={$postId}"; }

function get_term_by($field, $value, $taxonomy)
{
    global $wpdb;

    return $wpdb->getTermByName($value);
}

function wp_insert_term($name, $taxonomy)
{
    global $wpdb;

    return $wpdb->insertTerm($name);
}

function wp_set_post_tags($postId, $tags, $append)
{
    global $wpdb;
    $wpdb->setPostTags($postId, $tags);

    return true;
}

function wp_upload_bits($filename, $deprecated, $bits)
{
    global $wpdb;

    return $wpdb->uploadBits($filename, $bits);
}

function wp_insert_attachment($data, $file, $postId)
{
    global $wpdb;

    return $wpdb->insertAttachment($data, $file, $postId);
}

function wp_generate_attachment_metadata($attachmentId, $file) { return ['width' => 20, 'height' => 20]; }
function wp_update_attachment_metadata($attachmentId, $metadata) { return true; }

function set_post_thumbnail($postId, $attachmentId)
{
    global $wpdb;
    $wpdb->setPostThumbnail($postId, $attachmentId);

    return true;
}

function update_post_meta($postId, $key, $value)
{
    global $wpdb;
    $wpdb->updatePostMeta($postId, $key, $value);

    return true;
}

require __DIR__ . '/FakeWpdb.php';
