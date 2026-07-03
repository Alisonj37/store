<?php

declare(strict_types=1);

namespace RoboJackSparrow\Database\Repositories;

use RoboJackSparrow\Core\Encryption;
use RoboJackSparrow\Core\Logger;
use RuntimeException;
use wpdb;

/**
 * Acesso a tabela {prefix}_rjs_settings. Valores sensiveis (is_sensitive=1
 * ou setting_type='encrypted') sao criptografados em repouso via
 * Encryption (AES-256-GCM) e transparentemente decriptados na leitura.
 */
class SettingRepository
{
    private wpdb $db;
    private string $table;

    public function __construct(
        private Encryption $encryption,
        private Logger $logger
    ) {
        global $wpdb;

        $this->db = $wpdb;
        $this->table = $wpdb->prefix . 'rjs_settings';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT setting_value, setting_type, is_sensitive FROM {$this->table} WHERE setting_key = %s",
                $key
            )
        );

        if ($row === null) {
            return $default;
        }

        try {
            return $this->decodeValue($row);
        } catch (RuntimeException $e) {
            // A rotated AUTH_KEY/SECURE_AUTH_KEY, a restored DB from a
            // different site, or a lost fallback secret all make previously
            // stored values undecryptable. One bad key must not fatal an
            // entire admin page or REST request - degrade to $default and
            // let the caller detect/re-prompt for reconfiguration instead.
            $this->logger->warning('Failed to decrypt setting, returning default', [
                'setting_key' => $key,
                'error'       => $e->getMessage(),
            ]);

            return $default;
        }
    }

    public function set(string $key, mixed $value, string $type = 'string', bool $sensitive = false, ?string $description = null): void
    {
        $data = [
            'setting_key'   => $key,
            'setting_value' => $this->encodeValue($value, $type, $sensitive),
            'setting_type'  => $sensitive ? 'encrypted' : $type,
            'is_sensitive'  => $sensitive ? 1 : 0,
            'updated_at'    => current_time('mysql'),
        ];

        if ($description !== null) {
            $data['description'] = $description;
        }

        $exists = $this->db->get_var(
            $this->db->prepare("SELECT id FROM {$this->table} WHERE setting_key = %s", $key)
        );

        if ($exists) {
            $this->db->update($this->table, $data, ['setting_key' => $key]);

            return;
        }

        $data['created_at'] = current_time('mysql');
        $this->db->insert($this->table, $data);
    }

    public function delete(string $key): void
    {
        $this->db->delete($this->table, ['setting_key' => $key]);
    }

    /**
     * Whether a key is flagged sensitive (or already stored as such).
     * Unknown keys ending in "_api_key" are treated as sensitive by
     * convention even before their first save.
     */
    public function isSensitive(string $key): bool
    {
        $row = $this->db->get_row(
            $this->db->prepare("SELECT is_sensitive FROM {$this->table} WHERE setting_key = %s", $key)
        );

        if ($row !== null) {
            return (int) $row->is_sensitive === 1;
        }

        return str_ends_with($key, '_api_key');
    }

    /**
     * Masked preview safe to display in the admin UI without exposing the
     * full secret (e.g. "sk-1...ab9f"). Null when unset.
     */
    public function getMasked(string $key): ?string
    {
        $value = $this->get($key);
        if (!is_string($value) || $value === '') {
            return null;
        }

        if (strlen($value) <= 8) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 4) . str_repeat('*', max(3, strlen($value) - 8)) . substr($value, -4);
    }

    private function decodeValue(object $row): mixed
    {
        $raw = $row->setting_value;

        if ((int) $row->is_sensitive === 1 || $row->setting_type === 'encrypted') {
            if ($raw === null || $raw === '') {
                return '';
            }

            return $this->encryption->decrypt((string) $raw);
        }

        return match ($row->setting_type) {
            'json' => json_decode((string) $raw, true),
            'bool' => (bool) $raw,
            'int'  => (int) $raw,
            default => $raw,
        };
    }

    private function encodeValue(mixed $value, string $type, bool $sensitive): string
    {
        if ($sensitive) {
            return $this->encryption->encrypt((string) $value);
        }

        return match ($type) {
            'json' => (string) wp_json_encode($value),
            'bool' => $value ? '1' : '0',
            default => (string) $value,
        };
    }
}
