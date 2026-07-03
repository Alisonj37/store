<?php

declare(strict_types=1);

namespace RoboJackSparrow\Database;

class Schema
{
    private const DB_VERSION = '1.0.0';
    private const DB_VERSION_OPTION = 'rjs_db_version';

    public static function install(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = self::charsetCollate();

        foreach (self::definitions($charsetCollate) as $sql) {
            dbDelta($sql);
        }

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    public static function uninstall(): void
    {
        global $wpdb;

        foreach (array_reverse(self::tableNames()) as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }

        delete_option(self::DB_VERSION_OPTION);
    }

    /**
     * @return string[] Fully prefixed table names, in creation/dependency order.
     */
    public static function tableNames(): array
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'rjs_';

        return [
            $prefix . 'articles',
            $prefix . 'queue',
            $prefix . 'logs',
            $prefix . 'settings',
            $prefix . 'memory',
            $prefix . 'llm_health',
            $prefix . 'sources',
        ];
    }

    private static function charsetCollate(): string
    {
        global $wpdb;

        return $wpdb->get_charset_collate();
    }

    /**
     * @return string[] dbDelta-compatible CREATE TABLE statements.
     */
    private static function definitions(string $charsetCollate): array
    {
        [$articles, $queue, $logs, $settings, $memory, $llmHealth, $sources] = self::tableNames();

        return [
            self::articlesTable($articles, $charsetCollate),
            self::queueTable($queue, $charsetCollate),
            self::logsTable($logs, $charsetCollate),
            self::settingsTable($settings, $charsetCollate),
            self::memoryTable($memory, $charsetCollate),
            self::llmHealthTable($llmHealth, $charsetCollate),
            self::sourcesTable($sources, $charsetCollate),
        ];
    }

    /**
     * Controle central de artigos (substitui a planilha do Google Sheets do n8n).
     */
    private static function articlesTable(string $table, string $charsetCollate): string
    {
        return "CREATE TABLE {$table} (
            id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_type            VARCHAR(20) NOT NULL,
            source_url             LONGTEXT NULL,
            source_title           VARCHAR(500) NULL,
            rss_feed_url           VARCHAR(500) NULL,
            status                 VARCHAR(20) NOT NULL DEFAULT 'pending',
            wordpress_post_id      BIGINT UNSIGNED NULL,
            wordpress_post_url     VARCHAR(500) NULL,
            category_id            BIGINT UNSIGNED NULL,
            category_name          VARCHAR(200) NULL,
            tags                   JSON NULL,
            assigned_scraper       VARCHAR(20) NOT NULL DEFAULT 'auto',
            assigned_llm           VARCHAR(20) NOT NULL DEFAULT 'auto',
            assigned_llm_model     VARCHAR(100) NULL,
            assigned_image_source  VARCHAR(20) NOT NULL DEFAULT 'auto',
            image_prompt           LONGTEXT NULL,
            featured_image_id      BIGINT UNSIGNED NULL,
            featured_image_url     VARCHAR(500) NULL,
            seo_title              VARCHAR(200) NULL,
            seo_description        VARCHAR(320) NULL,
            seo_schema             JSON NULL,
            faq_schema             JSON NULL,
            content_outline        JSON NULL,
            generated_content      LONGTEXT NULL,
            content_tokens_used    INT UNSIGNED NOT NULL DEFAULT 0,
            image_tokens_used      INT UNSIGNED NOT NULL DEFAULT 0,
            similarity_score       DECIMAL(5,2) NULL,
            duplicate_of           BIGINT UNSIGNED NULL,
            watermark_enabled      TINYINT(1) NOT NULL DEFAULT 1,
            watermark_logo_url     VARCHAR(500) NULL,
            watermark_overlay_url  VARCHAR(500) NULL,
            custom_post_type       VARCHAR(20) NOT NULL DEFAULT 'post',
            custom_taxonomy        VARCHAR(32) NOT NULL DEFAULT 'category',
            target_post_status     VARCHAR(20) NOT NULL DEFAULT 'publish',
            scheduled_at           DATETIME NULL,
            started_at             DATETIME NULL,
            completed_at           DATETIME NULL,
            error_message          LONGTEXT NULL,
            retry_count            TINYINT UNSIGNED NOT NULL DEFAULT 0,
            max_retries            TINYINT UNSIGNED NOT NULL DEFAULT 3,
            priority                TINYINT UNSIGNED NOT NULL DEFAULT 5,
            created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_status (status),
            KEY idx_source_type (source_type),
            KEY idx_scheduled_at (scheduled_at),
            KEY idx_created_at (created_at),
            KEY idx_wordpress_post_id (wordpress_post_id),
            KEY idx_similarity (similarity_score),
            KEY idx_priority_scheduled (priority, scheduled_at),
            KEY idx_status_priority (status, priority)
        ) ENGINE=InnoDB {$charsetCollate};";
    }

    /**
     * Fila interna de jobs (substitui o controle de execucao do n8n).
     */
    private static function queueTable(string $table, string $charsetCollate): string
    {
        return "CREATE TABLE {$table} (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            article_id          BIGINT UNSIGNED NOT NULL,
            job_type            VARCHAR(20) NOT NULL,
            job_payload         JSON NOT NULL,
            status              VARCHAR(20) NOT NULL DEFAULT 'pending',
            assigned_worker     VARCHAR(100) NULL,
            attempts            TINYINT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts        TINYINT UNSIGNED NOT NULL DEFAULT 3,
            error_message       LONGTEXT NULL,
            started_at          DATETIME NULL,
            completed_at        DATETIME NULL,
            available_at        DATETIME NOT NULL,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_status_available (status, available_at),
            KEY idx_article_id (article_id),
            KEY idx_job_type (job_type),
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB {$charsetCollate};";
    }

    /**
     * Auditoria completa (substitui o log visual do n8n).
     */
    private static function logsTable(string $table, string $charsetCollate): string
    {
        return "CREATE TABLE {$table} (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            article_id          BIGINT UNSIGNED NULL,
            queue_id            BIGINT UNSIGNED NULL,
            level               VARCHAR(20) NOT NULL DEFAULT 'info',
            source              VARCHAR(100) NOT NULL,
            message             LONGTEXT NOT NULL,
            context             JSON NULL,
            memory_usage        BIGINT UNSIGNED NULL,
            execution_time_ms   INT UNSIGNED NULL,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_article_id (article_id),
            KEY idx_level (level),
            KEY idx_source (source),
            KEY idx_created_at (created_at),
            KEY idx_level_created (level, created_at)
        ) ENGINE=InnoDB {$charsetCollate};";
    }

    /**
     * Configuracoes versionadas (substitui as variaveis de ambiente do n8n).
     */
    private static function settingsTable(string $table, string $charsetCollate): string
    {
        return "CREATE TABLE {$table} (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            setting_key         VARCHAR(100) NOT NULL,
            setting_value       LONGTEXT NULL,
            setting_type        VARCHAR(20) NOT NULL DEFAULT 'string',
            description         VARCHAR(500) NULL,
            is_sensitive        TINYINT(1) NOT NULL DEFAULT 0,
            updated_by          BIGINT UNSIGNED NULL,
            updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_setting_key (setting_key),
            KEY idx_setting_type (setting_type)
        ) ENGINE=InnoDB {$charsetCollate};";
    }

    /**
     * Memoria de contexto do agente (janela deslizante entre secoes/sessoes).
     */
    private static function memoryTable(string $table, string $charsetCollate): string
    {
        return "CREATE TABLE {$table} (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            memory_key          VARCHAR(100) NOT NULL,
            role                VARCHAR(20) NOT NULL,
            content             LONGTEXT NOT NULL,
            tokens              SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            metadata            JSON NULL,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_memory_key (memory_key),
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB {$charsetCollate};";
    }

    /**
     * Health-check dos provedores de LLM, usado pelo LLMRouter (Fase 4).
     */
    private static function llmHealthTable(string $table, string $charsetCollate): string
    {
        return "CREATE TABLE {$table} (
            id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider                VARCHAR(20) NOT NULL,
            status                  VARCHAR(20) NOT NULL,
            last_check              DATETIME NOT NULL,
            last_success            DATETIME NULL,
            last_error              LONGTEXT NULL,
            avg_latency_ms          INT UNSIGNED NULL,
            success_rate_24h        DECIMAL(5,2) NULL,
            consecutive_failures    TINYINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_provider (provider),
            KEY idx_status (status)
        ) ENGINE=InnoDB {$charsetCollate};";
    }

    /**
     * Fontes RSS/scraper cadastradas.
     */
    private static function sourcesTable(string $table, string $charsetCollate): string
    {
        return "CREATE TABLE {$table} (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_name         VARCHAR(200) NOT NULL,
            source_url          VARCHAR(500) NOT NULL,
            source_type         VARCHAR(20) NOT NULL DEFAULT 'rss',
            category_id         BIGINT UNSIGNED NULL,
            is_active           TINYINT(1) NOT NULL DEFAULT 1,
            scrape_frequency    VARCHAR(20) NOT NULL DEFAULT '4_hours',
            last_scraped_at     DATETIME NULL,
            last_items_hash     VARCHAR(64) NULL,
            custom_settings     JSON NULL,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_active (is_active),
            KEY idx_type (source_type),
            KEY idx_frequency (scrape_frequency)
        ) ENGINE=InnoDB {$charsetCollate};";
    }
}
