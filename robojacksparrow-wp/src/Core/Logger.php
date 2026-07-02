<?php

declare(strict_types=1);

namespace RoboJackSparrow\Core;

use wpdb;

class Logger
{
    private const LEVELS = ['debug', 'info', 'warning', 'error', 'critical'];

    private wpdb $db;
    private string $table;
    private string $logDir;

    public function __construct()
    {
        global $wpdb;

        $this->db = $wpdb;
        $this->table = $wpdb->prefix . 'rjs_logs';
        $this->logDir = RJS_PLUGIN_DIR . 'logs/';
    }

    public function debug(string $message, array $context = [], ?int $articleId = null, ?int $queueId = null): void
    {
        $this->log('debug', $message, $context, $articleId, $queueId);
    }

    public function info(string $message, array $context = [], ?int $articleId = null, ?int $queueId = null): void
    {
        $this->log('info', $message, $context, $articleId, $queueId);
    }

    public function warning(string $message, array $context = [], ?int $articleId = null, ?int $queueId = null): void
    {
        $this->log('warning', $message, $context, $articleId, $queueId);
    }

    public function error(string $message, array $context = [], ?int $articleId = null, ?int $queueId = null): void
    {
        $this->log('error', $message, $context, $articleId, $queueId);
    }

    public function critical(string $message, array $context = [], ?int $articleId = null, ?int $queueId = null): void
    {
        $this->log('critical', $message, $context, $articleId, $queueId);
    }

    private function log(string $level, string $message, array $context, ?int $articleId, ?int $queueId): void
    {
        if (!in_array($level, self::LEVELS, true)) {
            $level = 'info';
        }

        $source = $this->resolveCaller();

        $inserted = $this->db->insert(
            $this->table,
            [
                'article_id'   => $articleId,
                'queue_id'     => $queueId,
                'level'        => $level,
                'source'       => $source,
                'message'      => $message,
                'context'      => $context === [] ? null : wp_json_encode($context),
                'memory_usage' => memory_get_usage(true),
                'created_at'   => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s']
        );

        if ($inserted === false) {
            $this->writeToFile($level, $message, $context, $source);
        }
    }

    /**
     * Walks the call stack to find the class/method that triggered the log call,
     * so `source` reflects the real caller instead of always "Logger::log".
     */
    private function resolveCaller(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);

        foreach ($trace as $frame) {
            $class = $frame['class'] ?? '';

            if ($class !== '' && $class !== self::class) {
                return $class . '::' . ($frame['function'] ?? '');
            }
        }

        return self::class;
    }

    private function writeToFile(string $level, string $message, array $context, string $source): void
    {
        if (!is_dir($this->logDir)) {
            wp_mkdir_p($this->logDir);
        }

        $file = $this->logDir . 'robojacksparrow-' . gmdate('Y-m-d') . '.log';

        $line = sprintf(
            "[%s] %s %s: %s %s\n",
            gmdate('Y-m-d H:i:s'),
            strtoupper($level),
            $source,
            $message,
            $context === [] ? '' : (string) wp_json_encode($context)
        );

        error_log($line, 3, $file);
    }
}
