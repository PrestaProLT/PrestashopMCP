<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Tool;

/**
 * Normalised description of one MCP tool, whether it is a core tool or one a
 * third-party module registered through PpMcpToolProviderTrait.
 *
 * Naming convention (see docs/NAMING.md):
 *   <owner>_<resource>_<action>   e.g. catalog_product_get, ppslider_slide_update
 * lower-case a-z, 0-9 and "_" only, max 40 characters (Cursor caps server+tool
 * name at 60 chars, Claude clients at 64 for the namespaced name).
 */
class ToolDefinition
{
    const NAME_PATTERN = '/^[a-z][a-z0-9]*(_[a-z0-9]+)+$/';
    const NAME_MAX_LENGTH = 40;

    const SCOPE_READ = 'read';
    const SCOPE_WRITE = 'write';
    const SCOPE_CODE = 'code';

    /** @var string */
    public $name;
    /** @var string */
    public $title;
    /** @var string */
    public $description;
    /** @var string core domain (catalog, order…) or module technical name */
    public $owner;
    /** @var bool true when owner is a third-party module */
    public $isModuleTool = false;
    /** @var array JSON schema (type=object) */
    public $inputSchema;
    /** @var array|null */
    public $outputSchema;
    /** @var bool */
    public $write = false;
    /** @var bool */
    public $destructive = false;
    /** @var bool */
    public $idempotent = false;
    /** @var string */
    public $scope = self::SCOPE_READ;
    /** @var array list of [AdminTabClass, view|add|edit|delete] — ANY may grant access */
    public $permissions = [];
    /** @var bool write tool honours the dry_run argument */
    public $supportsDryRun = false;
    /** @var bool injects id_shop argument and switches shop context */
    public $usesShop = false;
    /** @var bool injects lang argument and switches language context */
    public $usesLang = false;
    /** @var string[] extra search keywords */
    public $keywords = [];
    /** @var callable function (array $args, ExecutionContext $ctx) */
    public $handler;

    /**
     * @param array $def keys: name, title, description, params|inputSchema, write,
     *                   destructive, idempotent, scope, permission(s), shop, lang,
     *                   keywords, outputSchema
     */
    public static function fromArray(array $def, string $owner, callable $handler, bool $isModuleTool = false): self
    {
        $t = new self();
        $t->name = isset($def['name']) ? (string) $def['name'] : '';
        $t->owner = $owner;
        $t->isModuleTool = $isModuleTool;
        $t->title = isset($def['title']) ? (string) $def['title'] : $t->name;
        $t->description = isset($def['description']) ? trim((string) $def['description']) : '';
        $t->write = !empty($def['write']);
        $t->destructive = !empty($def['destructive']);
        $t->idempotent = isset($def['idempotent']) ? (bool) $def['idempotent'] : !$t->write;
        $t->scope = isset($def['scope']) ? (string) $def['scope'] : ($t->write ? self::SCOPE_WRITE : self::SCOPE_READ);
        $t->usesShop = !empty($def['shop']);
        $t->usesLang = !empty($def['lang']);
        $t->keywords = isset($def['keywords']) ? array_values((array) $def['keywords']) : [];
        $t->outputSchema = isset($def['outputSchema']) && is_array($def['outputSchema']) ? $def['outputSchema'] : null;
        $t->handler = $handler;
        // Core write tools always implement dry_run; module tools opt in with 'dry_run' => true.
        $t->supportsDryRun = $t->write && (isset($def['dry_run']) ? (bool) $def['dry_run'] : !$isModuleTool);

        $perms = [];
        if (isset($def['permission']) && is_array($def['permission'])) {
            $perms[] = array_values($def['permission']);
        }
        if (isset($def['permissions']) && is_array($def['permissions'])) {
            foreach ($def['permissions'] as $p) {
                if (is_array($p)) {
                    $perms[] = array_values($p);
                }
            }
        }
        $t->permissions = $perms;

        if (isset($def['inputSchema']) && is_array($def['inputSchema'])) {
            $schema = $def['inputSchema'];
        } else {
            $schema = self::schemaFromParams(isset($def['params']) && is_array($def['params']) ? $def['params'] : []);
        }
        $t->inputSchema = self::injectCommonParams($schema, $t);

        $t->assertValid();

        return $t;
    }

    /**
     * Compact param syntax used by core tools and the module trait:
     *   'id_product' => ['type' => 'integer', 'description' => '…', 'required' => true]
     */
    public static function schemaFromParams(array $params): array
    {
        $properties = [];
        $required = [];
        foreach ($params as $name => $spec) {
            if (!is_array($spec)) {
                $spec = ['type' => (string) $spec];
            }
            if (!empty($spec['required'])) {
                $required[] = (string) $name;
            }
            unset($spec['required']);
            $properties[(string) $name] = $spec;
        }
        $schema = ['type' => 'object', 'properties' => empty($properties) ? new \stdClass() : $properties];
        if ($required) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    private static function injectCommonParams(array $schema, self $t): array
    {
        if (!isset($schema['type'])) {
            $schema['type'] = 'object';
        }
        $props = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];
        if ($t->usesShop && !isset($props['id_shop'])) {
            $props['id_shop'] = [
                'type' => 'integer',
                'description' => 'Shop ID (multistore). Omit to use the shop this MCP endpoint belongs to. See shop_context_get.',
            ];
        }
        if ($t->usesLang && !isset($props['lang'])) {
            $props['lang'] = [
                'type' => ['string', 'integer'],
                'description' => 'Language ISO code (e.g. "en", "lt") or id_lang. Omit for the shop default language.',
            ];
        }
        if ($t->supportsDryRun && !isset($props['dry_run'])) {
            $props['dry_run'] = [
                'type' => 'boolean',
                'description' => 'true = validate and return the planned changes without saving anything.',
                'default' => false,
            ];
        }
        $schema['properties'] = empty($props) ? new \stdClass() : $props;

        return $schema;
    }

    private function assertValid()
    {
        if (strlen($this->name) > self::NAME_MAX_LENGTH || !preg_match(self::NAME_PATTERN, $this->name)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid MCP tool name "%s": use <owner>_<resource>_<action>, lower-case a-z0-9 and "_", max %d chars.',
                $this->name,
                self::NAME_MAX_LENGTH
            ));
        }
        if (strpos($this->name, $this->owner . '_') !== 0) {
            throw new \InvalidArgumentException(sprintf(
                'MCP tool "%s" must start with its owner prefix "%s_".',
                $this->name,
                $this->owner
            ));
        }
        if ($this->description === '') {
            throw new \InvalidArgumentException(sprintf('MCP tool "%s" needs a description.', $this->name));
        }
        if (!in_array($this->scope, [self::SCOPE_READ, self::SCOPE_WRITE, self::SCOPE_CODE], true)) {
            throw new \InvalidArgumentException(sprintf('MCP tool "%s" has an unknown scope "%s".', $this->name, $this->scope));
        }
    }

    /**
     * Shape returned by tools/list.
     */
    public function toMcp(string $protocolVersion): array
    {
        $out = [
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
        ];
        // All four hints are always sent explicitly: clients default
        // destructiveHint/openWorldHint to true when omitted.
        $out['annotations'] = [
            'title' => $this->title,
            'readOnlyHint' => !$this->write,
            'destructiveHint' => $this->write && $this->destructive,
            'idempotentHint' => $this->idempotent,
            'openWorldHint' => false,
        ];
        if ($this->outputSchema && strcmp($protocolVersion, '2025-06-18') >= 0) {
            $out['outputSchema'] = $this->outputSchema;
        }

        return $out;
    }
}
