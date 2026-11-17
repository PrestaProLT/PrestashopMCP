<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Log;

/**
 * Writes the MCP activity log (table ppmcp_log). Never throws: a logging
 * failure must not break the tool call, it is reported to PrestaShopLogger.
 */
class ActionLogger
{
    const MAX_FIELD_BYTES = 60000;

    /** Argument / result keys whose values are always masked. */
    /**
     * Names (config keys, argument keys) whose values are always masked:
     * passwords, tokens, API/private/merchant keys (*_KEY, *_KEY_*), salts,
     * signatures, hashes, secret keys (*_SK), credentials, licences, cards.
     */
    const SECRET_KEYS = '/(pass(word)?|passwd|pwd|secret|token|api[_-]?key|private|(^|[_-])key($|[_-])|[a-z]key$|salt|sign(ature)?|hash|(^|_)sk($|_)|credential|licen[cs]e|authorization|cookie|card|cvv|iban)/i';

    public static function log(array $entry): void
    {
        try {
            $row = [
                'request_id' => pSQL(substr((string) self::get($entry, 'request_id', ''), 0, 32)),
                'id_ppmcp_key' => (int) self::get($entry, 'id_ppmcp_key', 0),
                'id_employee' => (int) self::get($entry, 'id_employee', 0),
                'id_shop' => (int) self::get($entry, 'id_shop', 0),
                'id_lang' => (int) self::get($entry, 'id_lang', 0),
                'client' => pSQL(substr((string) self::get($entry, 'client', ''), 0, 128)),
                'protocol_version' => pSQL(substr((string) self::get($entry, 'protocol_version', ''), 0, 16)),
                'rpc_method' => pSQL(substr((string) self::get($entry, 'rpc_method', ''), 0, 64)),
                'tool' => pSQL(substr((string) self::get($entry, 'tool', ''), 0, 128)),
                'owner' => pSQL(substr((string) self::get($entry, 'owner', ''), 0, 64)),
                'is_write' => (int) (bool) self::get($entry, 'is_write', false),
                'dry_run' => (int) (bool) self::get($entry, 'dry_run', false),
                'status' => pSQL((string) self::get($entry, 'status', 'ok')),
                'error' => pSQL(self::truncate((string) self::get($entry, 'error', ''), 4000)),
                'arguments' => pSQL(self::encode(self::get($entry, 'arguments', null), (bool) \Configuration::getGlobalValue('PPMCP_LOG_ARGUMENTS')), true),
                'result' => pSQL(self::encode(self::get($entry, 'result', null), (bool) \Configuration::getGlobalValue('PPMCP_LOG_RESULTS')), true),
                'changes' => pSQL(self::encode(self::get($entry, 'changes', null), true), true),
                'changes_count' => is_array(self::get($entry, 'changes', null)) ? count($entry['changes']) : 0,
                'duration_ms' => (int) self::get($entry, 'duration_ms', 0),
                'ip' => pSQL(substr((string) self::get($entry, 'ip', ''), 0, 45)),
                'date_add' => date('Y-m-d H:i:s'),
            ];
            \Db::getInstance()->insert('ppmcp_log', $row, false, false);
        } catch (\Throwable $e) {
            if (class_exists('PrestaShopLogger')) {
                \PrestaShopLogger::addLog('ppmcp: activity log write failed: ' . $e->getMessage(), 3, null, 'ppmcp');
            }
        }
    }

    /**
     * Deletes log rows older than the configured retention.
     */
    public static function purge(): int
    {
        $days = (int) \Configuration::getGlobalValue('PPMCP_LOG_RETENTION_DAYS');
        if ($days <= 0) {
            return 0;
        }
        \Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'ppmcp_log` WHERE `date_add` < \'' . pSQL(date('Y-m-d H:i:s', time() - $days * 86400)) . '\''
        );

        return (int) \Db::getInstance()->Affected_Rows();
    }

    /**
     * Unauthenticated requests are logged at most 30 times per IP per 10
     * minutes, so anonymous traffic cannot flood the activity log.
     */
    public static function authFailureThrottled(string $ip): bool
    {
        try {
            $count = (int) \Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ppmcp_log`
                 WHERE `id_ppmcp_key` = 0 AND `status` = \'denied\' AND `ip` = \'' . pSQL(substr($ip, 0, 45)) . '\'
                 AND `date_add` > \'' . pSQL(date('Y-m-d H:i:s', time() - 600)) . '\''
            );

            return $count >= 30;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Runs purge() at most once a day (called after the response is sent).
     */
    public static function purgeDaily(): void
    {
        try {
            $today = date('Y-m-d');
            if (\Configuration::getGlobalValue('PPMCP_LAST_PURGE') !== $today) {
                \Configuration::updateGlobalValue('PPMCP_LAST_PURGE', $today);
                self::purge();
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * Masks secrets recursively.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public static function redact($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $k => $v) {
            if (is_string($k) && preg_match(self::SECRET_KEYS, $k)) {
                $value[$k] = '***';
            } else {
                $value[$k] = self::redact($v);
            }
        }

        return $value;
    }

    private static function encode($value, bool $enabled): string
    {
        if (!$enabled || $value === null) {
            return '';
        }
        $json = json_encode(self::redact($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return self::truncate((string) $json, self::MAX_FIELD_BYTES);
    }

    private static function truncate(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }

        return substr($s, 0, $max) . '…[truncated ' . (strlen($s) - $max) . ' bytes]';
    }

    private static function get(array $a, string $k, $default)
    {
        return array_key_exists($k, $a) ? $a[$k] : $default;
    }
}
