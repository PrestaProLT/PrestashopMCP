<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Install;

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
    ];

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

        return $this->tables() && $this->config($resetConfig) && $this->providerHook() && $this->tabs();
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
     * Self-contained menu: one "PrestaPro MCP" section owning all pages.
     * Existing tabs are re-parented (repairs earlier installs).
     */
    public function tabs(): bool
    {
        $idParent = (int) \Tab::getIdFromClassName(self::PARENT_TAB);
        if ($idParent <= 0) {
            $parent = $this->newTab(self::PARENT_TAB, 'PrestaPro MCP', 0, 'hub');
            if (!$parent->add()) {
                $this->errors[] = 'Could not create admin tab ' . self::PARENT_TAB;

                return false;
            }
            $idParent = (int) $parent->id;
        }
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
