<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Tool\Core;

use PrestaPro\PpMcp\Context\ExecutionContext;
use PrestaPro\PpMcp\Mcp\Runtime;
use PrestaPro\PpMcp\Tool\AbstractToolGroup;
use PrestaPro\PpMcp\Tool\ToolDefinition;
use PrestaPro\PpMcp\Tool\ToolException;
use PrestaPro\PpMcp\Tool\ToolRegistry;

/**
 * Meta tools: find tools, read skills (playbooks), audit the MCP activity log.
 */
class McpTools extends AbstractToolGroup
{
    public function owner(): string
    {
        return 'mcp';
    }

    public function tools(): array
    {
        return [
            [
                'name' => 'mcp_whoami',
                'title' => 'Who am I (API key, employee, rights)',
                'description' => 'Shows the API key in use, its scopes (read/write/code) and shop restriction, the linked employee and profile, and whether the server is read-only. Call it when a tool is denied, to explain why.',
                'handler' => 'whoami',
            ],
            [
                'name' => 'mcp_tool_search',
                'title' => 'Find the right tool for a task',
                'description' => 'Search all tools available to this key (core and module tools) by intent, e.g. "change slider on mobile", "refund order", "stock of product". Returns matching tool names with owner and whether they write. With an empty query, lists every owner (core domain or module) and its tools — the tool hierarchy.',
                'params' => [
                    'query' => ['type' => 'string', 'description' => 'What you want to do, in plain words. Empty = list hierarchy.'],
                    'owner' => ['type' => 'string', 'description' => 'Optional: restrict to one owner (catalog, order… or a module technical name).'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 12],
                ],
                'keywords' => ['find', 'help', 'which tool', 'module tools'],
                'handler' => 'toolSearch',
            ],
            [
                'name' => 'mcp_skill_list',
                'title' => 'List skills (playbooks)',
                'description' => 'Lists the built-in and module-provided skills: step-by-step playbooks for recurring jobs (analysing a module, diagnosing why a customer cannot buy a product, safe bulk catalog edits, order investigation…). Call before multi-step work, then mcp_skill_get.',
                'handler' => 'skillList',
            ],
            [
                'name' => 'mcp_skill_get',
                'title' => 'Read a skill (playbook)',
                'description' => 'Returns the full markdown playbook of a skill listed by mcp_skill_list. Follow its steps; it tells what to check and what to ignore.',
                'params' => [
                    'name' => ['type' => 'string', 'required' => true, 'description' => 'Skill name from mcp_skill_list.'],
                ],
                'handler' => 'skillGet',
            ],
            [
                'name' => 'mcp_log_list',
                'title' => 'MCP activity log',
                'description' => 'Lists what was done through MCP: tool calls with key, employee, shop, status, duration and — for write tools — every changed field with before/after values. Use for "what did the assistant change yesterday", debugging failed calls and audits. Super admins see every entry; other employees see their own; shop-restricted keys see their shops only.',
                'params' => array_merge([
                    'tool' => ['type' => 'string', 'description' => 'Exact tool name or prefix (e.g. "catalog_").'],
                    'status' => ['type' => 'string', 'enum' => ['ok', 'error', 'denied']],
                    'writes_only' => self::boolParam('Only calls of write tools (real saves, not dry runs).', false),
                    'request_id' => ['type' => 'string', 'description' => 'Show one request (request_id is returned with every tool result).'],
                    'date_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD[ HH:MM:SS]'],
                    'date_to' => ['type' => 'string', 'description' => 'YYYY-MM-DD[ HH:MM:SS]'],
                    'with_details' => self::boolParam('Include arguments, result and changes JSON.', false),
                ], self::paginationParams(20, 100)),
                'permission' => ['AdminPpMcpLogs', 'view'],
                'handler' => 'logList',
            ],
        ];
    }

    public function whoami(array $args, ExecutionContext $ctx): array
    {
        $e = $ctx->employee;
        $profile = new \Profile((int) $e->id_profile, (int) $ctx->idLang);

        return [
            'api_key' => [
                'id' => (int) $ctx->apiKey->id,
                'name' => $ctx->apiKey->name,
                'scopes' => $ctx->apiKey->getScopes(),
                'shops' => $ctx->apiKey->getShopIds() ?: 'all',
                'expires' => $ctx->apiKey->date_expire ?: null,
            ],
            'employee' => [
                'id' => (int) $e->id,
                'name' => trim($e->firstname . ' ' . $e->lastname),
                'email' => $e->email,
                'profile' => $profile->name ?: (int) $e->id_profile,
                'super_admin' => (int) $e->id_profile === (int) _PS_ADMIN_PROFILE_,
            ],
            'server' => [
                'read_only' => (bool) \Configuration::getGlobalValue('PPMCP_READ_ONLY'),
                'module_version' => $this->module->version,
                'prestashop' => _PS_VERSION_,
                'protocol_version' => $ctx->protocolVersion,
                'client' => $ctx->clientName,
            ],
        ];
    }

    public function toolSearch(array $args, ExecutionContext $ctx): array
    {
        $tools = Runtime::$runner ? Runtime::$runner->visibleTools(Runtime::$registry->all()) : [];
        $owner = isset($args['owner']) ? strtolower(trim($args['owner'])) : '';
        if ($owner !== '') {
            $tools = array_filter($tools, function (ToolDefinition $t) use ($owner) {
                return $t->owner === $owner;
            });
        }
        $query = isset($args['query']) ? trim((string) $args['query']) : '';

        if ($query === '') {
            $tree = [];
            foreach ($tools as $t) {
                $key = $t->owner;
                if (!isset($tree[$key])) {
                    $tree[$key] = [
                        'owner' => $t->owner,
                        'type' => $t->isModuleTool ? 'module' : 'core',
                        'module' => $t->isModuleTool ? $this->moduleDisplayName($t->owner) : null,
                        'tools' => [],
                    ];
                }
                $tree[$key]['tools'][] = $t->name . ($t->write ? ' (write)' : '');
            }

            return ['naming' => '<owner>_<resource>_<action>; owner = core domain or module technical name', 'owners' => array_values($tree)];
        }

        $words = array_filter(preg_split('/[^a-z0-9]+/', strtolower($query)), function ($w) {
            return strlen($w) > 1;
        });
        $scored = [];
        foreach ($tools as $t) {
            $hay = strtolower($t->name . ' ' . str_replace('_', ' ', $t->name) . ' ' . $t->title . ' ' . implode(' ', $t->keywords) . ' ' . ($t->isModuleTool ? $this->moduleDisplayName($t->owner) : ''));
            $desc = strtolower($t->description);
            $score = 0;
            foreach ($words as $w) {
                $stem = strlen($w) > 4 ? substr($w, 0, -1) : $w;
                if (strpos($hay, $stem) !== false) {
                    $score += 3;
                } elseif (strpos($desc, $stem) !== false) {
                    ++$score;
                }
            }
            if ($score > 0) {
                $scored[] = ['score' => $score, 'tool' => $t];
            }
        }
        usort($scored, function ($a, $b) {
            return $b['score'] === $a['score'] ? strcmp($a['tool']->name, $b['tool']->name) : $b['score'] - $a['score'];
        });
        $limit = isset($args['limit']) ? (int) $args['limit'] : 12;
        $out = [];
        foreach (array_slice($scored, 0, $limit) as $row) {
            $t = $row['tool'];
            $out[] = [
                'name' => $t->name,
                'title' => $t->title,
                'owner' => $t->owner,
                'owner_type' => $t->isModuleTool ? 'module' : 'core',
                'write' => $t->write,
                'summary' => self::firstSentence($t->description),
            ];
        }

        return [
            'query' => $query,
            'matches' => $out,
            'tip' => $out ? 'Call the tool directly; its inputSchema lists the arguments.' : 'No match. Call mcp_tool_search with an empty query to browse all owners, or module_list to see whether a module manages this feature without MCP tools.',
        ];
    }

    public function skillList(array $args, ExecutionContext $ctx): array
    {
        $out = [];
        foreach (Runtime::$skills->all() as $s) {
            $out[] = [
                'name' => $s['name'],
                'title' => $s['title'],
                'description' => $s['description'],
                'when_to_use' => $s['when_to_use'],
                'owner' => $s['owner'],
            ];
        }

        return ['skills' => $out];
    }

    public function skillGet(array $args, ExecutionContext $ctx): array
    {
        $skill = Runtime::$skills->get((string) $args['name']);
        if (!$skill) {
            throw new ToolException('Unknown skill "' . $args['name'] . '".', 'Call mcp_skill_list for the available names.');
        }

        return ['name' => $skill['name'], 'title' => $skill['title'], 'owner' => $skill['owner'], 'content' => $skill['content']];
    }

    public function logList(array $args, ExecutionContext $ctx): array
    {
        $where = ['1'];
        // Visibility: shop-restricted keys only see their shops; employees who
        // are not super admins only see entries made under their own identity.
        if ($ctx->apiKey->getShopIds()) {
            $where[] = '`id_shop` IN (' . implode(',', array_map('intval', $ctx->apiKey->getShopIds())) . ')';
        }
        if ((int) $ctx->employee->id_profile !== (int) _PS_ADMIN_PROFILE_) {
            $where[] = '`id_employee` = ' . (int) $ctx->employee->id;
        }
        if (!empty($args['tool'])) {
            $where[] = '`tool` LIKE \'' . self::likeValue((string) $args['tool']) . '%\'';
        }
        if (!empty($args['status'])) {
            $where[] = '`status` = \'' . pSQL($args['status']) . '\'';
        }
        if (!empty($args['writes_only'])) {
            $where[] = '`is_write` = 1 AND `dry_run` = 0';
        }
        if (!empty($args['request_id'])) {
            $where[] = '`request_id` = \'' . pSQL($args['request_id']) . '\'';
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $k => $op) {
            if (!empty($args[$k])) {
                $ts = strtotime($args[$k]);
                if ($ts === false) {
                    throw new ToolException($k . ' is not a valid date.');
                }
                $where[] = '`date_add` ' . $op . ' \'' . pSQL(date($k === 'date_to' && strlen($args[$k]) <= 10 ? 'Y-m-d 23:59:59' : 'Y-m-d H:i:s', $ts)) . '\'';
            }
        }
        $w = implode(' AND ', $where);
        $table = '`' . _DB_PREFIX_ . 'ppmcp_log`';
        $total = (int) $this->db()->getValue('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $w);
        $cols = '`id_ppmcp_log`, `request_id`, `date_add`, `tool`, `rpc_method`, `owner`, `status`, `error`, `is_write`, `dry_run`, `changes_count`, `id_ppmcp_key`, `id_employee`, `id_shop`, `client`, `duration_ms`';
        if (!empty($args['with_details'])) {
            $cols .= ', `arguments`, `result`, `changes`';
        }
        $rows = $this->db()->executeS(
            'SELECT ' . $cols . ' FROM ' . $table . ' WHERE ' . $w . ' ORDER BY `id_ppmcp_log` DESC LIMIT ' . (int) $args['offset'] . ', ' . (int) $args['limit']
        ) ?: [];
        foreach ($rows as &$r) {
            foreach (['arguments', 'result', 'changes'] as $j) {
                if (isset($r[$j])) {
                    $r[$j] = $r[$j] !== '' ? json_decode($r[$j], true) : null;
                }
            }
            foreach (['id_ppmcp_log', 'is_write', 'dry_run', 'changes_count', 'id_ppmcp_key', 'id_employee', 'id_shop', 'duration_ms'] as $i) {
                $r[$i] = (int) $r[$i];
            }
        }

        return $this->paged($rows, $total, $args, 'entries');
    }

    private function moduleDisplayName(string $name): string
    {
        static $cache = [];
        if (!isset($cache[$name])) {
            $m = \Module::getInstanceByName($name);
            $cache[$name] = $m ? (string) $m->displayName : $name;
        }

        return $cache[$name];
    }

    private static function firstSentence(string $s): string
    {
        $pos = strpos($s, '. ');

        return $pos !== false ? substr($s, 0, $pos + 1) : $s;
    }
}
