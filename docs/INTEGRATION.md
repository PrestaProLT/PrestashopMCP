# Adding MCP tools to your module

Any module can publish tools (and skills) through PrestaPro MCP without depending on it.

## 1. Bundle the trait

Copy `modules/ppmcp/integration/PpMcpToolProviderTrait.php` to your module, e.g.
`modules/yourmodule/mcp/PpMcpToolProviderTrait.php`. It references no ppmcp class and is
guarded with `trait_exists()`, so several modules can ship it and your module keeps working when
ppmcp is not installed (the hook is simply never called).

## 2. Use it

```php
require_once __DIR__ . '/mcp/PpMcpToolProviderTrait.php';

class PpSlider extends Module
{
    use PpMcpToolProviderTrait;

    public function install()
    {
        return parent::install() && $this->ppMcpRegisterHook();   // registers actionPpMcpRegisterTools
    }

    public function ppMcpTools()
    {
        return [
            [
                'name' => 'slide_list',                              // → ppslider_slide_list
                'title' => 'List slides',
                'description' => 'Lists homepage slides with position, image per device (desktop/mobile), link and active flag. Use before slide_update.',
                'params' => [
                    'device' => ['type' => 'string', 'enum' => ['desktop', 'mobile', 'all'], 'default' => 'all'],
                ],
                'shop' => true,                                      // inject id_shop, switch context
                'lang' => true,                                      // inject lang, switch context
                'permission' => ['AdminModules', 'view'],            // employee BO right needed
                'handler' => 'mcpSlideList',
            ],
            [
                'name' => 'slide_update',                            // → ppslider_slide_update
                'title' => 'Update a slide',
                'description' => 'Changes one slide: title/legend/url per language, active, show on desktop/mobile. dry_run=true previews.',
                'params' => [
                    'id_slide' => ['type' => 'integer', 'required' => true],
                    'active' => ['type' => 'boolean'],
                    'show_on_mobile' => ['type' => 'boolean'],
                    'show_on_desktop' => ['type' => 'boolean'],
                    'title' => ['type' => 'object', 'description' => '{"en": "...", "lt": "..."}'],
                ],
                'write' => true,                                     // needs key scope "write"
                'dry_run' => true,                                   // handler honours $context['dry_run']
                'shop' => true,
                'permission' => ['AdminModules', 'edit'],
                'keywords' => ['carousel', 'banner', 'homepage'],
                'handler' => 'mcpSlideUpdate',
            ],
        ];
    }

    public function mcpSlideList(array $args, array $context)
    {
        // $context: id_shop, id_lang, id_employee, id_api_key, dry_run, request_id, client
        return ['slides' => [/* ... */]];
    }

    public function mcpSlideUpdate(array $args, array $context)
    {
        $slide = new PpSlide((int) $args['id_slide'], null, (int) $context['id_shop']);
        if (!Validate::isLoadedObject($slide)) {
            $this->ppMcpFail('Slide not found.', 'Call ppslider_slide_list to get ids.');
        }
        $changes = [];
        if (isset($args['show_on_mobile'])) {
            $changes[] = $this->ppMcpChange('ppslider_slide', $slide->id, 'show_on_mobile', (bool) $slide->mobile, (bool) $args['show_on_mobile'], $context['id_shop']);
            $slide->mobile = (int) $args['show_on_mobile'];
        }
        // ... other fields ...
        if (!$context['dry_run']) {
            $slide->save();
        }

        return $this->ppMcpResult(['id_slide' => (int) $slide->id], $changes);
    }
}
```

## Definition keys

| key | required | meaning |
|---|---|---|
| `name` | yes | local `<resource>_<action>`; final name `<module>_<resource>_<action>` ≤ 40 chars |
| `description` | yes | when to use it and what it returns — the model chooses tools by this text |
| `title` | | human label |
| `params` | | `['arg' => ['type' => 'integer'|'number'|'string'|'boolean'|'array'|'object', 'description', 'enum', 'minimum', 'maximum', 'default', 'items', 'required' => true]]` |
| `write` | | changes data. Needs key scope `write`; hidden in read-only mode |
| `dry_run` | | your handler honours `$context['dry_run']`; adds the `dry_run` argument |
| `destructive` | | deletes / irreversibly overwrites |
| `scope` | | `read` (default), `write` (default for write tools) or `code` — tools exposing source code or files need an API key with the `code` scope |
| `permission` / `permissions` | | `['AdminTab', 'view'|'add'|'edit'|'delete']` or a list (any grants) |
| `shop` / `lang` | | inject `id_shop` / `lang` arguments; the context is switched before your handler runs |
| `keywords` | | extra words for `mcp_tool_search` |
| `handler` | yes | public method name (or callable): `function (array $args, array $context)` |

Arguments are validated against `params` before your handler runs (types are coerced, unknown
arguments rejected). `id_shop`, `lang` and `dry_run` are removed from `$args` — read them from
`$context`.

## Results, changes and errors

* Return an array (or scalar). Keep it compact and paged.
* `ppMcpResult($data, $changes)` + `ppMcpChange($entity, $id, $field, $before, $after, $idShop, $idLang)`
  report what changed. The assistant sees the changes and they are written to the MCP activity log —
  report every changed field, also in dry runs.
* `ppMcpFail($message, $hint)` stops with an error the model can act on. Any other exception is
  caught, shown as an error and logged.

## Skills

Put markdown playbooks in `modules/yourmodule/mcp/skills/*.md` (or override `ppMcpSkills()` to
return file paths). Front matter: `name`, `title`, `description`, `when_to_use`. They are listed as
`<module>_<name>` by `mcp_skill_list`.

## Checklist

* [ ] trait bundled, `ppMcpRegisterHook()` in `install()` **and** in an upgrade script for existing installs
  (in the upgrade script set `$module->id` first on PS 9 — the trait does it for you)
* [ ] descriptions say when to use the tool
* [ ] write tools record changes and support `dry_run`
* [ ] permissions declared
* [ ] multistore: read/write for `$context['id_shop']`; multilang: per `id_lang`
* [ ] test: the tool appears in *PrestaPro MCP → Tools & skills*; provider errors are shown there

A complete working example: `integration/example/ppmcpexample/`.
