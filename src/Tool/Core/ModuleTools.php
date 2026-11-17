<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Tool\Core;

use PrestaPro\PpMcp\Context\ExecutionContext;
use PrestaPro\PpMcp\Log\ActionLogger;
use PrestaPro\PpMcp\Tool\AbstractToolGroup;
use PrestaPro\PpMcp\Tool\ToolException;
use PrestaPro\PpMcp\Tool\ToolRegistry;

/**
 * Module introspection: what is installed, how a module is wired into the
 * shop (hooks, tables, configuration, controllers, overrides) and read-only
 * access to its source files so an assistant can explain how it works.
 *
 * Every path is resolved with realpath() and must stay inside
 * modules/<name>/ (symlinks pointing elsewhere are refused). Secret-bearing
 * files are never read or searched.
 */
class ModuleTools extends AbstractToolGroup
{
    /** Never descended into when scanning or listing. */
    const VCS_DIRS = ['.git', '.svn', '.hg', '.idea', '.vscode', '.github', '.gitlab'];

    /** Third-party code: skipped unless include_vendor. */
    const VENDOR_DIRS = ['vendor', 'node_modules', 'bower_components'];

    /** Listed but not scanned (content is noise for code analysis). */
    /** Data files never readable through module_file_read. */
    const DATA_EXT = ['log', 'csv', 'tsv', 'xls', 'xlsx', 'ods', 'dump', 'bak', 'old', 'gz', 'zip', 'tar', 'tgz', '7z', 'rar'];
    /** Path segments holding runtime data. */
    const DATA_DIR_PATTERN = '#/(upload|uploads|cache|logs?|exports?|imports?|tmp|temp|backups?|var)/#'; // lower-case only: PSR-4 dirs like src/Cache/ stay readable

    const NOISE_DIRS = ['translations', 'mails', 'upload', 'uploads', 'cache', 'img', 'images', 'fonts'];

    const BINARY_EXT = [
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'bmp', 'tif', 'tiff', 'psd', 'ai', 'eps', 'pdf',
        'zip', 'gz', 'tgz', 'bz2', 'xz', 'rar', '7z', 'tar', 'jar', 'phar', 'woff', 'woff2', 'ttf', 'otf', 'eot',
        'mp3', 'mp4', 'webm', 'ogg', 'wav', 'avi', 'mov', 'swf', 'exe', 'dll', 'so', 'dylib', 'bin', 'dat',
        'db', 'sqlite', 'mo', 'xlsx', 'xls', 'docx', 'doc', 'odt', 'ods', 'pptx', 'class', 'pyc',
    ];

    /** Extensions searched / scanned as code. */
    const TEXT_EXT = [
        'php', 'phtml', 'inc', 'tpl', 'twig', 'html', 'htm', 'js', 'mjs', 'ts', 'tsx', 'jsx', 'vue', 'css', 'scss',
        'less', 'sass', 'yml', 'yaml', 'xml', 'json', 'md', 'txt', 'sql', 'ini', 'neon', 'sh', 'dist', 'graphql',
        'htaccess', 'conf', 'rst', 'csv', 'xlf', 'svg', 'map', 'lock', 'env', 'po', 'pot',
    ];

    /** Text but not worth scanning for code facts. */
    const SCAN_SKIP_EXT = ['svg', 'map', 'lock', 'csv', 'xlf', 'po', 'pot'];

    /** Explain/search limits. */
    const SCAN_MAX_FILES = 5000;
    const SCAN_MAX_BYTES = 40000000;
    const SCAN_FILE_MAX = 1500000;
    const SEARCH_SECONDS = 10;

    /** Core parents: hook methods declared there are not the module's own. */
    const CORE_CLASSES = ['module', 'modulecore', 'paymentmodule', 'paymentmodulecore', 'carriermodule', 'carriermodulecore'];

    /** @var array|null installed override members, per request */
    private $overrideCache;
    /** @var array|null lowercase alias => canonical hook name */
    private $aliasCache;

    public function owner(): string
    {
        return 'module';
    }

    public function tools(): array
    {
        $perms = [['AdminModulesSf', 'view'], ['AdminModules', 'view']];
        $moduleParam = ['type' => 'string', 'required' => true, 'maxLength' => 64, 'description' => 'Technical module name (directory name), e.g. "ps_emailsubscription". module_list finds it.'];

        return [
            [
                'name' => 'module_list',
                'title' => 'List modules',
                'description' => 'Lists modules on disk and/or installed, cheaply (no module is instantiated). Per row: technical name, display name, version on disk vs version in DB (upgrade_pending), author, tab, installed, active in this shop, enabled devices, number of hooks, whether it ships overrides and whether it provides MCP tools. Filter by installed/active, free text (name, display name, author), tab, has_overrides, provides_mcp_tools. Next: module_explain for one module.',
                'params' => array_merge([
                    'installed' => self::boolParam('true = only installed, false = only not installed (on disk only).'),
                    'active' => self::boolParam('true = only modules active in this shop, false = only inactive/disabled here.'),
                    'search' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Text in technical name, display name or author.'],
                    'tab' => ['type' => 'string', 'maxLength' => 64, 'description' => 'Module category tab, e.g. payments_gateways, front_office_features, administration.'],
                    'has_overrides' => self::boolParam('Only modules shipping class/controller overrides (modules/<name>/override).'),
                    'provides_mcp_tools' => self::boolParam('Only modules that provide MCP tools to this server.'),
                ], self::paginationParams(50, 500)),
                'shop' => true,
                'permissions' => $perms,
                'keywords' => ['addon', 'plugin', 'extension', 'installed', 'version', 'upgrade'],
                'handler' => 'moduleList',
            ],
            [
                'name' => 'module_explain',
                'title' => 'Explain how a module works',
                'description' => 'Structured analysis of ONE module, for explaining what it does and how: identity from the constructor (version, author, tab, parent class such as PaymentModule/CarrierModule, compliancy, dependencies), install state per shop, hooks registered in DB (position, exceptions) vs hook methods in code (mismatches flagged), widget support, DB tables (sql/*.sql, CREATE TABLE, ObjectModel definitions) with existence and row counts, configuration keys with current values (secrets masked), front controllers with URLs, admin controllers and tabs, Symfony routes/services, overrides, theme template overrides, templates, upgrade scripts, composer requirements, cron/CLI entry points, MCP tools, documentation (README/CHANGELOG/docs content) and classification hints. Output is budgeted (max_chars); the "truncated" list says what was cut. Then read code with module_file_read / module_code_search.',
                'params' => [
                    'module' => $moduleParam,
                    'max_chars' => ['type' => 'integer', 'minimum' => 4000, 'maximum' => 80000, 'default' => 20000, 'description' => 'Output budget in characters (documentation is cut first).'],
                    'include_docs' => self::boolParam('Include README/CHANGELOG/docs content.', true),
                    'instantiate' => self::boolParam('Load the module class (more exact: inherited hook methods, widget interface, dependencies). false = static parsing only.', true),
                ],
                'shop' => true,
                'lang' => true,
                'scope' => 'code',
                'permissions' => $perms,
                'keywords' => ['analyze', 'analyse', 'audit', 'how does', 'readme', 'documentation', 'hooks', 'tables', 'classification'],
                'handler' => 'moduleExplain',
            ],
            [
                'name' => 'module_file_list',
                'title' => 'Module directory tree',
                'description' => 'Directory tree of modules/<name>/ (or a subpath) with file sizes, depth-limited. vendor/ and node_modules/ are summarised unless include_vendor; .git is never listed. Next: module_file_read.',
                'params' => [
                    'module' => $moduleParam,
                    'path' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Subdirectory relative to the module root, e.g. "controllers/front".'],
                    'depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'default' => 3, 'description' => 'How many directory levels to descend.'],
                    'include_vendor' => self::boolParam('Descend into vendor/ and node_modules/.', false),
                    'max_entries' => ['type' => 'integer', 'minimum' => 10, 'maximum' => 3000, 'default' => 600, 'description' => 'Stop after this many entries.'],
                ],
                'scope' => 'code',
                'permissions' => $perms,
                'keywords' => ['tree', 'files', 'directory', 'ls', 'structure'],
                'handler' => 'fileList',
            ],
            [
                'name' => 'module_file_read',
                'title' => 'Read a module file',
                'description' => 'Reads one text file inside modules/<name>/ with line numbers, optionally a line range. Output is capped by the PPMCP_MAX_FILE_BYTES setting; when cut, next_start_line tells where to continue. Binary files, paths leaving the module directory (.., absolute, symlinks) and secret-bearing files (.env, keys, certificates, parameters.php, credentials) are refused.',
                'params' => [
                    'module' => $moduleParam,
                    'path' => ['type' => 'string', 'required' => true, 'maxLength' => 255, 'description' => 'File path relative to the module root, e.g. "ps_emailsubscription.php" or "controllers/front/verification.php".'],
                    'start_line' => ['type' => 'integer', 'minimum' => 1, 'default' => 1, 'description' => 'First line to return.'],
                    'end_line' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Last line to return (inclusive). Omit to read as much as the cap allows.'],
                ],
                'scope' => 'code',
                'permissions' => $perms,
                'keywords' => ['source', 'code', 'cat', 'open file', 'view'],
                'handler' => 'fileRead',
            ],
            [
                'name' => 'module_code_search',
                'title' => 'Search module source code',
                'description' => 'Text or regex search in one module, or in all modules when "module" is omitted (then file_glob is required, e.g. "*.php"). Returns file:line:snippet. vendor/, node_modules/, translations, binaries, minified files and secret files are skipped by default. Time and size limited; "complete" tells whether everything was searched. Use to find where a hook, class, configuration key or SQL table is used.',
                'params' => [
                    'query' => ['type' => 'string', 'required' => true, 'minLength' => 2, 'maxLength' => 300, 'description' => 'Text (case-insensitive by default) or PCRE pattern without delimiters when regex=true.'],
                    'module' => ['type' => 'string', 'maxLength' => 64, 'description' => 'Technical module name. Omit to search every module (file_glob required).'],
                    'regex' => self::boolParam('Treat query as a regular expression.', false),
                    'case_sensitive' => self::boolParam('Case-sensitive match.', false),
                    'file_glob' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Filename or relative path pattern, e.g. "*.php", "*.tpl", "controllers/*".'],
                    'path' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Only search below this subdirectory of the module.'],
                    'include_vendor' => self::boolParam('Also search vendor/ and node_modules/.', false),
                    'max_results' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 60, 'description' => 'Stop after this many matching lines.'],
                ],
                'scope' => 'code',
                'permissions' => $perms,
                'keywords' => ['grep', 'find', 'usage', 'where is', 'occurrence'],
                'handler' => 'codeSearch',
            ],
            [
                'name' => 'module_hook_list',
                'title' => 'Hooks of a module / modules on a hook',
                'description' => 'Give "module" to list the hooks it is attached to in this shop (position, how many modules share the hook, page exceptions, whether the code implements it). Give "hook" (e.g. displayHome, aliases resolved) to list the modules attached to it in execution order — "who renders on displayHome?" — with active state, devices, exceptions and whether each implements the hook or renders through a widget.',
                'params' => [
                    'module' => ['type' => 'string', 'maxLength' => 64, 'description' => 'Technical module name.'],
                    'hook' => ['type' => 'string', 'maxLength' => 191, 'description' => 'Hook name, e.g. displayHome, actionValidateOrder.'],
                ],
                'shop' => true,
                'permissions' => $perms,
                'keywords' => ['position', 'transplant', 'display hook', 'action hook', 'exceptions'],
                'handler' => 'hookList',
            ],
            [
                'name' => 'module_override_list',
                'title' => 'Installed class/controller overrides',
                'description' => 'Lists files in the shop override/ directory (classes, controllers, module overrides) with the class they override, each method/property and the module that installed it (from the "* module: NAME" markers; members without a marker were added by hand), whether the class index cache knows the override, and whether overrides are disabled (PS_DISABLE_OVERRIDES). Filter by module to see what one module overrides and whether its shipped overrides are installed.',
                'params' => [
                    'module' => ['type' => 'string', 'maxLength' => 64, 'description' => 'Only overrides owned or shipped by this module.'],
                ],
                'scope' => 'code',
                'permissions' => $perms,
                'keywords' => ['override', 'class_index', 'conflict'],
                'handler' => 'overrideList',
            ],
        ];
    }

    // ------------------------------------------------------------------
    // module_list
    // ------------------------------------------------------------------

    public function moduleList(array $args, ExecutionContext $ctx): array
    {
        $idShop = (int) $ctx->idShop;
        $p = _DB_PREFIX_;
        $db = [];
        foreach ($this->db()->executeS(
            'SELECT m.`id_module`, m.`name`, m.`active`, m.`version`, ms.`id_module` AS in_shop, ms.`enable_device`
             FROM `' . $p . 'module` m
             LEFT JOIN `' . $p . 'module_shop` ms ON ms.`id_module` = m.`id_module` AND ms.`id_shop` = ' . $idShop
        ) ?: [] as $r) {
            $db[strtolower($r['name'])] = $r;
        }
        $hookCounts = [];
        foreach ($this->db()->executeS(
            'SELECT `id_module`, COUNT(*) AS n FROM `' . $p . 'hook_module` WHERE `id_shop` = ' . $idShop . ' GROUP BY `id_module`'
        ) ?: [] as $r) {
            $hookCounts[(int) $r['id_module']] = (int) $r['n'];
        }
        $mcpProviders = [];
        foreach ($this->db()->executeS(
            'SELECT DISTINCT hm.`id_module` FROM `' . $p . 'hook_module` hm INNER JOIN `' . $p . 'hook` h ON h.`id_hook` = hm.`id_hook`
             WHERE h.`name` = \'' . pSQL(ToolRegistry::PROVIDER_HOOK) . '\''
        ) ?: [] as $r) {
            $mcpProviders[(int) $r['id_module']] = true;
        }

        $names = [];
        foreach ($this->diskModules() as $n) {
            $names[strtolower($n)] = $n;
        }
        foreach ($db as $k => $r) {
            if (!isset($names[$k])) {
                $names[$k] = $r['name'];
            }
        }
        ksort($names);

        $search = isset($args['search']) ? mb_strtolower(trim((string) $args['search'])) : '';
        $rows = [];
        foreach ($names as $key => $name) {
            $dbRow = isset($db[$key]) ? $db[$key] : null;
            $installed = $dbRow !== null;
            if (isset($args['installed']) && (bool) $args['installed'] !== $installed) {
                continue;
            }
            $activeInShop = $installed && (int) $dbRow['active'] === 1 && $dbRow['in_shop'] !== null;
            if (isset($args['active']) && (bool) $args['active'] !== $activeInShop) {
                continue;
            }
            $dir = _PS_MODULE_DIR_ . $name . '/';
            $onDisk = is_file($dir . $name . '.php');
            $info = $onDisk ? $this->parseMainFile($name, false) : [];
            $xml = $onDisk ? $this->parseConfigXml($name) : [];
            $displayName = !empty($xml['displayName']) ? $xml['displayName'] : (isset($info['displayName']) ? $info['displayName'] : null);
            $author = isset($info['author']) ? $info['author'] : (isset($xml['author']) ? $xml['author'] : null);
            $tab = isset($info['tab']) ? $info['tab'] : (isset($xml['tab']) ? $xml['tab'] : null);
            if ($search !== '') {
                $hay = mb_strtolower($name . ' ' . $displayName . ' ' . $author);
                if (mb_strpos($hay, $search) === false) {
                    continue;
                }
            }
            if (!empty($args['tab']) && strcasecmp((string) $tab, (string) $args['tab']) !== 0) {
                continue;
            }
            $hasOverrides = $onDisk && $this->dirHasPhp($dir . 'override', 4);
            if (isset($args['has_overrides']) && (bool) $args['has_overrides'] !== $hasOverrides) {
                continue;
            }
            $idModule = $installed ? (int) $dbRow['id_module'] : 0;
            $mcp = ($idModule && isset($mcpProviders[$idModule])) || $name === $this->module->name || !empty($info['mcp_trait']);
            if (isset($args['provides_mcp_tools']) && (bool) $args['provides_mcp_tools'] !== $mcp) {
                continue;
            }
            $diskVersion = isset($info['version']) ? $info['version'] : (isset($xml['version']) ? $xml['version'] : null);
            $row = [
                'name' => $name,
                'display_name' => $displayName,
                'version_disk' => $diskVersion,
                'version_db' => $installed ? $dbRow['version'] : null,
            ];
            if ($installed && $diskVersion !== null) {
                $cmp = $this->compareVersions($diskVersion, (string) $dbRow['version']);
                $row['upgrade_pending'] = $cmp['pending'];
                if ($cmp['truncated']) {
                    $row['version_db_truncated'] = true;
                }
            }
            $row['author'] = $author;
            $row['tab'] = $tab;
            $row['installed'] = $installed;
            if ($installed) {
                $row['active_global'] = (int) $dbRow['active'] === 1;
                $row['active_in_shop'] = $activeInShop;
                if ($dbRow['in_shop'] !== null && (int) $dbRow['enable_device'] !== 7) {
                    $row['devices'] = $this->devices((int) $dbRow['enable_device']);
                }
                $row['hooks'] = isset($hookCounts[$idModule]) ? $hookCounts[$idModule] : 0;
            }
            if (!$onDisk) {
                $row['on_disk'] = false;
            }
            if ($hasOverrides) {
                $row['has_overrides'] = true;
            }
            if ($mcp) {
                $row['mcp_tools'] = true;
            }
            $rows[] = $row;
        }

        $total = count($rows);
        $page = array_slice($rows, (int) $args['offset'], (int) $args['limit']);
        $out = $this->paged($page, $total, $args, 'modules');
        $out['id_shop'] = $idShop;
        $out['note'] = 'version_db is the `module`.`version` column (varchar(8): longer versions are truncated). upgrade_pending = disk version newer than DB version (run the upgrade from Module Manager).';

        return $out;
    }

    // ------------------------------------------------------------------
    // module_explain
    // ------------------------------------------------------------------

    public function moduleExplain(array $args, ExecutionContext $ctx): array
    {
        $name = $this->validName($args['module']);
        $root = $this->moduleRoot($name);
        $budget = (int) $args['max_chars'];
        $idShop = (int) $ctx->idShop;
        $idLang = (int) $ctx->idLang;
        $p = _DB_PREFIX_;
        $truncated = [];

        $info = $this->parseMainFile($name, true);
        $xml = $this->parseConfigXml($name);

        // Optional instance (exact hook methods, widget, dependencies).
        $instance = null;
        $instanceError = null;
        if (!empty($args['instantiate'])) {
            try {
                $instance = \Module::getInstanceByName($name);
                if (!$instance) {
                    $instanceError = 'Module::getInstanceByName returned false';
                }
            } catch (\Throwable $e) {
                $instanceError = get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 300);
                $instance = null;
            }
        }

        // ---- identity
        $identity = [
            'name' => $name,
            'display_name' => $instance ? (string) $instance->displayName : (!empty($xml['displayName']) ? $xml['displayName'] : (isset($info['displayName']) ? $info['displayName'] : null)),
            'description' => $instance ? (string) $instance->description : (!empty($xml['description']) ? $xml['description'] : (isset($info['description']) ? $info['description'] : null)),
            'version' => $instance ? (string) $instance->version : (isset($info['version']) ? $info['version'] : (isset($xml['version']) ? $xml['version'] : null)),
            'author' => $instance ? (string) $instance->author : (isset($info['author']) ? $info['author'] : null),
            'tab' => $instance ? (string) $instance->tab : (isset($info['tab']) ? $info['tab'] : null),
            'class' => $instance ? get_class($instance) : (isset($info['class']) ? $info['class'] : null),
            'parent_class' => isset($info['parent']) ? $info['parent'] : null,
            'parent_chain' => $instance ? $this->parentChain($instance) : null,
            'interfaces' => $instance ? array_values(array_filter(class_implements($instance), function ($i) {
                return stripos($i, 'Psr\\') !== 0;
            })) : (isset($info['interfaces']) ? $info['interfaces'] : []),
            'need_instance' => $instance ? (int) $instance->need_instance : (isset($info['need_instance']) ? $info['need_instance'] : null),
            'bootstrap' => $instance ? (bool) $instance->bootstrap : (isset($info['bootstrap']) ? $info['bootstrap'] : null),
            'ps_versions_compliancy' => $instance && is_array($instance->ps_versions_compliancy) ? $instance->ps_versions_compliancy : (isset($info['compliancy']) ? $info['compliancy'] : null),
            'dependencies' => $instance && is_array($instance->dependencies) ? array_values($instance->dependencies) : (isset($info['dependencies']) ? $info['dependencies'] : []),
            'is_configurable' => $instance ? method_exists($instance, 'getContent') : (isset($info['has_getContent']) ? $info['has_getContent'] : null),
            'main_file' => $name . '.php',
            'main_file_lines' => isset($info['lines']) ? $info['lines'] : null,
        ];
        if (isset($info['version'], $xml['version']) && $info['version'] !== $xml['version']) {
            $identity['config_xml_version'] = $xml['version'];
        }
        if ($instanceError) {
            $identity['instantiation_error'] = $instanceError;
        } elseif (empty($args['instantiate'])) {
            $identity['note'] = 'Static parsing only (instantiate=false).';
        }
        $isWidget = $instance
            ? $instance instanceof \PrestaShop\PrestaShop\Core\Module\WidgetInterface
            : !empty($info['widget']);

        // ---- install state
        $dbRow = $this->db()->getRow('SELECT `id_module`, `name`, `active`, `version` FROM `' . $p . 'module` WHERE `name` = \'' . pSQL($name) . '\'');
        $idModule = $dbRow ? (int) $dbRow['id_module'] : 0;
        $install = ['installed' => (bool) $dbRow];
        if ($dbRow) {
            $install['id_module'] = $idModule;
            $install['active_global'] = (int) $dbRow['active'] === 1;
            $install['version_db'] = $dbRow['version'];
            if ($identity['version'] !== null) {
                $cmp = $this->compareVersions((string) $identity['version'], (string) $dbRow['version']);
                $install['upgrade_pending'] = $cmp['pending'];
                if ($cmp['truncated']) {
                    $install['version_db_truncated'] = true;
                }
            }
            $shops = [];
            $shopRows = $this->db()->executeS('SELECT ms.`id_shop`, ms.`enable_device`, s.`name` FROM `' . $p . 'module_shop` ms LEFT JOIN `' . $p . 'shop` s ON s.`id_shop` = ms.`id_shop` WHERE ms.`id_module` = ' . $idModule) ?: [];
            $hooksPerShop = [];
            foreach ($this->db()->executeS('SELECT `id_shop`, COUNT(*) AS n FROM `' . $p . 'hook_module` WHERE `id_module` = ' . $idModule . ' GROUP BY `id_shop`') ?: [] as $r) {
                $hooksPerShop[(int) $r['id_shop']] = (int) $r['n'];
            }
            foreach ($shopRows as $r) {
                $sid = (int) $r['id_shop'];
                if (!$ctx->apiKey->allowsShop($sid)) {
                    continue;
                }
                $shops[] = ['id_shop' => $sid, 'name' => $r['name'], 'devices' => $this->devices((int) $r['enable_device']), 'hooks' => isset($hooksPerShop[$sid]) ? $hooksPerShop[$sid] : 0];
            }
            $install['enabled_shops'] = $shops;
            $install['active_in_current_shop'] = $install['active_global'] && in_array($idShop, array_column($shops, 'id_shop'), true);
            $restr = [];
            foreach (['module_country' => 'countries', 'module_currency' => 'currencies', 'module_group' => 'customer_groups', 'module_carrier' => 'carriers'] as $table => $label) {
                try {
                    $n = (int) $this->db()->getValue('SELECT COUNT(*) FROM `' . $p . $table . '` WHERE `id_module` = ' . $idModule . ' AND `id_shop` = ' . $idShop);
                } catch (\Throwable $e) {
                    $n = null;
                }
                if ($n) {
                    $restr[$label] = $n;
                }
            }
            if ($restr) {
                $install['restriction_rows_in_shop'] = $restr;
                $install['restriction_note'] = 'Rows in module_country/currency/group/carrier: payment modules are only offered where a row exists.';
            }
        }

        // ---- file walk (one pass)
        $walk = $this->walk($root, '', 25, false, self::SCAN_MAX_FILES, true);
        $files = $walk['entries'];
        $facts = $this->scanFacts($root, $files, $name);

        // ---- hooks
        $hooks = $this->explainHooks($name, $idModule, $idShop, $instance, $info, $isWidget);

        // ---- database
        $database = $this->explainTables($name, $facts);

        // ---- configuration
        $configuration = $this->explainConfig($name, $facts['config_keys'], $idShop, $idLang);

        // ---- controllers / tabs
        $front = [];
        foreach ($files as $f) {
            if (!$f['dir'] && preg_match('#^controllers/front/([A-Za-z0-9_]+)\.php$#', $f['path'], $m) && strtolower($m[1]) !== 'index') {
                $url = null;
                try {
                    $url = $this->context()->link->getModuleLink($name, $m[1], [], true, $idLang, $idShop);
                } catch (\Throwable $e) {
                    $url = null;
                }
                $front[] = ['controller' => $m[1], 'file' => $f['path'], 'url' => $url];
            }
        }
        $admin = [];
        foreach ($files as $f) {
            if (!$f['dir'] && preg_match('#^controllers/admin/([A-Za-z0-9_]+)\.php$#', $f['path'], $m) && strtolower($m[1]) !== 'index') {
                $admin[] = $f['path'];
            }
        }
        $tabs = [];
        try {
            foreach ($this->db()->executeS(
                'SELECT t.*, tl.`name` AS tab_label, pt.`class_name` AS parent_class_name
                 FROM `' . $p . 'tab` t
                 LEFT JOIN `' . $p . 'tab_lang` tl ON tl.`id_tab` = t.`id_tab` AND tl.`id_lang` = ' . $idLang . '
                 LEFT JOIN `' . $p . 'tab` pt ON pt.`id_tab` = t.`id_parent`
                 WHERE t.`module` = \'' . pSQL($name) . '\' ORDER BY t.`id_parent`, t.`position`'
            ) ?: [] as $t) {
                $row = ['class_name' => $t['class_name'], 'label' => $t['tab_label'], 'parent' => $t['parent_class_name'] ?: ((int) $t['id_parent'] === -1 ? '(hidden, id_parent -1)' : null), 'active' => (bool) $t['active']];
                if (!empty($t['route_name'])) {
                    $row['route_name'] = $t['route_name'];
                }
                if (isset($t['enabled']) && !(int) $t['enabled']) {
                    $row['enabled'] = false;
                }
                $tabs[] = $row;
            }
        } catch (\Throwable $e) {
            $tabs = [['error' => $e->getMessage()]];
        }
        $configureUrl = $identity['is_configurable'] ? 'index.php?controller=AdminModules&configure=' . $name . ' (BO, needs token)' : null;

        // ---- symfony
        $symfony = $this->explainSymfony($root, $files);

        // ---- overrides
        $shipped = [];
        foreach ($files as $f) {
            if (!$f['dir'] && strpos($f['path'], 'override/') === 0 && substr($f['path'], -4) === '.php' && basename($f['path']) !== 'index.php') {
                $installedFile = _PS_ROOT_DIR_ . '/' . $f['path'];
                $shipped[] = ['file' => $f['path'], 'present_in_shop' => is_file($installedFile)];
            }
        }
        $ownedMembers = [];
        foreach ($this->installedOverrides() as $of) {
            foreach ($of['members'] as $mem) {
                if ($mem['module'] !== null && strcasecmp($mem['module'], $name) === 0) {
                    $ownedMembers[] = $of['file'] . ' :: ' . $mem['member'] . ($mem['version'] ? ' (v' . $mem['version'] . ')' : '');
                }
            }
        }
        $overrides = null;
        if ($shipped || $ownedMembers) {
            $overrides = ['shipped' => $shipped, 'installed_members' => $ownedMembers];
            if ($shipped && !$ownedMembers && $dbRow) {
                $overrides['warning'] = 'Module ships overrides but no installed member carries its "* module:" marker — overrides may not be installed (or were merged manually).';
            }
            if ((int) \Configuration::get('PS_DISABLE_OVERRIDES')) {
                $overrides['note'] = 'PS_DISABLE_OVERRIDES = 1: overrides are not loaded.';
            }
        }

        // ---- theme template overrides
        $themeOverrides = $this->themeOverrides($name);

        // ---- templates & assets
        $templates = [];
        $assets = ['js' => 0, 'css' => 0];
        $extCount = [];
        $fileCount = 0;
        foreach ($files as $f) {
            if ($f['dir']) {
                continue;
            }
            ++$fileCount;
            $ext = strtolower(pathinfo($f['path'], PATHINFO_EXTENSION));
            $extCount[$ext] = isset($extCount[$ext]) ? $extCount[$ext] + 1 : 1;
            if (preg_match('#^views/templates/([^/]+)/#', $f['path'], $m) && in_array($ext, ['tpl', 'twig', 'html'], true)) {
                $templates[$m[1]] = isset($templates[$m[1]]) ? $templates[$m[1]] + 1 : 1;
            } elseif (preg_match('#^views/templates/[^/]+\.(tpl|twig)$#', $f['path'])) {
                $templates['.'] = isset($templates['.']) ? $templates['.'] + 1 : 1;
            } elseif (in_array($ext, ['tpl', 'twig'], true)) {
                $templates['(outside views/templates) ' . dirname($f['path'])] = (isset($templates['(outside views/templates) ' . dirname($f['path'])]) ? $templates['(outside views/templates) ' . dirname($f['path'])] : 0) + 1;
            }
            if ($ext === 'js' || $ext === 'mjs') {
                ++$assets['js'];
            } elseif (in_array($ext, ['css', 'scss', 'less'], true)) {
                ++$assets['css'];
            }
        }
        arsort($extCount);

        // ---- upgrade scripts
        $upgrades = [];
        foreach ($files as $f) {
            if (!$f['dir'] && preg_match('#^upgrade/(?:upgrade|install)-([0-9][0-9A-Za-z._-]*)\.php$#', $f['path'], $m)) {
                $upgrades[$m[1]] = $f['path'];
            }
        }
        uksort($upgrades, 'version_compare');
        $upgrade = null;
        if ($upgrades) {
            $upgrade = ['scripts' => array_keys($upgrades)];
            if ($dbRow) {
                $pending = [];
                foreach (array_keys($upgrades) as $v) {
                    if (version_compare($v, (string) $dbRow['version'], '>') && ($identity['version'] === null || version_compare($v, (string) $identity['version'], '<='))) {
                        $pending[] = $v;
                    }
                }
                $upgrade['pending'] = $pending;
            }
        }

        // ---- composer
        $composer = null;
        if (is_file($root . '/composer.json')) {
            $c = json_decode((string) @file_get_contents($root . '/composer.json', false, null, 0, 200000), true);
            if (is_array($c)) {
                $composer = array_filter([
                    'name' => isset($c['name']) ? $c['name'] : null,
                    'require' => isset($c['require']) ? $c['require'] : null,
                    'require_dev' => isset($c['require-dev']) ? array_keys($c['require-dev']) : null,
                    'autoload' => isset($c['autoload']) ? $c['autoload'] : null,
                ]);
                $composer['vendor_installed'] = is_dir($root . '/vendor');
            }
        }

        // ---- MCP tools
        $mcpTools = $this->providedMcpTools($name);

        // ---- docs
        $docFiles = [];
        foreach ($files as $f) {
            if ($f['dir']) {
                continue;
            }
            $base = basename($f['path']);
            $depth = substr_count($f['path'], '/');
            $isDoc = ($depth === 0 && preg_match('/^(readme|changelog|changes|history|upgrade|install|usage|manual|documentation|claude|agents)([._-][\w.-]*)?(\.(md|txt|rst|markdown))?$/i', $base) && !preg_match('/\.(php|json|xml|yml|html?)$/i', $base))
                || (preg_match('#^(docs?|documentation)/#i', $f['path']) && preg_match('/\.(md|txt|rst|markdown)$/i', $base) && $depth <= 2)
                || preg_match('#^(mcp/)?skills/[^/]+\.md$#', $f['path']);
            if ($isDoc && !preg_match('/^licen[cs]e/i', $base)) {
                $docFiles[] = $f;
            }
        }
        usort($docFiles, function ($a, $b) {
            $rank = function ($p) {
                if (preg_match('/^readme/i', $p)) {
                    return 0;
                }
                if (strpos($p, '/') === false && !preg_match('/^changelog|^changes|^history/i', $p)) {
                    return 1;
                }
                if (preg_match('#^docs?/#i', $p)) {
                    return 2;
                }
                if (preg_match('#^(mcp/)?skills/#', $p)) {
                    return 3;
                }

                return 4;
            };

            return [$rank($a['path']), $a['path']] <=> [$rank($b['path']), $b['path']];
        });

        $hints = $this->classify($name, $identity, $hooks, $front, $admin, $tabs, $isWidget, $database, $facts, $mcpTools, $info);

        $out = [
            'module' => $name,
            'identity' => $identity,
            'install' => $install,
            'classification_hints' => $hints,
            'hooks' => $hooks,
            'widget' => $isWidget ? ['implements_widget_interface' => true, 'note' => 'renderWidget()/getWidgetVariables() render it on any display hook without a hookXxx method, and via {widget name="' . $name . '"} in templates.'] : false,
            'database' => $database,
            'configuration' => $configuration,
            'controllers' => array_filter([
                'front' => $front,
                'admin' => $admin,
                'tabs' => $tabs,
                'configure_page' => $configureUrl,
            ]),
            'symfony' => $symfony,
            'overrides' => $overrides,
            'theme_template_overrides' => $themeOverrides,
            'templates' => $templates,
            'assets' => $assets,
            'upgrade' => $upgrade,
            'composer' => $composer,
            'entry_points' => $facts['entry_points'],
            'mcp_tools' => $mcpTools,
            'files' => [
                'count' => $fileCount,
                'by_extension' => array_slice($extCount, 0, 12, true),
                'skipped_dirs' => $walk['skipped'],
                'scan' => $facts['scan'],
            ],
            'documentation' => [],
        ];
        if ($walk['limited']) {
            $truncated[] = 'file walk stopped at ' . self::SCAN_MAX_FILES . ' entries';
        }
        $out = array_filter($out, function ($v) {
            return $v !== null && $v !== [];
        });
        foreach (['overrides', 'upgrade', 'composer', 'symfony', 'theme_template_overrides', 'entry_points', 'mcp_tools'] as $k) {
            if (array_key_exists($k, $out) && ($out[$k] === null || $out[$k] === [])) {
                unset($out[$k]);
            }
        }

        // ---- fit budget: structure first, docs get the rest
        $this->fitBudget($out, (int) round($budget * 0.85), $truncated);
        $docs = [];
        if (!empty($args['include_docs']) && $docFiles) {
            $left = $budget - $this->jsonLen($out) - 600;
            foreach ($docFiles as $f) {
                $entry = ['path' => $f['path'], 'size' => $f['size']];
                if ($left < 300) {
                    $entry['content'] = null;
                    $entry['note'] = 'not included (budget): read with module_file_read';
                    $docs[] = $entry;
                    continue;
                }
                $raw = (string) @file_get_contents($root . '/' . $f['path'], false, null, 0, 400000);
                $raw = $this->utf8($raw);
                $take = min($left, preg_match('/^changelog|^changes|^history/i', basename($f['path'])) ? 3000 : 12000);
                if (mb_strlen($raw) > $take) {
                    $entry['content'] = mb_substr($raw, 0, $take);
                    $entry['truncated'] = true;
                    $truncated[] = $f['path'] . ' cut at ' . $take . ' chars';
                } else {
                    $entry['content'] = $raw;
                }
                $left -= $this->jsonLen($entry) + 10;
                $docs[] = $entry;
            }
        }
        $out['documentation'] = $docs ?: (empty($args['include_docs']) ? 'not requested' : 'no README/CHANGELOG/docs found');
        if ($truncated) {
            $out['truncated'] = $truncated;
        }
        $out['next_steps'] = 'Read the main file with module_file_read (path "' . $name . '.php"), then hook methods, controllers and classes; use module_code_search to follow a configuration key, table or hook.';

        return $out;
    }

    // ------------------------------------------------------------------
    // module_file_list
    // ------------------------------------------------------------------

    public function fileList(array $args, ExecutionContext $ctx): array
    {
        $name = $this->validName($args['module']);
        $root = $this->moduleRoot($name);
        $sub = '';
        if (!empty($args['path']) && trim((string) $args['path'], '/ ') !== '') {
            $abs = $this->safePath($root, (string) $args['path']);
            if (!is_dir($abs)) {
                throw new ToolException('Not a directory: ' . $args['path'], 'Use module_file_read for files.');
            }
            $sub = $abs === $root ? '' : substr($abs, strlen($root) + 1);
        }
        $walk = $this->walk($root, $sub, (int) $args['depth'], !empty($args['include_vendor']), (int) $args['max_entries'], false);
        $entries = [];
        $totalBytes = 0;
        foreach ($walk['entries'] as $e) {
            if ($e['dir']) {
                $row = ['path' => $e['path'] . '/'];
                if (isset($e['summary'])) {
                    $row['summary'] = $e['summary'];
                }
                $entries[] = $row;
            } else {
                $totalBytes += $e['size'];
                $entries[] = ['path' => $e['path'], 'size' => $e['size']];
            }
        }

        return [
            'module' => $name,
            'root' => 'modules/' . $name . '/' . ($sub !== '' ? $sub . '/' : ''),
            'depth' => (int) $args['depth'],
            'count' => count($entries),
            'total_bytes_listed' => $totalBytes,
            'entries' => $entries,
            'skipped' => $walk['skipped'],
            'complete' => !$walk['limited'],
        ];
    }

    // ------------------------------------------------------------------
    // module_file_read
    // ------------------------------------------------------------------

    public function fileRead(array $args, ExecutionContext $ctx): array
    {
        $name = $this->validName($args['module']);
        $root = $this->moduleRoot($name);
        $abs = $this->safePath($root, (string) $args['path']);
        if (!is_file($abs)) {
            throw new ToolException('Not a file: ' . $args['path'], 'module_file_list shows the files.');
        }
        $rel = substr($abs, strlen($root) + 1);
        $base = basename($abs);
        $cap = $this->maxBytes();
        $size = (int) filesize($abs);
        if ($this->isSecretFile($base)) {
            throw new ToolException('Refused: "' . $rel . '" looks like a secret-bearing file (credentials, keys, environment).', 'Configuration values are available (masked) via module_explain / shop_config_get.');
        }
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        // Runtime data (gateway logs, customer exports, backups) is personal or
        // sensitive data, not module logic: never served.
        if ($this->isRuntimeData($rel)) {
            throw new ToolException(
                'Refused: "' . $rel . '" is runtime data (logs, exports, uploads, cache or backups), not module code.',
                'Read the module code, templates, SQL schema or docs instead. Errors are available through shop_log_list.'
            );
        }
        if (in_array($ext, self::BINARY_EXT, true)) {
            throw new ToolException('Refused: binary file type .' . $ext . '.');
        }
        if ($ext === 'sql' && $size > $cap) {
            throw new ToolException('Refused: SQL file larger than the ' . $cap . ' byte cap (likely a data dump).');
        }
        $fh = @fopen($abs, 'rb');
        if (!$fh) {
            throw new ToolException('File is not readable: ' . $rel);
        }
        $head = (string) fread($fh, 8192);
        if (strpos($head, "\0") !== false) {
            fclose($fh);
            throw new ToolException('Refused: binary content in ' . $rel . '.');
        }
        if (preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $head)) {
            fclose($fh);
            throw new ToolException('Refused: file contains a private key.');
        }
        rewind($fh);

        $start = max(1, (int) $args['start_line']);
        $end = isset($args['end_line']) ? (int) $args['end_line'] : PHP_INT_MAX;
        if ($end < $start) {
            fclose($fh);
            throw new ToolException('end_line must be >= start_line.');
        }
        $lineNo = 0;
        $bytes = 0;
        $buf = [];
        $cut = false;
        $last = $start - 1;
        while (($line = fgets($fh)) !== false) {
            ++$lineNo;
            if ($lineNo < $start) {
                continue;
            }
            if ($lineNo > $end) {
                break;
            }
            $line = rtrim($line, "\r\n");
            $bytes += strlen($line) + 8;
            if ($bytes > $cap && $buf) {
                $cut = true;
                break;
            }
            $buf[] = str_pad((string) $lineNo, 5, ' ', STR_PAD_LEFT) . '  ' . $line;
            $last = $lineNo;
        }
        // count remaining lines cheaply (bounded)
        $totalLines = $lineNo;
        $countedAll = true;
        if ($line !== false) {
            $guard = 0;
            while (fgets($fh) !== false) {
                ++$totalLines;
                if (++$guard > 200000) {
                    $countedAll = false;
                    break;
                }
            }
        }
        fclose($fh);
        if ($start > $totalLines && $totalLines > 0) {
            throw new ToolException(sprintf('start_line %d is past the end of the file (%d lines).', $start, $totalLines));
        }

        $out = [
            'module' => $name,
            'path' => $rel,
            'size' => $size,
            'total_lines' => $countedAll ? $totalLines : null,
            'start_line' => $start,
            'end_line' => $last,
            'content' => $this->utf8(implode("\n", $buf)),
        ];
        if ($cut) {
            $out['truncated'] = true;
            $out['next_start_line'] = $last + 1;
            $out['note'] = 'Output capped at PPMCP_MAX_FILE_BYTES (' . $cap . ' bytes). Continue with start_line ' . ($last + 1) . '.';
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // module_code_search
    // ------------------------------------------------------------------

    public function codeSearch(array $args, ExecutionContext $ctx): array
    {
        $query = (string) $args['query'];
        $glob = isset($args['file_glob']) ? trim((string) $args['file_glob']) : '';
        $max = (int) $args['max_results'];
        $includeVendor = !empty($args['include_vendor']);
        if (!empty($args['regex'])) {
            $pattern = "\x01" . $query . "\x01" . (empty($args['case_sensitive']) ? 'i' : '');
            if (@preg_match($pattern, '') === false) {
                throw new ToolException('Invalid regular expression.', 'Pass a PCRE pattern without delimiters, e.g. "registerHook\\(\'(\\w+)\'".');
            }
        } else {
            $pattern = "\x01" . preg_quote($query, "\x01") . "\x01" . (empty($args['case_sensitive']) ? 'i' : '');
        }

        if (!empty($args['module'])) {
            $modules = [$this->validName($args['module'])];
        } else {
            if ($glob === '') {
                throw new ToolException('Searching all modules needs file_glob (e.g. "*.php", "*.tpl").', 'Or pass module to search one module.');
            }
            $modules = $this->diskModules();
        }

        $deadline = microtime(true) + self::SEARCH_SECONDS;
        $results = [];
        $filesScanned = 0;
        $bytes = 0;
        $complete = true;
        $reason = null;
        $filesMatched = [];
        foreach ($modules as $mod) {
            try {
                $root = $this->moduleRoot($mod);
            } catch (ToolException $e) {
                continue;
            }
            $sub = '';
            if (!empty($args['path']) && count($modules) === 1) {
                $abs = $this->safePath($root, (string) $args['path']);
                $sub = $abs === $root ? '' : substr($abs, strlen($root) + 1);
                if (is_file($abs)) {
                    $walk = ['entries' => [['path' => $sub, 'dir' => false, 'size' => (int) filesize($abs)]]];
                }
            }
            if (!isset($walk)) {
                $walk = $this->walk($root, $sub, 25, $includeVendor, self::SCAN_MAX_FILES, true);
            }
            foreach ($walk['entries'] as $f) {
                if ($f['dir']) {
                    continue;
                }
                $rel = $f['path'];
                $base = basename($rel);
                $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
                if (!in_array($ext, self::TEXT_EXT, true) || $this->isSecretFile($base) || $this->isRuntimeData($rel) || preg_match('/\.min\.(js|css)$/i', $base) || $ext === 'map') {
                    continue;
                }
                if ($glob !== '' && !fnmatch($glob, $base) && !fnmatch($glob, $rel)) {
                    continue;
                }
                if ($f['size'] > self::SCAN_FILE_MAX) {
                    continue;
                }
                if (microtime(true) > $deadline) {
                    $complete = false;
                    $reason = 'time limit (' . self::SEARCH_SECONDS . ' s)';
                    break 2;
                }
                if ($bytes > self::SCAN_MAX_BYTES * 2) {
                    $complete = false;
                    $reason = 'size limit';
                    break 2;
                }
                $content = @file_get_contents($root . '/' . $rel);
                if ($content === false || $content === '') {
                    continue;
                }
                ++$filesScanned;
                $bytes += strlen($content);
                if (strpos(substr($content, 0, 8192), "\0") !== false) {
                    continue;
                }
                $r = @preg_match($pattern, $content);
                if (!$r) {
                    continue;
                }
                $label = count($modules) === 1 ? $rel : $mod . '/' . $rel;
                $filesMatched[$label] = true;
                foreach (preg_split('/\r\n|\n|\r/', $content) as $i => $line) {
                    if (@preg_match($pattern, $line, $mm, PREG_OFFSET_CAPTURE)) {
                        $pos = $mm[0][1];
                        $snippet = strlen($line) > 240 ? substr($line, max(0, $pos - 80), 240) : $line;
                        $results[] = $label . ':' . ($i + 1) . ': ' . $this->utf8(trim($snippet));
                        if (count($results) >= $max) {
                            $complete = false;
                            $reason = 'max_results reached';
                            break 3;
                        }
                    }
                }
            }
            unset($walk);
        }

        return [
            'query' => $query,
            'regex' => !empty($args['regex']),
            'scope' => count($modules) === 1 ? 'modules/' . $modules[0] . '/' : 'all modules (' . count($modules) . ')',
            'count' => count($results),
            'files_matched' => count($filesMatched),
            'files_scanned' => $filesScanned,
            'complete' => $complete,
            'stopped_by' => $reason,
            'matches' => $results,
            'skipped' => 'vendor/node_modules' . ($includeVendor ? ' (included)' : '') . ', translations, binaries, *.min.*, secret files, files > ' . self::SCAN_FILE_MAX . ' bytes',
        ];
    }

    // ------------------------------------------------------------------
    // module_hook_list
    // ------------------------------------------------------------------

    public function hookList(array $args, ExecutionContext $ctx): array
    {
        $idShop = (int) $ctx->idShop;
        $p = _DB_PREFIX_;
        $hasModule = !empty($args['module']);
        $hasHook = !empty($args['hook']);
        if ($hasModule === $hasHook) {
            throw new ToolException('Pass either "module" or "hook" (not both).');
        }

        if ($hasModule) {
            $name = $this->validName($args['module']);
            $idModule = (int) $this->db()->getValue('SELECT `id_module` FROM `' . $p . 'module` WHERE `name` = \'' . pSQL($name) . '\'');
            if (!$idModule) {
                throw new ToolException('Module "' . $name . '" is not installed.', 'module_list shows installed modules.');
            }
            $info = is_file(_PS_MODULE_DIR_ . $name . '/' . $name . '.php') ? $this->parseMainFile($name, true) : [];
            $hooks = $this->explainHooks($name, $idModule, $idShop, null, $info, !empty($info['widget']));
            $hooks['module'] = $name;
            $hooks['id_shop'] = $idShop;
            $hooks['note'] = 'Implementation check is static (main file + traits/classes it declares); use module_explain for the reflection-based check.';

            return $hooks;
        }

        $hookName = trim((string) $args['hook']);
        if (!\Validate::isHookName($hookName)) {
            throw new ToolException('Invalid hook name.');
        }
        $hook = $this->db()->getRow('SELECT * FROM `' . $p . 'hook` WHERE `name` = \'' . pSQL($hookName) . '\'');
        $resolvedFrom = null;
        if (!$hook) {
            $canonical = $this->db()->getValue('SELECT `name` FROM `' . $p . 'hook_alias` WHERE `alias` = \'' . pSQL($hookName) . '\'');
            if ($canonical) {
                $hook = $this->db()->getRow('SELECT * FROM `' . $p . 'hook` WHERE `name` = \'' . pSQL($canonical) . '\'');
                $resolvedFrom = $hookName;
            }
        }
        if (!$hook) {
            $similar = array_column($this->db()->executeS(
                'SELECT `name` FROM `' . $p . 'hook` WHERE `name` LIKE \'%' . pSQL(str_replace(['%', '_'], ['\%', '\_'], $hookName)) . '%\' ORDER BY `name` LIMIT 20'
            ) ?: [], 'name');
            throw new ToolException('Hook "' . $hookName . '" does not exist.', $similar ? 'Similar hooks: ' . implode(', ', $similar) : 'Hook names are like displayHome, actionValidateOrder.');
        }
        $idHook = (int) $hook['id_hook'];
        $aliases = array_column($this->db()->executeS('SELECT `alias` FROM `' . $p . 'hook_alias` WHERE `name` = \'' . pSQL($hook['name']) . '\'') ?: [], 'alias');
        $rows = $this->db()->executeS(
            'SELECT hm.`position`, m.`id_module`, m.`name`, m.`active`, ms.`id_module` AS in_shop, ms.`enable_device`
             FROM `' . $p . 'hook_module` hm
             INNER JOIN `' . $p . 'module` m ON m.`id_module` = hm.`id_module`
             LEFT JOIN `' . $p . 'module_shop` ms ON ms.`id_module` = m.`id_module` AND ms.`id_shop` = ' . $idShop . '
             WHERE hm.`id_hook` = ' . $idHook . ' AND hm.`id_shop` = ' . $idShop . '
             ORDER BY hm.`position`'
        ) ?: [];
        $exceptions = [];
        foreach ($this->db()->executeS('SELECT `id_module`, `file_name` FROM `' . $p . 'hook_module_exceptions` WHERE `id_hook` = ' . $idHook . ' AND `id_shop` = ' . $idShop) ?: [] as $e) {
            $exceptions[(int) $e['id_module']][] = $e['file_name'];
        }
        $isDisplay = stripos($hook['name'], 'display') === 0;
        $names = array_merge([$hook['name']], $aliases);
        $modules = [];
        foreach ($rows as $r) {
            $mn = $r['name'];
            $row = [
                'position' => (int) $r['position'],
                'module' => $mn,
                'runs' => (int) $r['active'] === 1 && $r['in_shop'] !== null,
            ];
            if ((int) $r['active'] !== 1) {
                $row['inactive_reason'] = 'module disabled globally';
            } elseif ($r['in_shop'] === null) {
                $row['inactive_reason'] = 'module not enabled in this shop';
            }
            if ($r['in_shop'] !== null && (int) $r['enable_device'] !== 7) {
                $row['devices'] = $this->devices((int) $r['enable_device']);
            }
            if (isset($exceptions[(int) $r['id_module']])) {
                $row['not_on_pages'] = $this->compactList($exceptions[(int) $r['id_module']], 30);
            }
            if (is_file(_PS_MODULE_DIR_ . $mn . '/' . $mn . '.php')) {
                $info = $this->parseMainFile($mn, true);
                $impl = false;
                foreach ($names as $n) {
                    if (in_array(strtolower('hook' . $n), $info['hook_methods_lc'], true)) {
                        $impl = 'hook' . ucfirst($n) . '()';
                        break;
                    }
                }
                if (!$impl && $isDisplay && !empty($info['widget'])) {
                    $impl = 'renderWidget()';
                }
                if (!$impl && !empty($info['magic_call'])) {
                    $impl = '__call() (magic dispatcher)';
                }
                $row['implemented_by'] = $impl ?: 'not found in main file (may be inherited or in a trait)';
            } else {
                $row['implemented_by'] = 'module files missing';
            }
            $modules[] = $row;
        }

        return [
            'hook' => [
                'id_hook' => $idHook,
                'name' => $hook['name'],
                'resolved_from_alias' => $resolvedFrom,
                'aliases' => $aliases,
                'title' => $hook['title'],
                'description' => isset($hook['description']) ? mb_substr((string) $hook['description'], 0, 300) : null,
                'active' => isset($hook['active']) ? (bool) $hook['active'] : null,
            ],
            'id_shop' => $idShop,
            'count' => count($modules),
            'modules' => $modules,
            'note' => 'Ordered by position (execution/render order). not_on_pages = hook_module_exceptions (controller file names). Theme widgets ({widget}) and direct {hook} calls can also render modules outside this list.',
        ];
    }

    // ------------------------------------------------------------------
    // module_override_list
    // ------------------------------------------------------------------

    public function overrideList(array $args, ExecutionContext $ctx): array
    {
        $filter = !empty($args['module']) ? $this->validName($args['module']) : null;
        $classIndex = $this->classIndex();
        $files = [];
        foreach ($this->installedOverrides() as $of) {
            $owners = array_values(array_unique(array_filter(array_column($of['members'], 'module'))));
            if ($filter !== null && !in_array(strtolower($filter), array_map('strtolower', $owners), true)
                && !(preg_match('#^override/modules/([^/]+)/#', $of['file'], $m) && strcasecmp($m[1], $filter) === 0)) {
                continue;
            }
            $members = [];
            $manual = [];
            foreach ($of['members'] as $mem) {
                if ($filter !== null && $mem['module'] !== null && strcasecmp($mem['module'], $filter) !== 0) {
                    continue;
                }
                if ($mem['module'] === null) {
                    $manual[] = $mem['member'];
                } else {
                    $members[] = $mem['member'] . ' <- ' . $mem['module'] . ($mem['version'] ? ' ' . $mem['version'] : '') . ($mem['date'] ? ' (' . $mem['date'] . ')' : '');
                }
            }
            $row = [
                'file' => $of['file'],
                'class' => $of['class'],
                'extends' => $of['extends'],
                'size' => $of['size'],
                'modules' => $owners,
                'members' => $members,
            ];
            if ($manual) {
                $row['members_without_module_marker'] = $manual;
            }
            if ($classIndex['exists'] && $of['class']) {
                $row['in_class_index'] = isset($classIndex['overrides'][$of['class']]);
            }
            $files[] = $row;
        }

        $out = [
            'module' => $filter,
            'overrides_disabled' => (bool) (int) \Configuration::get('PS_DISABLE_OVERRIDES'),
            'count' => count($files),
            'files' => $files,
            'class_index' => [
                'file' => $classIndex['file'],
                'exists' => $classIndex['exists'],
                'override_entries' => $classIndex['exists'] ? count($classIndex['overrides']) : null,
            ],
            'note' => 'Overrides are active only when listed in the class index cache (var/cache/<env>/class_index.php) and PS_DISABLE_OVERRIDES is 0. A file with in_class_index=false is not loaded until the cache is cleared/regenerated. Members without a "* module:" marker were edited by hand.',
        ];
        if ($classIndex['exists']) {
            $stale = [];
            foreach ($classIndex['overrides'] as $class => $path) {
                if (!is_file(_PS_ROOT_DIR_ . '/' . $path)) {
                    $stale[] = $class . ' -> ' . $path;
                }
            }
            if ($stale) {
                $out['class_index']['entries_without_file'] = $stale;
            }
        }
        if ($filter !== null) {
            $root = null;
            try {
                $root = $this->moduleRoot($filter);
            } catch (ToolException $e) {
                $root = null;
            }
            if ($root && is_dir($root . '/override')) {
                $shipped = [];
                $w = $this->walk($root, 'override', 10, false, 500, false);
                foreach ($w['entries'] as $e) {
                    if (!$e['dir'] && substr($e['path'], -4) === '.php' && basename($e['path']) !== 'index.php') {
                        $shipped[] = ['file' => $e['path'], 'present_in_shop' => is_file(_PS_ROOT_DIR_ . '/' . $e['path'])];
                    }
                }
                $out['shipped_by_module'] = $shipped;
            }
        }

        return $out;
    }

    // ==================================================================
    // helpers: names & paths
    // ==================================================================

    /**
     * @throws ToolException
     */
    private function validName($name): string
    {
        $name = trim((string) $name);
        if ($name === '' || !\Validate::isModuleName($name) || strpos($name, '.') !== false || strlen($name) > 64) {
            throw new ToolException('Invalid module name "' . mb_substr($name, 0, 64) . '".', 'Use the technical name (directory name) from module_list, e.g. "ps_emailsubscription".');
        }

        return $name;
    }

    private function modulesBase(): string
    {
        $base = realpath(_PS_MODULE_DIR_);

        return $base ? rtrim($base, '/') : rtrim(_PS_MODULE_DIR_, '/');
    }

    /**
     * Real path of modules/<name> (must contain <name>.php).
     *
     * @throws ToolException
     */
    private function moduleRoot(string $name): string
    {
        $dir = $this->modulesBase() . '/' . $name;
        $real = realpath($dir);
        if ($real === false || !is_dir($real) || !is_file($real . '/' . $name . '.php')) {
            throw new ToolException('Module "' . $name . '" is not on disk (modules/' . $name . '/' . $name . '.php missing).', 'module_list shows modules on disk.');
        }
        if ($real === '/' || substr_count($real, '/') < 2) {
            throw new ToolException('Refused: module directory resolves to an unsafe location.');
        }

        return rtrim($real, '/');
    }

    /**
     * Resolve a path relative to the module root; refuses absolute paths,
     * traversal and symlinks leaving the root.
     *
     * @throws ToolException
     */
    private function safePath(string $root, string $rel): string
    {
        $rel = str_replace('\\', '/', trim($rel));
        if ($rel === '' || $rel === '.') {
            return $root;
        }
        if (strpos($rel, "\0") !== false || $rel[0] === '/' || preg_match('#^[A-Za-z]:#', $rel) || preg_match('#^[a-z][a-z0-9+.-]*://#i', $rel)) {
            throw new ToolException('Refused: path must be relative to the module directory.');
        }
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '..') {
                throw new ToolException('Refused: ".." is not allowed in paths.');
            }
            if (in_array($seg, self::VCS_DIRS, true)) {
                throw new ToolException('Refused: version-control/IDE directories are not readable.');
            }
        }
        $real = realpath($root . '/' . $rel);
        if ($real === false) {
            throw new ToolException('Path not found: ' . $rel, 'module_file_list shows the files.');
        }
        if ($real !== $root && strpos($real, $root . '/') !== 0) {
            throw new ToolException('Refused: path resolves outside modules/' . basename($root) . '/ (symlink or traversal).');
        }

        return $real;
    }

    /**
     * Runtime data (gateway logs, customer exports, uploads, caches, backups)
     * is never read, searched or scanned: it is personal / sensitive data, not
     * module code. Single rule shared by every content-reading tool.
     */
    private function isRuntimeData(string $rel): bool
    {
        $rel = '/' . ltrim(str_replace('\\', '/', $rel), '/');
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));

        return in_array($ext, self::DATA_EXT, true) || (bool) preg_match(self::DATA_DIR_PATTERN, $rel);
    }

    private function isSecretFile(string $base): bool
    {
        $b = strtolower($base);
        if (preg_match('/^\.env/', $b) || preg_match('/\.(pem|key|p12|pfx|jks|keystore|ppk|kdbx|gpg|asc|crt|cer|der|csr)$/', $b)) {
            return true;
        }
        if (preg_match('/^id_(rsa|dsa|ecdsa|ed25519)/', $b) || preg_match('/^(parameters\.(php|yml|yaml)|\.htpasswd|auth\.json|\.npmrc|\.pypirc|\.netrc|\.git-credentials|settings\.inc\.php|wp-config\.php)$/', $b)) {
            return true;
        }
        $stem = preg_replace('/\.[a-z0-9]+$/', '', $b);

        return (bool) preg_match('/(^|[._-])(secrets?|credentials?|passwords?|passwd|private[_-]?key|api[_-]?keys?|tokens?|service[_-]?account)([._-]|$)/', $stem);
    }

    private function maxBytes(): int
    {
        $v = (int) \Configuration::getGlobalValue('PPMCP_MAX_FILE_BYTES');

        return $v > 0 ? $v : 200000;
    }

    /**
     * @return string[] technical names of directories with <name>/<name>.php
     */
    private function diskModules(): array
    {
        $base = $this->modulesBase();
        $out = [];
        foreach (@scandir($base) ?: [] as $d) {
            if ($d[0] === '.' || !\Validate::isModuleName($d) || strpos($d, '.') !== false) {
                continue;
            }
            if (is_file($base . '/' . $d . '/' . $d . '.php')) {
                $out[] = $d;
            }
        }
        sort($out);

        return $out;
    }

    /**
     * Iterative walk below $root/$sub. Never follows symlinked directories;
     * symlinked files are kept only when they resolve inside the root.
     *
     * @return array{entries: array, skipped: array, limited: bool}
     */
    private function walk(string $root, string $sub, int $maxDepth, bool $includeVendor, int $maxEntries, bool $forScan): array
    {
        $entries = [];
        $skipped = [];
        $limited = false;
        $stack = [[$sub, 0]];
        while ($stack) {
            list($rel, $depth) = array_pop($stack);
            $abs = $root . ($rel !== '' ? '/' . $rel : '');
            $list = @scandir($abs);
            if ($list === false) {
                continue;
            }
            $dirs = [];
            foreach ($list as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $childRel = $rel !== '' ? $rel . '/' . $item : $item;
                $childAbs = $abs . '/' . $item;
                $lower = strtolower($item);
                if (is_link($childAbs)) {
                    $target = realpath($childAbs);
                    if ($target === false || ($target !== $root && strpos($target, $root . '/') !== 0) || is_dir($childAbs)) {
                        $skipped[] = $childRel . ' (symlink)';
                        continue;
                    }
                }
                if (is_dir($childAbs)) {
                    if (in_array($lower, self::VCS_DIRS, true)) {
                        continue;
                    }
                    if (!$includeVendor && in_array($lower, self::VENDOR_DIRS, true)) {
                        $skipped[] = $childRel . '/';
                        if (!$forScan) {
                            $entries[] = ['path' => $childRel, 'dir' => true, 'size' => 0, 'summary' => 'third-party code, not listed (include_vendor=true)'];
                        }
                        continue;
                    }
                    if ($forScan && in_array($lower, self::NOISE_DIRS, true)) {
                        $skipped[] = $childRel . '/';
                        continue;
                    }
                    $entries[] = ['path' => $childRel, 'dir' => true, 'size' => 0];
                    if ($depth + 1 < $maxDepth) {
                        $dirs[] = $childRel;
                    } elseif (!$forScan) {
                        $n = count(@scandir($childAbs) ?: []) - 2;
                        $entries[count($entries) - 1]['summary'] = max(0, $n) . ' entries (depth limit)';
                    }
                } else {
                    $entries[] = ['path' => $childRel, 'dir' => false, 'size' => (int) @filesize($childAbs)];
                }
                if (count($entries) >= $maxEntries) {
                    $limited = true;
                    break 2;
                }
            }
            rsort($dirs);
            foreach ($dirs as $d) {
                $stack[] = [$d, $depth + 1];
            }
        }
        usort($entries, function ($a, $b) {
            return strcmp($a['path'], $b['path']);
        });

        return ['entries' => $entries, 'skipped' => array_values(array_unique($skipped)), 'limited' => $limited];
    }

    private function dirHasPhp(string $dir, int $depth): bool
    {
        if ($depth < 0 || !is_dir($dir) || is_link($dir)) {
            return false;
        }
        foreach (@scandir($dir) ?: [] as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            $p = $dir . '/' . $i;
            if (is_file($p) && substr($i, -4) === '.php' && $i !== 'index.php') {
                return true;
            }
            if (is_dir($p) && $this->dirHasPhp($p, $depth - 1)) {
                return true;
            }
        }

        return false;
    }

    // ==================================================================
    // helpers: parsing
    // ==================================================================

    private function parseConfigXml(string $name): array
    {
        $f = _PS_MODULE_DIR_ . $name . '/config.xml';
        if (!is_file($f)) {
            return [];
        }
        $raw = (string) @file_get_contents($f, false, null, 0, 20000);
        $out = [];
        foreach (['displayName', 'version', 'description', 'author', 'tab'] as $tag) {
            if (preg_match('#<' . $tag . '>\s*(?:<!\[CDATA\[(.*?)\]\]>|([^<]*))\s*</' . $tag . '>#s', $raw, $m)) {
                $v = trim(isset($m[2]) && $m[2] !== '' ? $m[2] : $m[1]);
                if ($v !== '') {
                    $out[$tag] = $this->utf8($v);
                }
            }
        }

        return $out;
    }

    /**
     * Cheap regex parse of modules/<name>/<name>.php.
     */
    private function parseMainFile(string $name, bool $full): array
    {
        static $cache = [];
        $key = $name . ($full ? ':f' : ':s');
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $f = _PS_MODULE_DIR_ . $name . '/' . $name . '.php';
        $src = (string) @file_get_contents($f, false, null, 0, $full ? 1500000 : 400000);
        $out = ['hook_methods' => [], 'hook_methods_lc' => []];
        if ($src === '') {
            return $cache[$key] = $out;
        }
        if (preg_match_all('/\$this->(name|tab|version|author|need_instance|bootstrap)\s*=\s*(?:\'([^\']*)\'|"([^"]*)"|(\d+|true|false)\b|(?:self|static|[A-Za-z_\\\\]+)::([A-Z_][A-Z0-9_]*))/i', $src, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $prop = strtolower($m[1]);
                if (isset($out[$prop])) {
                    continue;
                }
                if (isset($m[5]) && $m[5] !== '') {
                    $v = preg_match('/const\s+' . preg_quote($m[5], '/') . '\s*=\s*[\'"]([^\'"]*)[\'"]/', $src, $c) ? $c[1] : null;
                } elseif (isset($m[4]) && $m[4] !== '') {
                    $v = strtolower($m[4]) === 'true' ? 1 : (strtolower($m[4]) === 'false' ? 0 : (int) $m[4]);
                } else {
                    $v = $m[2] !== '' ? $m[2] : (isset($m[3]) ? $m[3] : '');
                }
                if ($v !== null) {
                    $out[$prop] = $prop === 'bootstrap' ? (bool) $v : $v;
                }
            }
        }
        foreach (['displayName', 'description'] as $prop) {
            if (preg_match('/\$this->' . $prop . '\s*=\s*(?:\$this->(?:l|trans)\(\s*)?(\'|")((?:\\\\.|(?!\1).){0,400})\1/s', $src, $m)) {
                $out[$prop] = stripslashes($m[2]);
            }
        }
        if (preg_match('/\bclass\s+(\w+)\s+extends\s+\\\\?([\w\\\\]+)(?:\s+implements\s+([^{]+))?\s*\{/', $src, $m)) {
            $out['class'] = $m[1];
            $out['parent'] = $m[2];
            $out['interfaces'] = isset($m[3]) ? array_values(array_filter(array_map('trim', explode(',', $m[3])))) : [];
            $out['widget'] = isset($m[3]) && stripos($m[3], 'WidgetInterface') !== false;
        }
        $out['mcp_trait'] = stripos($src, 'PpMcpToolProviderTrait') !== false || stripos($src, 'hookActionPpMcpRegisterTools') !== false;
        if (!$full) {
            return $cache[$key] = $out;
        }
        $out['lines'] = substr_count($src, "\n") + 1;
        if (preg_match('/\$this->ps_versions_compliancy\s*=\s*(\[[^;]*\]|array\s*\([^;]*\))\s*;/s', $src, $m)) {
            $c = [];
            if (preg_match('/[\'"]min[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $m[1], $x)) {
                $c['min'] = $x[1];
            }
            if (preg_match('/[\'"]max[\'"]\s*=>\s*(?:[\'"]([^\'"]+)[\'"]|(_PS_VERSION_))/', $m[1], $x)) {
                $c['max'] = $x[1] !== '' ? $x[1] : '_PS_VERSION_';
            }
            $out['compliancy'] = $c;
        }
        if (preg_match('/\$this->dependencies\s*=\s*(\[[^;]*\]|array\s*\([^;]*\))\s*;/s', $src, $m) && preg_match_all('/[\'"]([\w-]+)[\'"]/', $m[1], $x)) {
            $out['dependencies'] = $x[1];
        }
        $out['has_getContent'] = (bool) preg_match('/function\s+getContent\s*\(/i', $src);
        $out['magic_call'] = (bool) preg_match('/function\s+__call\s*\(/i', $src);
        $methods = [];
        if (preg_match_all('/function\s+(hook[A-Za-z0-9_]+)\s*\(/i', $src, $m)) {
            $methods = $m[1];
        }
        $out['hook_methods'] = array_values(array_unique($methods));
        $out['hook_methods_lc'] = array_map('strtolower', $out['hook_methods']);

        return $cache[$key] = $out;
    }

    /**
     * One pass over the module's code files: configuration keys, CREATE TABLE,
     * ObjectModel definitions and cron/CLI entry points.
     */
    private function scanFacts(string $root, array $files, string $name): array
    {
        $config = [];
        $created = [];
        $models = [];
        $entry = [];
        $bytes = 0;
        $scanned = 0;
        $limited = false;
        foreach ($files as $f) {
            if ($f['dir']) {
                continue;
            }
            $ext = strtolower(pathinfo($f['path'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['php', 'sql', 'yml', 'yaml', 'tpl', 'twig', 'inc'], true) || $f['size'] > self::SCAN_FILE_MAX || $this->isSecretFile(basename($f['path'])) || $this->isRuntimeData($f['path'])) {
                continue;
            }
            if ($bytes > self::SCAN_MAX_BYTES) {
                $limited = true;
                break;
            }
            $src = @file_get_contents($root . '/' . $f['path']);
            if ($src === false) {
                continue;
            }
            $bytes += strlen($src);
            ++$scanned;
            $rel = $f['path'];
            if ($ext === 'php' || $ext === 'tpl' || $ext === 'twig' || $ext === 'inc') {
                if (preg_match_all('/Configuration::(get|getInt|getGlobalValue|updateValue|updateGlobalValue|deleteByName|hasKey|set|getMultiShopValues)\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/', $src, $m, PREG_SET_ORDER)) {
                    foreach ($m as $x) {
                        $k = strtoupper($x[2]);
                        $isWrite = in_array($x[1], ['updateValue', 'updateGlobalValue', 'set', 'deleteByName'], true);
                        if (!isset($config[$k])) {
                            $config[$k] = ['read' => false, 'write' => false, 'files' => []];
                        }
                        $config[$k][$isWrite ? 'write' : 'read'] = true;
                        if (count($config[$k]['files']) < 3 && !in_array($rel, $config[$k]['files'], true)) {
                            $config[$k]['files'][] = $rel;
                        }
                    }
                }
                // Keys in arrays/constants that look like the module prefix.
                $prefix = strtoupper($name) . '_';
                if (preg_match_all('/[\'"](' . preg_quote($prefix, '/') . '[A-Z0-9_]+)[\'"]/', $src, $m)) {
                    foreach ($m[1] as $k) {
                        if (!isset($config[$k])) {
                            $config[$k] = ['read' => false, 'write' => false, 'files' => [$rel], 'literal_only' => true];
                        }
                    }
                }
                if ($ext === 'php') {
                    if (preg_match('/\bclass\s+(\w+)\s+extends\s+\\\\?(ObjectModel|ObjectModelCore)\b/', $src, $cm)) {
                        $def = ['class' => $cm[1], 'file' => $rel];
                        if (preg_match('/\$definition\s*=\s*(?:\[|array\s*\()(.{0,3000})/s', $src, $dm)) {
                            if (preg_match('/[\'"]table[\'"]\s*=>\s*[\'"](\w+)[\'"]/', $dm[1], $t)) {
                                $def['table'] = $t[1];
                            }
                            if (preg_match('/[\'"]primary[\'"]\s*=>\s*[\'"](\w+)[\'"]/', $dm[1], $t)) {
                                $def['primary'] = $t[1];
                            }
                            $def['multilang'] = (bool) preg_match('/[\'"]multilang[\'"]\s*=>\s*true/i', $dm[1]);
                            $def['multishop'] = (bool) preg_match('/[\'"]multishop[\'"]\s*=>\s*true/i', $dm[1]);
                        }
                        $models[] = $def;
                    }
                    $base = basename($rel);
                    $why = [];
                    if (preg_match('/(cron|cli|task|sync|import|export|feed|worker|queue)/i', $base) && strpos($rel, 'controllers/') !== 0 && strpos($rel, 'classes/') !== 0 && strpos($rel, 'src/') !== 0 && $base !== $name . '.php') {
                        $why[] = 'file name';
                    }
                    if (preg_match('/(php_sapi_name\(\)|PHP_SAPI)\s*[!=]==?\s*[\'"]cli|[\'"]cli[\'"]\s*[!=]==?\s*(php_sapi_name\(\)|PHP_SAPI)|\$argv\[|\$_SERVER\[[\'"]argv/', $src)) {
                        $why[] = 'CLI check';
                    }
                    if (preg_match('/extends\s+\\\\?(?:Symfony\\\\Component\\\\Console\\\\Command\\\\)?Command\b/', $src) || preg_match('/#\[AsCommand|protected static \$defaultName/', $src)) {
                        $why[] = 'Symfony console command';
                    }
                    if (preg_match('#^controllers/front/(cron|task|sync|import|export|feed)#i', $rel)) {
                        $why[] = 'HTTP cron front controller';
                    }
                    if ($why && count($entry) < 25) {
                        $row = ['file' => $rel, 'why' => implode(', ', array_unique($why))];
                        if (preg_match('/\$defaultName\s*=\s*[\'"]([^\'"]+)/', $src, $cn) || preg_match('/->setName\(\s*[\'"]([^\'"]+)/', $src, $cn) || preg_match('/AsCommand\(\s*(?:name:\s*)?[\'"]([^\'"]+)/', $src, $cn)) {
                            $row['command'] = 'bin/console ' . $cn[1];
                        }
                        $entry[] = $row;
                    }
                }
            }
            if ($ext === 'php' || $ext === 'sql') {
                if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(.{1,80})/i', $src, $m)) {
                    foreach ($m[1] as $frag) {
                        $t = $this->tableFromFragment($frag);
                        if ($t !== null) {
                            if (!isset($created[$t])) {
                                $created[$t] = [];
                            }
                            if (count($created[$t]) < 2 && !in_array($rel, $created[$t], true)) {
                                $created[$t][] = $rel;
                            }
                        }
                    }
                }
            }
        }

        return [
            'config_keys' => $config,
            'created_tables' => $created,
            'object_models' => $models,
            'entry_points' => $entry,
            'scan' => ['files_scanned' => $scanned, 'bytes' => $bytes, 'limited' => $limited],
        ];
    }

    /**
     * "`' . _DB_PREFIX_ . 'foo_bar` (" -> "foo_bar"; null when the name is dynamic.
     */
    private function tableFromFragment(string $frag): ?string
    {
        $s = $frag;
        $s = preg_replace('/^[\s`"\'.]+/', '', $s);
        // strip prefix expressions
        $s = preg_replace('/^(?:[\'"]\s*\.\s*)?(?:_DB_PREFIX_|\$this->prefix|\$prefix|\{\$prefix\}|\$db_prefix|PREFIX_|_PREFIX_|ps_)\s*(?:\.\s*[\'"])?/i', '', $s);
        $s = preg_replace('/^[\s`"\'.]+/', '', $s);
        if ($s === '' || $s[0] === '$' || $s[0] === '{' || stripos($s, 'self::') === 0 || stripos($s, 'static::') === 0) {
            return null;
        }
        if (!preg_match('/^([A-Za-z0-9_]+)/', $s, $m)) {
            return null;
        }
        $t = strtolower($m[1]);
        if (strlen($t) < 3 || in_array($t, ['if', 'in', 'table', 'tables', 'select', 'as', 'like', 'for', 'with', 'the', 'sql', 'query', 'statement', 'statements', 'and', 'from', 'when'], true)) {
            return null;
        }
        $p = strtolower(_DB_PREFIX_);
        if ($p !== '' && strpos($t, $p) === 0) {
            $t = substr($t, strlen($p));
        }

        return $t;
    }

    // ==================================================================
    // helpers: explain sections
    // ==================================================================

    private function aliasMap(): array
    {
        if ($this->aliasCache === null) {
            $this->aliasCache = [];
            try {
                foreach ($this->db()->executeS('SELECT `alias`, `name` FROM `' . _DB_PREFIX_ . 'hook_alias`') ?: [] as $r) {
                    $this->aliasCache[strtolower($r['alias'])] = $r['name'];
                }
            } catch (\Throwable $e) {
                $this->aliasCache = [];
            }
        }

        return $this->aliasCache;
    }

    /**
     * @param \Module|null $instance
     */
    private function explainHooks(string $name, int $idModule, int $idShop, $instance, array $info, bool $isWidget): array
    {
        $p = _DB_PREFIX_;
        // Hook methods in code.
        $methods = [];
        $source = 'static';
        if ($instance) {
            $source = 'reflection';
            try {
                $rc = new \ReflectionClass($instance);
                foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                    if (stripos($m->getName(), 'hook') === 0 && strlen($m->getName()) > 4
                        && !in_array(strtolower($m->getDeclaringClass()->getName()), self::CORE_CLASSES, true)) {
                        $methods[strtolower($m->getName())] = $m->getName();
                    }
                }
            } catch (\Throwable $e) {
                $source = 'static';
            }
        }
        if ($source === 'static') {
            foreach ($info['hook_methods'] as $m) {
                $methods[strtolower($m)] = $m;
            }
        }
        unset($methods['hookexec']);
        $magicCall = false;
        if ($instance) {
            try {
                $mc = new \ReflectionMethod($instance, '__call');
                $magicCall = !in_array(strtolower($mc->getDeclaringClass()->getName()), self::CORE_CLASSES, true);
            } catch (\Throwable $e) {
                $magicCall = false;
            }
        } elseif (!empty($info['magic_call'])) {
            $magicCall = true;
        }

        $aliases = $this->aliasMap();
        $reverse = [];
        foreach ($aliases as $alias => $canon) {
            $reverse[strtolower($canon)][] = $alias;
        }

        $registered = [];
        $used = [];
        $withoutMethod = [];
        if ($idModule) {
            $rows = $this->db()->executeS(
                'SELECT h.`id_hook`, h.`name`, hm.`position`,
                    (SELECT COUNT(*) FROM `' . $p . 'hook_module` x WHERE x.`id_hook` = hm.`id_hook` AND x.`id_shop` = hm.`id_shop`) AS on_hook
                 FROM `' . $p . 'hook_module` hm INNER JOIN `' . $p . 'hook` h ON h.`id_hook` = hm.`id_hook`
                 WHERE hm.`id_module` = ' . $idModule . ' AND hm.`id_shop` = ' . $idShop . ' ORDER BY h.`name`'
            ) ?: [];
            $exceptions = [];
            foreach ($this->db()->executeS('SELECT `id_hook`, `file_name` FROM `' . $p . 'hook_module_exceptions` WHERE `id_module` = ' . $idModule . ' AND `id_shop` = ' . $idShop) ?: [] as $e) {
                $exceptions[(int) $e['id_hook']][] = $e['file_name'];
            }
            foreach ($rows as $r) {
                $hn = $r['name'];
                $lc = strtolower($hn);
                $candidates = array_merge([$lc], isset($reverse[$lc]) ? $reverse[$lc] : []);
                if (isset($aliases[$lc])) {
                    $candidates[] = strtolower($aliases[$lc]);
                }
                $impl = null;
                foreach ($candidates as $c) {
                    if (isset($methods['hook' . $c])) {
                        $impl = $methods['hook' . $c];
                        $used['hook' . $c] = true;
                        break;
                    }
                }
                $row = ['hook' => $hn, 'position' => (int) $r['position'], 'of' => (int) $r['on_hook']];
                if ($impl === null) {
                    if ($isWidget && stripos($hn, 'display') === 0) {
                        $row['via'] = 'renderWidget';
                    } elseif ($lc === 'actionppmcpregistertools' && !empty($info['mcp_trait'])) {
                        $row['via'] = 'PpMcpToolProviderTrait';
                    } elseif ($magicCall) {
                        $row['via'] = '__call()';
                    } elseif ($lc === 'registergdprconsent') {
                        $row['via'] = 'marker hook read by psgdpr (no method needed)';
                    } else {
                        $row['method'] = 'MISSING';
                        $withoutMethod[] = $hn;
                    }
                }
                if (isset($exceptions[(int) $r['id_hook']])) {
                    $row['not_on_pages'] = $this->compactList($exceptions[(int) $r['id_hook']], 15);
                }
                $registered[] = $row;
            }
        }
        $notRegistered = [];
        foreach ($methods as $lc => $m) {
            if (!isset($used[$lc])) {
                $notRegistered[] = $m;
            }
        }

        $out = [
            'registered_count' => count($registered),
            'registered' => $registered,
            'methods_in_code' => count($methods),
            'method_source' => $source,
        ];
        if ($withoutMethod) {
            $out['registered_without_method'] = $withoutMethod;
        }
        if (!$idModule) {
            $out['hook_methods'] = array_values($methods);
        } elseif ($notRegistered) {
            $out['implemented_not_registered'] = $notRegistered;
            $out['implemented_not_registered_note'] = 'Methods with no hook_module row in this shop: dead code, registered only in another shop, called directly, or the module needs a reset/upgrade.';
        }
        if (!$idModule) {
            $out['note'] = 'Module not installed: no hooks registered.';
        }

        return $out;
    }

    private function explainTables(string $name, array $facts): array
    {
        $p = _DB_PREFIX_;
        $cands = [];
        foreach ($facts['created_tables'] as $t => $src) {
            $cands[$t] = ['table' => $t, 'source' => 'CREATE TABLE in ' . implode(', ', $src)];
        }
        foreach ($facts['object_models'] as $om) {
            if (empty($om['table'])) {
                continue;
            }
            $t = strtolower($om['table']);
            $label = 'ObjectModel ' . $om['class'];
            foreach (array_filter([$t => true, $t . '_lang' => !empty($om['multilang']), $t . '_shop' => !empty($om['multishop'])]) as $tn => $on) {
                if (isset($cands[$tn])) {
                    $cands[$tn]['source'] .= '; ' . $label;
                } else {
                    $cands[$tn] = ['table' => $tn, 'source' => $label];
                }
            }
        }
        // Tables named after the module that no file mentions.
        $names = [];
        foreach ($cands as $t => $c) {
            $names[] = '\'' . pSQL($p . $t) . '\'';
        }
        $like = str_replace('_', '\_', pSQL($p . strtolower($name))) . '%';
        $info = [];
        try {
            $rows = $this->db()->executeS(
                'SELECT `TABLE_NAME` AS t, `TABLE_ROWS` AS r, `DATA_LENGTH` + `INDEX_LENGTH` AS b FROM information_schema.`TABLES`
                 WHERE `TABLE_SCHEMA` = DATABASE() AND (`TABLE_NAME` LIKE \'' . $like . '\'' . ($names ? ' OR `TABLE_NAME` IN (' . implode(',', $names) . ')' : '') . ')'
            ) ?: [];
            foreach ($rows as $r) {
                $short = strtolower(substr($r['t'], strlen($p)));
                $info[$short] = $r;
                if (!isset($cands[$short])) {
                    $cands[$short] = ['table' => $short, 'source' => 'exists in DB, name starts with module name'];
                }
            }
        } catch (\Throwable $e) {
            $info = [];
        }
        $tables = [];
        foreach ($cands as $t => $c) {
            $row = ['table' => $p . $t, 'source' => $c['source']];
            if (isset($info[$t])) {
                $row['exists'] = true;
                $est = (int) $info[$t]['r'];
                if ($est < 300000) {
                    try {
                        $row['rows'] = (int) $this->db()->getValue('SELECT COUNT(*) FROM `' . bqSQL($p . $t) . '`');
                    } catch (\Throwable $e) {
                        $row['rows_estimate'] = $est;
                    }
                } else {
                    $row['rows_estimate'] = $est;
                }
                $row['size_kb'] = (int) round(((int) $info[$t]['b']) / 1024);
            } else {
                $row['exists'] = false;
            }
            $tables[] = $row;
        }
        $out = ['tables' => $tables];
        if ($facts['object_models']) {
            $out['object_models'] = $facts['object_models'];
        }
        if (!$tables) {
            $out['note'] = 'No own tables found (module stores data in Configuration or core tables).';
        }

        return $out;
    }

    private function explainConfig(string $name, array $keys, int $idShop, int $idLang): array
    {
        $p = _DB_PREFIX_;
        $like = str_replace('_', '\_', pSQL(strtoupper($name))) . '\_%';
        foreach ($this->db()->executeS('SELECT DISTINCT `name` FROM `' . $p . 'configuration` WHERE `name` LIKE \'' . $like . '\' LIMIT 300') ?: [] as $r) {
            $k = strtoupper($r['name']);
            if (!isset($keys[$k])) {
                $keys[$k] = ['read' => false, 'write' => false, 'files' => [], 'db_only' => true];
            }
        }
        ksort($keys);
        $shop = new \Shop($idShop);
        $group = (int) $shop->id_shop_group;
        $existing = [];
        if ($keys) {
            $in = [];
            foreach (array_keys($keys) as $k) {
                if (\Validate::isConfigName($k)) {
                    $in[] = '\'' . pSQL($k) . '\'';
                }
            }
            if ($in) {
                foreach ($this->db()->executeS('SELECT `name`, `id_shop`, `id_shop_group` FROM `' . $p . 'configuration` WHERE `name` IN (' . implode(',', $in) . ')') ?: [] as $r) {
                    $existing[strtoupper($r['name'])][] = ((int) $r['id_shop'] ? 'shop' : ((int) $r['id_shop_group'] ? 'group' : 'global'));
                }
            }
        }
        $list = [];
        foreach ($keys as $k => $meta) {
            if (!\Validate::isConfigName($k)) {
                continue;
            }
            if (!empty($meta['literal_only']) && !isset($existing[$k])) {
                continue;
            }
            $row = ['key' => $k];
            if (isset($existing[$k])) {
                $value = \Configuration::get($k, null, $group, $idShop);
                if (($value === false || $value === null) && \Configuration::isLangKey($k)) {
                    $value = \Configuration::get($k, $idLang, $group, $idShop);
                    $row['multilang'] = true;
                }
                if (preg_match(ActionLogger::SECRET_KEYS, $k) && $value !== false && $value !== '' && $value !== null) {
                    $value = '***masked (' . strlen((string) $value) . ' chars)***';
                } elseif (is_string($value) && mb_strlen($value) > 160) {
                    $value = mb_substr($value, 0, 160) . '… (' . mb_strlen($value) . ' chars)';
                }
                $row['value'] = $value === false ? null : $value;
                $row['scopes'] = array_values(array_unique($existing[$k]));
            } else {
                $row['value'] = null;
                $row['in_db'] = false;
            }
            $use = [];
            if (!empty($meta['read'])) {
                $use[] = 'read';
            }
            if (!empty($meta['write'])) {
                $use[] = 'write';
            }
            if ($use) {
                $row['code'] = implode('+', $use);
            } elseif (!empty($meta['db_only'])) {
                $row['code'] = 'not referenced literally';
            }
            if (!empty($meta['files']) && $meta['files'][0] !== $name . '.php') {
                $row['in'] = $meta['files'][0];
            }
            $list[] = $row;
        }

        return ['count' => count($list), 'keys' => $list, 'note' => 'Values for the current shop; secret-looking keys masked. Keys built dynamically in code are not detected.'];
    }

    private function explainSymfony(string $root, array $files): ?array
    {
        $out = [];
        $ymls = [];
        foreach ($files as $f) {
            if (!$f['dir'] && preg_match('#^config/.+\.ya?ml$#', $f['path'])) {
                $ymls[] = $f['path'];
            }
        }
        if (!$ymls) {
            return null;
        }
        $out['config_files'] = $ymls;
        $routes = [];
        $services = [];
        $commands = [];
        foreach ($ymls as $rel) {
            $raw = (string) @file_get_contents($root . '/' . $rel, false, null, 0, 300000);
            $data = null;
            if (class_exists('Symfony\\Component\\Yaml\\Yaml')) {
                try {
                    $data = \Symfony\Component\Yaml\Yaml::parse($raw);
                } catch (\Throwable $e) {
                    $data = null;
                }
            }
            if (!is_array($data)) {
                continue;
            }
            if (preg_match('#routes?[^/]*\.ya?ml$#', $rel) || preg_match('#/routing/#', $rel)) {
                foreach ($data as $rn => $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    $routes[] = array_filter([
                        'name' => $rn,
                        'path' => isset($r['path']) ? $r['path'] : (isset($r['resource']) ? 'resource: ' . (is_string($r['resource']) ? $r['resource'] : json_encode($r['resource'])) : null),
                        'methods' => isset($r['methods']) ? $r['methods'] : null,
                        'controller' => isset($r['defaults']['_controller']) ? $r['defaults']['_controller'] : (isset($r['controller']) ? $r['controller'] : null),
                        'legacy_controller' => isset($r['defaults']['_legacy_controller']) ? $r['defaults']['_legacy_controller'] : null,
                    ]);
                }
            }
            if (isset($data['services']) && is_array($data['services'])) {
                foreach ($data['services'] as $id => $def) {
                    if ($id === '_defaults' || $id === '_instanceof') {
                        continue;
                    }
                    $cls = is_array($def) && isset($def['class']) ? $def['class'] : null;
                    $services[] = $cls && $cls !== $id ? $id . ' (' . $cls . ')' : (string) $id;
                    if (is_array($def) && isset($def['tags']) && is_array($def['tags'])) {
                        foreach ($def['tags'] as $tag) {
                            $tn = is_array($tag) ? (isset($tag['name']) ? $tag['name'] : '') : (string) $tag;
                            if ($tn === 'console.command') {
                                $commands[] = $id;
                            }
                        }
                    }
                }
            }
        }
        if ($routes) {
            $out['routes'] = $routes;
        }
        if ($services) {
            $out['services_count'] = count($services);
            $out['services'] = $services;
        }
        if ($commands) {
            $out['console_commands'] = $commands;
        }

        return $out;
    }

    private function themeOverrides(string $name): ?array
    {
        $themes = [];
        try {
            $shop = $this->context()->shop;
            $theme = isset($shop->theme_name) && $shop->theme_name ? $shop->theme_name : (defined('_THEME_NAME_') ? _THEME_NAME_ : null);
        } catch (\Throwable $e) {
            $theme = null;
        }
        if (!$theme || !\Validate::isModuleName(str_replace('-', '_', $theme))) {
            return null;
        }
        $themes[] = $theme;
        $yml = _PS_ALL_THEMES_DIR_ . $theme . '/config/theme.yml';
        if (is_file($yml) && preg_match('/^parent:\s*[\'"]?([\w-]+)/m', (string) @file_get_contents($yml, false, null, 0, 20000), $m)) {
            $themes[] = $m[1];
        }
        $out = [];
        foreach ($themes as $t) {
            $dir = realpath(_PS_ALL_THEMES_DIR_ . $t . '/modules/' . $name);
            if (!$dir || !is_dir($dir)) {
                continue;
            }
            $w = $this->walk($dir, '', 6, false, 200, false);
            $list = [];
            foreach ($w['entries'] as $e) {
                if (!$e['dir']) {
                    $list[] = $e['path'];
                }
            }
            if ($list) {
                $out[] = ['theme' => $t, 'dir' => 'themes/' . $t . '/modules/' . $name . '/', 'files' => $list];
            }
        }

        return $out ?: null;
    }

    private function providedMcpTools(string $name): ?array
    {
        try {
            // Reuse the registry already built for this request.
            $registry = \PrestaPro\PpMcp\Mcp\Runtime::$registry ?: new ToolRegistry($this->module);
            $all = $registry->all(true);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
        $out = [];
        if ($name === $this->module->name) {
            $owners = [];
            foreach ($all as $t) {
                if (!$t->isModuleTool) {
                    $owners[$t->owner][] = $t->name;
                }
            }

            return ['note' => 'ppmcp itself: core tool groups', 'core_tools' => $owners];
        }
        foreach ($all as $t) {
            if ($t->owner === strtolower($name)) {
                $out[] = ['name' => $t->name, 'title' => $t->title, 'write' => $t->write];
            }
        }
        $skills = $registry->getModuleSkills();
        if (!empty($skills[strtolower($name)])) {
            $out[] = ['skills' => array_map('basename', $skills[strtolower($name)])];
        }

        return $out ?: null;
    }

    /**
     * @param \Module $instance
     */
    private function parentChain($instance): array
    {
        $chain = [];
        $c = get_parent_class($instance);
        while ($c && count($chain) < 6) {
            $chain[] = $c;
            $c = get_parent_class($c);
        }

        return $chain;
    }

    private function classify(string $name, array $identity, array $hooks, array $front, array $admin, array $tabs, bool $isWidget, array $database, array $facts, $mcpTools, array $info): array
    {
        $hints = [];
        $chain = array_map('strtolower', array_merge([(string) $identity['parent_class']], (array) $identity['parent_chain']));
        $hookNames = array_map('strtolower', array_column($hooks['registered'], 'hook'));
        foreach ($hooks as $k => $v) {
            if ($k === 'implemented_not_registered') {
                foreach ($v as $m) {
                    $hookNames[] = strtolower(substr($m, 4));
                }
            }
        }
        $has = function ($re) use ($hookNames) {
            foreach ($hookNames as $h) {
                if (preg_match($re, $h)) {
                    return true;
                }
            }

            return false;
        };
        if (array_intersect($chain, ['paymentmodule', 'paymentmodulecore']) || $has('/^paymentoptions$/')) {
            $hints[] = ['type' => 'payment', 'why' => 'extends PaymentModule / paymentOptions hook'];
        }
        if (array_intersect($chain, ['carriermodule', 'carriermodulecore']) || $has('/^(actioncarrierupdate|displaycarrierextracontent)$/')) {
            $hints[] = ['type' => 'shipping', 'why' => 'extends CarrierModule / actionCarrierUpdate / displayCarrierExtraContent'];
        }
        if ($isWidget) {
            $hints[] = ['type' => 'widget', 'why' => 'implements WidgetInterface (front-office block)'];
        }
        $display = array_filter($hookNames, function ($h) {
            return strpos($h, 'display') === 0 && strpos($h, 'displayadmin') !== 0 && strpos($h, 'displaybackoffice') !== 0 && $h !== 'displayheader';
        });
        if ($display) {
            $hints[] = ['type' => 'front-office rendering', 'why' => count($display) . ' display hooks'];
        }
        if ($has('/^action(validateorder|orderstatus|orderstatusupdate|orderstatuspostupdate|cart|cartsave|objectorder|paymentconfirmation)/')) {
            $hints[] = ['type' => 'order/cart logic', 'why' => 'order or cart action hooks'];
        }
        if ($has('/^action(product|objectproduct|updatequantity|admin.*product)|^displayadminproducts/')) {
            $hints[] = ['type' => 'product management', 'why' => 'product action/admin hooks'];
        }
        if ($has('/^action(customer|authentication|objectcustomer)|^additionalcustomerformfields|^validatecustomerformfields/')) {
            $hints[] = ['type' => 'customer management', 'why' => 'customer hooks/form fields'];
        }
        if ($tabs || $admin) {
            $hints[] = ['type' => 'admin tool', 'why' => count($tabs) . ' BO tabs, ' . count($admin) . ' admin controllers'];
        } elseif ($has('/^(displaybackofficeheader|actionadmin|displayadmin)/')) {
            $hints[] = ['type' => 'back-office enhancement', 'why' => 'BO hooks'];
        }
        if ($front) {
            $hints[] = ['type' => 'front pages / endpoints', 'why' => count($front) . ' front controllers (pages, AJAX or cron endpoints)'];
        }
        if ($facts['entry_points']) {
            $hints[] = ['type' => 'scheduled / CLI jobs', 'why' => count($facts['entry_points']) . ' cron/CLI entry points'];
        }
        if (!empty($database['tables'])) {
            $hints[] = ['type' => 'stores own data', 'why' => count($database['tables']) . ' own tables'];
        }
        if (empty($database['tables']) && !$display && !$front && !$tabs) {
            $hints[] = ['type' => 'configuration-only / behaviour tweak', 'why' => 'no tables, display hooks or controllers'];
        }
        $keywords = [
            'seo & analytics' => '/seo|sitemap|analytics|gtm|ga4|pixel|tag|schema|rich|canonical|redirect|url|llms/',
            'marketing & promotions' => '/discount|promo|coupon|loyal|upsell|crosssell|cartupsell|gift|newsletter|abandon|reward|referr/',
            'content & cms' => '/blog|slider|banner|block|html|faq|cms|content|homeblock|reassurance|linklist/',
            'search & navigation' => '/search|facet|filter|menu|sort|navigation/',
            'integration & api' => '/api|import|export|feed|erp|sync|connector|webservice|mcp|finvalda|cloudinary/',
            'email & communication' => '/mail|sms|email|notif|chat|unisend/',
            'security & compliance' => '/gdpr|cookie|captcha|recaptcha|security|audit|log/',
            'localization & tax' => '/vat|tax|geoloc|currency|translate/',
        ];
        $hay = strtolower($name . ' ' . $identity['display_name'] . ' ' . $identity['tab']);
        foreach ($keywords as $type => $re) {
            if (preg_match($re, $hay)) {
                $hints[] = ['type' => $type, 'why' => 'name/display name/tab keywords (weak signal)'];
            }
        }
        if (!empty($mcpTools) && $name !== $this->module->name) {
            $hints[] = ['type' => 'provides MCP tools', 'why' => 'registered on actionPpMcpRegisterTools'];
        }
        $author = strtolower((string) $identity['author']);
        if ($author === 'prestashop' || strpos($name, 'ps_') === 0) {
            $hints[] = ['type' => 'origin: PrestaShop native/partner module', 'why' => 'author/name'];
        } elseif (strpos($author, 'prestapro') !== false || strpos($name, 'pp') === 0) {
            $hints[] = ['type' => 'origin: PrestaPro custom module', 'why' => 'author/name'];
        }

        return $hints;
    }

    // ==================================================================
    // helpers: overrides
    // ==================================================================

    /**
     * Files under override/ with members and their "* module:" owners.
     */
    private function installedOverrides(): array
    {
        if ($this->overrideCache !== null) {
            return $this->overrideCache;
        }
        $root = realpath(_PS_ROOT_DIR_ . '/override');
        $this->overrideCache = [];
        if (!$root) {
            return $this->overrideCache;
        }
        $w = $this->walk($root, '', 8, false, 3000, false);
        foreach ($w['entries'] as $e) {
            if ($e['dir'] || substr($e['path'], -4) !== '.php' || basename($e['path']) === 'index.php') {
                continue;
            }
            $src = (string) @file_get_contents($root . '/' . $e['path'], false, null, 0, 2000000);
            $row = ['file' => 'override/' . $e['path'], 'class' => null, 'extends' => null, 'size' => $e['size'], 'members' => []];
            if (preg_match('/\bclass\s+(\w+)(?:\s+extends\s+\\\\?([\w\\\\]+))?/', $src, $m)) {
                $row['class'] = $m[1];
                $row['extends'] = isset($m[2]) ? $m[2] : null;
            }
            $attributed = [];
            if (preg_match_all('/\/\*+\s*\*?\s*module:\s*([\w-]+)\s*\*?\s*(?:date:\s*([^\n*]*))?\s*\*?\s*(?:version:\s*([^\n*]*))?\s*\*+\/\s*((?:(?:public|protected|private|static|final|abstract|var)\s+)*)(?:function\s+&?\s*(\w+)|\$(\w+)|const\s+(\w+))/i', $src, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $x) {
                    $member = !empty($x[5]) ? $x[5] . '()' : (!empty($x[6]) ? '$' . $x[6] : (isset($x[7]) ? 'const ' . $x[7] : '?'));
                    $attributed[strtolower($member)] = true;
                    $row['members'][] = ['member' => $member, 'module' => $x[1], 'date' => trim((string) $x[2]) ?: null, 'version' => isset($x[3]) ? (trim((string) $x[3]) ?: null) : null];
                }
            }
            if (preg_match_all('/^\s*(?:(?:public|protected|private|static|final|abstract)\s+)*function\s+&?\s*(\w+)\s*\(/m', $src, $fm)) {
                foreach ($fm[1] as $fn) {
                    if (!isset($attributed[strtolower($fn . '()')])) {
                        $row['members'][] = ['member' => $fn . '()', 'module' => null, 'date' => null, 'version' => null];
                    }
                }
            }
            if (preg_match_all('/^\s*(?:public|protected|private|var)\s+(?:static\s+)?(?:\??[\w\\\\]+\s+)?\$(\w+)/m', $src, $pm)) {
                foreach ($pm[1] as $prop) {
                    if (!isset($attributed[strtolower('$' . $prop)])) {
                        $row['members'][] = ['member' => '$' . $prop, 'module' => null, 'date' => null, 'version' => null];
                    }
                }
            }
            $this->overrideCache[] = $row;
        }

        return $this->overrideCache;
    }

    /**
     * @return array{file: string, exists: bool, overrides: array}
     */
    private function classIndex(): array
    {
        $file = defined('_PS_CACHE_DIR_') ? _PS_CACHE_DIR_ . 'class_index.php' : _PS_ROOT_DIR_ . '/var/cache/' . (_PS_MODE_DEV_ ? 'dev' : 'prod') . '/class_index.php';
        $rel = strpos($file, _PS_ROOT_DIR_) === 0 ? ltrim(substr($file, strlen(_PS_ROOT_DIR_)), '/') : basename($file);
        $out = ['file' => $rel, 'exists' => is_file($file), 'overrides' => []];
        if ($out['exists']) {
            $raw = (string) @file_get_contents($file, false, null, 0, 20000000);
            if (preg_match_all('/\'(\w+)\'\s*=>\s*(?:array\s*\(|\[)\s*\'path\'\s*=>\s*\'(override\/[^\']+)\'/', $raw, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    $out['overrides'][$x[1]] = $x[2];
                }
            }
        }

        return $out;
    }

    // ==================================================================
    // helpers: misc
    // ==================================================================

    /**
     * @return array{pending: bool, truncated: bool}
     */
    private function compareVersions(string $disk, string $db): array
    {
        $truncated = strlen($disk) > 8 && strlen($db) === 8 && strpos($disk, $db) === 0;
        if ($truncated || $disk === $db) {
            return ['pending' => false, 'truncated' => $truncated];
        }

        return ['pending' => version_compare($disk, $db, '>'), 'truncated' => false];
    }

    private function devices(int $mask): array
    {
        $out = [];
        foreach ([1 => 'desktop', 2 => 'tablet', 4 => 'mobile'] as $bit => $label) {
            if ($mask & $bit) {
                $out[] = $label;
            }
        }

        return $out;
    }

    private function compactList(array $items, int $max): array
    {
        $items = array_values(array_unique($items));
        if (count($items) > $max) {
            $n = count($items);
            $items = array_slice($items, 0, $max);
            $items[] = '… +' . ($n - $max) . ' more';
        }

        return $items;
    }

    private function utf8(string $s): string
    {
        if ($s === '' || mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }

        return mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
    }

    private function jsonLen($v): int
    {
        return strlen((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * Shrink the largest lists until the encoded size fits.
     */
    private function fitBudget(array &$out, int $budget, array &$truncated): void
    {
        $paths = [
            ['configuration', 'keys'], ['hooks', 'registered'], ['symfony', 'services'], ['symfony', 'routes'],
            ['database', 'tables'], ['database', 'object_models'], ['controllers', 'front'], ['controllers', 'admin'],
            ['controllers', 'tabs'], ['entry_points'], ['overrides', 'installed_members'], ['overrides', 'shipped'],
            ['hooks', 'implemented_not_registered'], ['theme_template_overrides'], ['templates'], ['files', 'skipped_dirs'],
            ['upgrade', 'scripts'], ['identity', 'interfaces'],
        ];
        $orig = [];
        for ($guard = 0; $guard < 40 && $this->jsonLen($out) > $budget; ++$guard) {
            $best = null;
            $bestLen = 0;
            foreach ($paths as $path) {
                $ref = $out;
                foreach ($path as $seg) {
                    if (!is_array($ref) || !isset($ref[$seg])) {
                        $ref = null;
                        break;
                    }
                    $ref = $ref[$seg];
                }
                if (is_array($ref) && count($ref) > 3) {
                    $len = $this->jsonLen($ref);
                    if ($len > $bestLen) {
                        $best = $path;
                        $bestLen = $len;
                    }
                }
            }
            if ($best === null) {
                break;
            }
            $node = &$out;
            foreach ($best as $seg) {
                $node = &$node[$seg];
            }
            $n = count($node);
            $keep = max(3, (int) floor($n / 2));
            $isList = array_keys($node) === range(0, $n - 1);
            $node = $isList ? array_slice($node, 0, $keep) : array_slice($node, 0, $keep, true);
            unset($node);
            $label = implode('.', $best);
            if (!isset($orig[$label])) {
                $orig[$label] = $n;
            }
            $truncated[$label] = $label . ': showing ' . $keep . ' of ' . $orig[$label];
        }
        $truncated = array_values($truncated);
    }
}
