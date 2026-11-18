<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Install;

use PrestaPro\PpMcp\OAuth\OAuthServer;
use PrestaPro\PpMcp\Tool\ToolRegistry;

/**
 * Idempotent installer: safe to run from install(), reset() and upgrade
 * scripts. Never drops or truncates data (that is Uninstaller's job).
 */
class Installer
{
    const PARENT_TAB = 'AdminPpMcp';

    /** class => [name, icon] — children of the module's own menu section */
    const TABS = [
        'AdminPpMcpConnect' => ['Approve connection', 'link'],
        'AdminPpMcpKeys' => ['API keys', 'vpn_key'],
        'AdminPpMcpLogs' => ['Activity log', 'history'],
        'AdminPpMcpTools' => ['Tools & skills', 'build'],
    ];

    const DEFAULT_CONFIG = [
        'PPMCP_ENABLED' => 1,
        'PPMCP_READ_ONLY' => 0,
        'PPMCP_LOG_RETENTION_DAYS' => 180,
        'PPMCP_LOG_ARGUMENTS' => 1,
        'PPMCP_LOG_RESULTS' => 0,
        'PPMCP_ALLOWED_ORIGINS' => '',
        'PPMCP_DISABLED_TOOLS' => '[]',
        'PPMCP_MAX_FILE_BYTES' => 200000,
        'PPMCP_LAST_PURGE' => '',
        'PPMCP_OAUTH_ENABLED' => 1,
        'PPMCP_OAUTH_REDIRECT_HOSTS' => OAuthServer::DEFAULT_REDIRECT_HOSTS,
        'PPMCP_OAUTH_ACCESS_TTL' => 3600,
    ];

    /** hooks the module implements */
    const HOOKS = ['moduleRoutes'];

    /** @var \Module */
    private $module;
    /** @var string[] */
    public $errors = [];

    public function __construct(\Module $module)
    {
        $this->module = $module;
    }

    public function install(bool $resetConfig = false): bool
    {
        if (empty($this->module->id)) {
            $this->module->id = (int) \Module::getModuleIdByName($this->module->name);
        }

        return $this->tables() && $this->columns() && $this->config($resetConfig) && $this->providerHook() && $this->hooks() && $this->tabs();
    }

    public function hooks(): bool
    {
        foreach (self::HOOKS as $hook) {
            if (!$this->module->isRegisteredInHook($hook) && !$this->module->registerHook($hook)) {
                $this->errors[] = 'Could not register hook ' . $hook;

                return false;
            }
        }

        return true;
    }

    /**
     * Columns added after 1.0 (idempotent ALTERs).
     */
    public function columns(): bool
    {
        $p = _DB_PREFIX_;
        $add = [
            'ppmcp_key' => [
                'oauth_client' => "ADD `oauth_client` VARCHAR(64) NULL DEFAULT NULL, ADD KEY `oauth_client` (`oauth_client`)",
                'refresh_hash' => "ADD `refresh_hash` CHAR(64) NULL DEFAULT NULL, ADD UNIQUE KEY `refresh_hash` (`refresh_hash`)",
                'token_expire' => 'ADD `token_expire` DATETIME NULL DEFAULT NULL',
            ],
        ];
        foreach ($add as $table => $cols) {
            $existing = array_column(\Db::getInstance()->executeS('SHOW COLUMNS FROM `' . $p . $table . '`') ?: [], 'Field');
            foreach ($cols as $col => $ddl) {
                if (!in_array($col, $existing, true) && !\Db::getInstance()->execute('ALTER TABLE `' . $p . $table . '` ' . $ddl)) {
                    $this->errors[] = 'SQL error: ' . \Db::getInstance()->getMsgError();

                    return false;
                }
            }
        }

        return true;
    }

    public function tables(): bool
    {
        $engine = _MYSQL_ENGINE_;
        $p = _DB_PREFIX_;
        $sql = [
            "CREATE TABLE IF NOT EXISTS `{$p}ppmcp_key` (
                `id_ppmcp_key` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(64) NOT NULL,
                `token_hash` CHAR(64) NOT NULL,
                `token_hint` VARCHAR(16) NOT NULL DEFAULT '',
                `id_employee` INT(10) UNSIGNED NOT NULL,
                `scopes` VARCHAR(64) NOT NULL DEFAULT 'read',
                `shops` VARCHAR(255) NULL DEFAULT NULL,
                `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
                `date_expire` DATETIME NULL DEFAULT NULL,
                `last_used` DATETIME NULL DEFAULT NULL,
                `last_ip` VARCHAR(45) NULL DEFAULT NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_ppmcp_key`),
                UNIQUE KEY `token_hash` (`token_hash`),
                KEY `id_employee` (`id_employee`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$p}ppmcp_log` (
                `id_ppmcp_log` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `request_id` VARCHAR(32) NOT NULL DEFAULT '',
                `id_ppmcp_key` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `id_employee` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `id_shop` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `id_lang` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `client` VARCHAR(128) NOT NULL DEFAULT '',
                `protocol_version` VARCHAR(16) NOT NULL DEFAULT '',
                `rpc_method` VARCHAR(64) NOT NULL DEFAULT '',
                `tool` VARCHAR(128) NOT NULL DEFAULT '',
                `owner` VARCHAR(64) NOT NULL DEFAULT '',
                `is_write` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `dry_run` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `status` VARCHAR(16) NOT NULL DEFAULT 'ok',
                `error` TEXT NULL,
                `arguments` MEDIUMTEXT NULL,
                `result` MEDIUMTEXT NULL,
                `changes` MEDIUMTEXT NULL,
                `changes_count` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `duration_ms` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `ip` VARCHAR(45) NOT NULL DEFAULT '',
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_ppmcp_log`),
                KEY `date_add` (`date_add`),
                KEY `tool` (`tool`),
                KEY `status` (`status`),
                KEY `id_ppmcp_key` (`id_ppmcp_key`),
                KEY `request_id` (`request_id`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$p}ppmcp_oauth_client` (
                `id_ppmcp_oauth_client` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `client_id` VARCHAR(64) NOT NULL,
                `secret_hash` CHAR(64) NULL DEFAULT NULL,
                `auth_method` VARCHAR(32) NOT NULL DEFAULT 'none',
                `client_name` VARCHAR(128) NOT NULL DEFAULT '',
                `redirect_uris` TEXT NOT NULL,
                `ip` VARCHAR(45) NOT NULL DEFAULT '',
                `date_add` DATETIME NOT NULL,
                `last_used` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`id_ppmcp_oauth_client`),
                UNIQUE KEY `client_id` (`client_id`),
                KEY `ip_date` (`ip`, `date_add`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$p}ppmcp_oauth_request` (
                `id_ppmcp_oauth_request` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `request_key` CHAR(32) NOT NULL,
                `browser_hash` CHAR(64) NOT NULL,
                `user_code` CHAR(9) NOT NULL,
                `client_id` VARCHAR(64) NOT NULL,
                `redirect_uri` TEXT NOT NULL,
                `state` TEXT NULL,
                `code_challenge` VARCHAR(128) NOT NULL,
                `scope` VARCHAR(255) NOT NULL DEFAULT '',
                `resource` VARCHAR(500) NOT NULL DEFAULT '',
                `issuer` VARCHAR(255) NOT NULL DEFAULT '',
                `id_shop` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `ip` VARCHAR(45) NOT NULL DEFAULT '',
                `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
                `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
                `id_employee` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `id_ppmcp_key` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `code_hash` CHAR(64) NULL DEFAULT NULL,
                `code_expire` DATETIME NULL DEFAULT NULL,
                `date_add` DATETIME NOT NULL,
                `date_expire` DATETIME NOT NULL,
                PRIMARY KEY (`id_ppmcp_oauth_request`),
                UNIQUE KEY `request_key` (`request_key`),
                UNIQUE KEY `code_hash` (`code_hash`),
                KEY `user_code` (`user_code`, `status`),
                KEY `ip_status` (`ip`, `status`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($sql as $q) {
            if (!\Db::getInstance()->execute($q)) {
                $this->errors[] = 'SQL error: ' . \Db::getInstance()->getMsgError();

                return false;
            }
        }

        return true;
    }

    public function config(bool $reset): bool
    {
        foreach (self::DEFAULT_CONFIG as $key => $value) {
            if ($reset || \Configuration::getGlobalValue($key) === false) {
                if (!\Configuration::updateGlobalValue($key, $value)) {
                    $this->errors[] = 'Could not save configuration ' . $key;

                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Creates the hook other modules register on to provide tools.
     */
    public function providerHook(): bool
    {
        if (\Hook::getIdByName(ToolRegistry::PROVIDER_HOOK)) {
            return true;
        }
        $hook = new \Hook();
        $hook->name = ToolRegistry::PROVIDER_HOOK;
        $hook->title = 'PrestaPro MCP: register MCP tools';
        $hook->description = 'Modules return MCP tool definitions (see modules/ppmcp/docs/INTEGRATION.md).';
        $hook->position = 0;

        return (bool) $hook->add();
    }

    /**
     * No sidebar entry: the module is opened from the Module Manager
     * (Configure) and its pages link to each other with a tab bar. The admin
     * controllers are registered as hidden tabs (id_parent -1) so they stay
     * reachable and keep per-profile permissions. Earlier versions created a
     * "PrestaPro MCP" sidebar section: it is removed here.
     */
    public function tabs(): bool
    {
        $idParent = -1;
        foreach (self::TABS as $class => $def) {
            $existing = (int) \Tab::getIdFromClassName($class);
            if ($existing > 0) {
                $tab = new \Tab($existing);
                if ((int) $tab->id_parent !== $idParent) {
                    $tab->id_parent = $idParent;
                    $tab->update();
                }
                continue;
            }
            $tab = $this->newTab($class, $def[0], $idParent, $def[1]);
            if (!$tab->add()) {
                $this->errors[] = 'Could not create admin tab ' . $class;

                return false;
            }
        }
        $oldParent = (int) \Tab::getIdFromClassName(self::PARENT_TAB);
        if ($oldParent > 0) {
            $tab = new \Tab($oldParent);
            $tab->delete();
        }

        return true;
    }

    private function newTab(string $class, string $name, int $idParent, string $icon): \Tab
    {
        $tab = new \Tab();
        $tab->active = 1;
        $tab->class_name = $class;
        $tab->module = $this->module->name;
        $tab->id_parent = $idParent;
        $tab->icon = $icon;
        if (property_exists($tab, 'wording')) {
            $tab->wording = $name;
            $tab->wording_domain = 'Modules.Ppmcp.Admin';
        }
        $tab->name = [];
        foreach (\Language::getLanguages(false) as $lang) {
            $tab->name[(int) $lang['id_lang']] = $name;
        }

        return $tab;
    }
}
