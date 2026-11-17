# PrestaPro MCP Server (`ppmcp`)

A [Model Context Protocol](https://modelcontextprotocol.io) server that runs **inside PrestaShop**
as a module. It lets AI assistants (Claude Code, Claude Desktop, claude.ai connectors, Cursor…)
help shop managers with day-to-day work: catalog, stock, prices, orders, customers, carts and
vouchers; diagnostics such as *"why can't this customer add product Y to the cart?"*; and
explaining how any installed module works.

* **PrestaShop 1.7.0 → 9.x**, one code base · **PHP 7.1 → 8.4**
* **Multistore and multilanguage**: every tool works on a chosen shop and language; multilang
  fields accept `{"en": "…", "lt": "…"}`
* **Extensible**: other modules publish their own tools with `PpMcpToolProviderTrait`
* **Clear tool names**: `<owner>_<resource>_<action>` — `catalog_product_update`, `order_status_set`,
  `ppslider_slide_update` (module ppslider)
* **Traceable**: every request is logged — who, what, where, arguments, outcome, and the
  before/after value of every changed field
* **Skills**: built-in playbooks (module analysis, cart/checkout problems, bulk edits, order
  investigation, daily report…) exposed as tools, MCP prompts and resources
* **Safe by default**: per-key scopes (read / write / code), employee back-office permissions,
  shop restriction, expiry, global read-only switch, `dry_run` on every write tool

## Install

1. Copy the repository into `modules/ppmcp/` (the directory name must be `ppmcp`) or upload a zip.
2. Install **PrestaPro MCP Server** in the Module Manager.
3. **PrestaPro MCP → API keys → Add**: choose the employee the assistant acts as and the scopes.
   Copy the token (shown once).
4. Connect your client — see [docs/CONNECTING.md](docs/CONNECTING.md). Claude Code:

   ```bash
   claude mcp add --transport http prestashop https://your-shop.tld/module/ppmcp/server \
     --header "Authorization: Bearer ppmcp_xxx"
   ```

## Back office

| page | what |
|---|---|
| Module **Configure** | enable/disable server, read-only mode, logging options, retention, allowed origins, endpoint URLs and copy-paste client configs |
| **PrestaPro MCP → API keys** | create / disable / delete keys; scopes, shops, expiry, last use |
| **PrestaPro MCP → Activity log** | every MCP request; detail view with arguments, result and changes (before → after) |
| **PrestaPro MCP → Tools & skills** | all tools grouped by owner (core / modules), enable or disable each, provider errors, skills |

## Tools

Core owners: `shop`, `catalog`, `order`, `customer`, `cart`, `diagnose`, `module`, `mcp`.
Full list with descriptions: [docs/TOOLS.md](docs/TOOLS.md). Naming rules: [docs/NAMING.md](docs/NAMING.md).

Start points for an assistant:

* `shop_context_get` – versions, shops, languages, currencies, key settings
* `mcp_tool_search` – find the tool for an intent ("hide slider on mobile"), or browse the hierarchy
* `mcp_skill_list` / `mcp_skill_get` – playbooks
* `diagnose_cart_add`, `diagnose_checkout`, `diagnose_product_visibility` – the "why" tools
* `module_explain` – how a module works (hooks, tables, config, controllers, overrides, README)
* `mcp_log_list` – what was done through MCP

## Tools in your own modules

```php
require_once __DIR__ . '/mcp/PpMcpToolProviderTrait.php';

class PpSlider extends Module
{
    use PpMcpToolProviderTrait;

    public function ppMcpTools()
    {
        return [[
            'name' => 'slide_update',          // exposed as ppslider_slide_update
            'description' => 'Changes one slide (texts per language, desktop/mobile visibility).',
            'params' => ['id_slide' => ['type' => 'integer', 'required' => true], 'show_on_mobile' => ['type' => 'boolean']],
            'write' => true, 'dry_run' => true, 'shop' => true,
            'permission' => ['AdminModules', 'edit'],
            'handler' => 'mcpSlideUpdate',
        ]];
    }
}
```

Guide: [docs/INTEGRATION.md](docs/INTEGRATION.md) · working example: [integration/example/ppmcpexample](integration/example/ppmcpexample).

## Skills

Markdown files in `skills/` (and `mcp/skills/` of other modules) with front matter
`name`, `title`, `description`, `when_to_use`. Built-in skills:

| skill | for |
|---|---|
| `module_analysis` | explain any module: what to read, what to check, what to ignore |
| `cart_add_problem` | customer cannot add a product to the cart |
| `checkout_problem` | no carrier / payment, order cannot be placed |
| `product_not_visible` | product missing in category / search / 404 |
| `price_problem` | why a price is what it is |
| `catalog_bulk_edit` | safe bulk changes (dry run, batches, multistore, translations) |
| `order_investigation` | order complaints, statuses, tracking, notes |
| `daily_manager_report` | morning overview with action items |
| `multistore_multilang` | rules for shops and languages |
| `module_tools_usage` | finding the right tool, module tools, denied tools |

## Security model

* Tokens are random (`ppmcp_` + 192 bits), stored as SHA-256 hashes, shown once; sent as
  `Authorization: Bearer` (or `X-Api-Key`). Never in URLs.
* A key acts as one employee; tools check that employee's back-office permissions
  (`AdminProducts`, `AdminOrders`…). Only super admins create keys for other employees.
* Scopes: `read`, `write` (data changes), `code` (read module source). Optional shop list and expiry.
* `tools/list` only shows what the key may call. Read-only mode hides all write tools.
* Secrets in configuration values, arguments and logs are masked.
* Requests with a foreign browser `Origin` are refused (DNS-rebinding protection).

## Protocol

Streamable HTTP, JSON responses, stateless. Speaks both MCP eras on the same endpoint:
2025-03-26 / 2025-06-18 / 2025-11-25 (`initialize` handshake) and 2026-07-28 (`server/discover`,
per-request `_meta`). Tools, prompts and resources capabilities. Test: `tests/protocol-smoke.sh URL TOKEN`.

## Development

[docs/DEVELOPING.md](docs/DEVELOPING.md) — compatibility rules, tool group contract, testing.

License: AFL-3.0 · Author: PrestaPro
