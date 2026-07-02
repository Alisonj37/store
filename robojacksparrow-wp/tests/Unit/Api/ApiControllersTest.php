<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Api;

use RoboJackSparrow\Api\Controllers\ArticleController;
use RoboJackSparrow\Api\Controllers\LogController;
use RoboJackSparrow\Api\Controllers\QueueController;
use RoboJackSparrow\Api\Controllers\SettingsController;
use RoboJackSparrow\Api\Controllers\SourceController;
use RoboJackSparrow\Api\Middleware\AuthMiddleware;
use RoboJackSparrow\Core\Encryption;
use RoboJackSparrow\Database\Repositories\ArticleRepository;
use RoboJackSparrow\Database\Repositories\LogRepository;
use RoboJackSparrow\Database\Repositories\QueueRepository;
use RoboJackSparrow\Database\Repositories\SettingRepository;
use RoboJackSparrow\Database\Repositories\SourceRepository;
use RoboJackSparrow\Tests\TestCase;
use WP_REST_Request;

final class ApiControllersTest extends TestCase
{
    public function testAuthMiddlewareReflectsCurrentUserCan(): void
    {
        $this->assertTrue(AuthMiddleware::check());

        $GLOBALS['__rjs_can'] = false;
        $this->assertFalse(AuthMiddleware::check());
    }

    public function testEveryRegisteredRouteWiresPermissionCallbackToAuthMiddleware(): void
    {
        (new ArticleController(new ArticleRepository()))->registerRoutes('robojacksparrow/v1');

        foreach ($GLOBALS['__rjs_routes']['robojacksparrow/v1 /articles'] as $endpoint) {
            $this->assertSame([AuthMiddleware::class, 'check'], $endpoint['permission_callback']);
        }
    }

    public function testArticleControllerIndexFiltersByStatusAndShow404s(): void
    {
        $this->wpdb->articles = [
            1 => ['id' => 1, 'status' => 'published', 'source_type' => 'rss', 'source_title' => 'A', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
            2 => ['id' => 2, 'status' => 'pending', 'source_type' => 'scraper', 'source_title' => 'B', 'created_at' => '2026-01-02 00:00:00', 'updated_at' => '2026-01-02 00:00:00'],
        ];

        $controller = new ArticleController(new ArticleRepository());
        $response = $controller->index(new WP_REST_Request(['status' => 'published']));

        $this->assertSame(1, $response->get_data()['data']['total']);
        $this->assertSame(404, $controller->show(new WP_REST_Request(['id' => 999]))->get_status());
    }

    public function testQueueControllerIndexAndShow(): void
    {
        $this->wpdb->queueRows = [
            1 => ['id' => 1, 'article_id' => 1, 'job_type' => 'scrape', 'status' => 'completed', 'attempts' => 1, 'max_attempts' => 3, 'available_at' => 'x', 'created_at' => '2026-01-01 00:00:00'],
        ];

        $controller = new QueueController(new QueueRepository());
        $response = $controller->show(new WP_REST_Request(['id' => 1]));

        $this->assertSame('scrape', $response->get_data()['data']['job_type']);
    }

    public function testLogControllerFiltersByLevel(): void
    {
        $this->wpdb->logs = [
            1 => ['id' => 1, 'level' => 'info', 'source' => 'Worker', 'message' => 'batch done', 'created_at' => '2026-01-01 00:00:00'],
            2 => ['id' => 2, 'level' => 'error', 'source' => 'LLMRouter', 'message' => 'all providers failed', 'created_at' => '2026-01-02 00:00:00'],
        ];

        $response = (new LogController(new LogRepository()))->index(new WP_REST_Request(['level' => 'error']));
        $items = $response->get_data()['data']['items'];

        $this->assertCount(1, $items);
        $this->assertSame('all providers failed', $items[0]['message']);
    }

    public function testSettingsControllerNeverReturnsARawSensitiveValue(): void
    {
        $settingRepo = new SettingRepository(new Encryption());
        $settingRepo->set('rjs_tavily_api_key', 'tvly-real-secret-value', 'string', true);

        $controller = new SettingsController($settingRepo);
        $response = $controller->show(new WP_REST_Request(['key' => 'rjs_tavily_api_key']));
        $data = $response->get_data()['data'];

        $this->assertTrue($data['sensitive']);
        $this->assertNotSame('tvly-real-secret-value', $data['value']);
        $this->assertStringContainsString('tvly', $data['value']);
    }

    public function testSettingsControllerUpdateAutoDetectsApiKeySuffixAsSensitive(): void
    {
        $settingRepo = new SettingRepository(new Encryption());
        $controller = new SettingsController($settingRepo);

        $response = $controller->update(new WP_REST_Request(['key' => 'rjs_openai_api_key', 'value' => 'sk-new-key']));

        $this->assertTrue($response->get_data()['success']);
        $this->assertSame('sk-new-key', $settingRepo->get('rjs_openai_api_key'));
        $this->assertNotSame('sk-new-key', $this->wpdb->settings['rjs_openai_api_key']['setting_value']);
    }

    public function testSettingsControllerShow404sForUnknownKey(): void
    {
        $controller = new SettingsController(new SettingRepository(new Encryption()));

        $this->assertSame(404, $controller->show(new WP_REST_Request(['key' => 'rjs_never_set']))->get_status());
    }

    public function testSourceControllerFullCrud(): void
    {
        $controller = new SourceController(new SourceRepository());

        $create = $controller->create(new WP_REST_Request(['source_name' => 'Tech Blog', 'source_url' => 'https://example.com/feed']));
        $this->assertSame(201, $create->get_status());
        $id = $create->get_data()['data']['id'];

        $show = $controller->show(new WP_REST_Request(['id' => $id]));
        $this->assertSame('https://example.com/feed', $show->get_data()['data']['source_url']);

        $update = $controller->update(new WP_REST_Request(['id' => $id, 'is_active' => false]));
        $this->assertFalse($update->get_data()['data']['is_active']);

        $destroy = $controller->destroy(new WP_REST_Request(['id' => $id]));
        $this->assertTrue($destroy->get_data()['data']['deleted']);
        $this->assertSame(404, $controller->show(new WP_REST_Request(['id' => $id]))->get_status());
    }

    public function testSourceControllerCreateValidatesRequiredFields(): void
    {
        $controller = new SourceController(new SourceRepository());

        $response = $controller->create(new WP_REST_Request(['source_name' => '', 'source_url' => '']));

        $this->assertSame(400, $response->get_status());
    }
}
