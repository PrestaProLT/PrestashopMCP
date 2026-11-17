<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Tool;

use PrestaPro\PpMcp\Context\ExecutionContext;

/**
 * Collects every tool the server exposes:
 *  - core groups shipped with ppmcp (src/Tool/Core/*Tools.php)
 *  - tools of other modules hooked on actionPpMcpRegisterTools
 *    (see integration/PpMcpToolProviderTrait.php)
 * Tool names are unique; a module can never shadow a core tool because its
 * tools are always prefixed with the module's technical name and core
 * domain names are reserved.
 */
class ToolRegistry
{
    const PROVIDER_HOOK = 'actionPpMcpRegisterTools';

    /** Core domains. A module with one of these names cannot register tools. */
    const CORE_GROUPS = [
        'shop' => 'PrestaPro\\PpMcp\\Tool\\Core\\ShopTools',
        'catalog' => 'PrestaPro\\PpMcp\\Tool\\Core\\CatalogTools',
        'order' => 'PrestaPro\\PpMcp\\Tool\\Core\\OrderTools',
        'customer' => 'PrestaPro\\PpMcp\\Tool\\Core\\CustomerTools',
        'cart' => 'PrestaPro\\PpMcp\\Tool\\Core\\CartTools',
        'diagnose' => 'PrestaPro\\PpMcp\\Tool\\Core\\DiagnoseTools',
        'module' => 'PrestaPro\\PpMcp\\Tool\\Core\\ModuleTools',
        'mcp' => 'PrestaPro\\PpMcp\\Tool\\Core\\McpTools',
    ];

    /** @var \Module */
    private $module;
    /** @var ToolDefinition[]|null */
    private $tools;
    /** @var array module name => skill files */
    private $moduleSkills = [];
    /** @var array diagnostics about providers that failed to load */
    private $providerErrors = [];

    public function __construct(\Module $module)
    {
        $this->module = $module;
    }

    /**
     * @return ToolDefinition[] keyed by name, sorted (deterministic tools/list)
     */
    public function all(bool $includeDisabled = false): array
    {
        if ($this->tools === null) {
            $this->tools = [];
            $this->loadCore();
            $this->loadModuleProviders();
            ksort($this->tools);
        }
        if ($includeDisabled) {
            return $this->tools;
        }
        $disabled = self::disabledTools();

        return array_diff_key($this->tools, array_flip($disabled));
    }

    public function get(string $name): ?ToolDefinition
    {
        $all = $this->all();

        return isset($all[$name]) ? $all[$name] : null;
    }

    public function getProviderErrors(): array
    {
        $this->all();

        return $this->providerErrors;
    }

    /**
     * @return array module name => list of skill markdown paths
     */
    public function getModuleSkills(): array
    {
        $this->all();

        return $this->moduleSkills;
    }

    public static function disabledTools(): array
    {
        $raw = \Configuration::getGlobalValue('PPMCP_DISABLED_TOOLS');
        $list = $raw ? json_decode($raw, true) : [];

        return is_array($list) ? array_values($list) : [];
    }

    private function loadCore(): void
    {
        foreach (self::CORE_GROUPS as $owner => $class) {
            if (!class_exists($class)) {
                continue;
            }
            /** @var AbstractToolGroup $group */
            $group = new $class($this->module);
            foreach ($group->tools() as $def) {
                $method = isset($def['handler']) ? $def['handler'] : '';
                if (!method_exists($group, $method)) {
                    $this->providerErrors[] = sprintf('Core tool %s: handler %s missing', isset($def['name']) ? $def['name'] : '?', $method);
                    continue;
                }
                $tool = ToolDefinition::fromArray($def, $owner, [$group, $method], false);
                $this->tools[$tool->name] = $tool;
            }
        }
    }

    /**
     * Modules hooked on actionPpMcpRegisterTools, active in the current shop.
     * Queried directly (instead of Hook::exec) so front-office group
     * restrictions and hook exceptions never hide a provider.
     */
    private function loadModuleProviders(): void
    {
        $idShop = (int) \Context::getContext()->shop->id;
        $rows = \Db::getInstance()->executeS(
            'SELECT DISTINCT m.`name`
             FROM `' . _DB_PREFIX_ . 'hook` h
             INNER JOIN `' . _DB_PREFIX_ . 'hook_module` hm ON hm.`id_hook` = h.`id_hook`' . ($idShop ? ' AND hm.`id_shop` = ' . $idShop : '') . '
             INNER JOIN `' . _DB_PREFIX_ . 'module` m ON m.`id_module` = hm.`id_module` AND m.`active` = 1
             ' . ($idShop ? 'INNER JOIN `' . _DB_PREFIX_ . 'module_shop` ms ON ms.`id_module` = m.`id_module` AND ms.`id_shop` = ' . $idShop : '') . '
             WHERE h.`name` = \'' . pSQL(self::PROVIDER_HOOK) . '\'
             ORDER BY m.`name`'
        );
        if (!$rows) {
            return;
        }

        foreach ($rows as $row) {
            $name = (string) $row['name'];
            if ($name === $this->module->name) {
                continue;
            }
            if (isset(self::CORE_GROUPS[$name])) {
                $this->providerErrors[] = sprintf('Module "%s" uses a reserved core prefix; its tools are ignored.', $name);
                continue;
            }
            try {
                $instance = \Module::getInstanceByName($name);
                if (!$instance || !method_exists($instance, 'hookActionPpMcpRegisterTools')) {
                    $this->providerErrors[] = sprintf('Module "%s" is hooked but has no hookActionPpMcpRegisterTools().', $name);
                    continue;
                }
                $payload = $instance->hookActionPpMcpRegisterTools(['ppmcp_version' => $this->module->version]);
                $this->registerModulePayload($instance, is_array($payload) ? $payload : []);
            } catch (\Throwable $e) {
                $this->providerErrors[] = sprintf('Module "%s" failed to register tools: %s', $name, $e->getMessage());
            }
        }
    }

    private function registerModulePayload(\Module $instance, array $payload): void
    {
        $owner = strtolower($instance->name);
        $defs = isset($payload['tools']) && is_array($payload['tools']) ? $payload['tools'] : [];
        foreach ($defs as $def) {
            if (!is_array($def) || empty($def['name'])) {
                continue;
            }
            $local = (string) $def['name'];
            $def['name'] = strpos($local, $owner . '_') === 0 ? $local : $owner . '_' . $local;
            $handler = isset($def['handler']) ? $def['handler'] : null;
            if (is_string($handler) && method_exists($instance, $handler)) {
                $callable = [$instance, $handler];
            } elseif (is_callable($handler)) {
                $callable = $handler;
            } else {
                $this->providerErrors[] = sprintf('Module tool %s: handler is not a public method of %s.', $def['name'], get_class($instance));
                continue;
            }
            $adapter = function (array $args, ExecutionContext $ctx) use ($callable) {
                return call_user_func($callable, $args, $ctx->toArray());
            };
            try {
                $tool = ToolDefinition::fromArray($def, $owner, $adapter, true);
            } catch (\InvalidArgumentException $e) {
                $this->providerErrors[] = $e->getMessage();
                continue;
            }
            if (isset($this->tools[$tool->name])) {
                $this->providerErrors[] = sprintf('Duplicate MCP tool name %s ignored.', $tool->name);
                continue;
            }
            $this->tools[$tool->name] = $tool;
        }
        if (!empty($payload['skills']) && is_array($payload['skills'])) {
            $this->moduleSkills[$owner] = array_values(array_filter($payload['skills'], 'is_string'));
        }
    }
}
