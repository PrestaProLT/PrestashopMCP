# Tool naming convention

Every tool name says **who owns it, what it touches and what it does**:

```
<owner>_<resource>_<action>
```

| part | rule | examples |
|---|---|---|
| owner | a core domain, or the **technical name of the module** that provides the tool | `catalog`, `order`, `ppslider` |
| resource | the thing acted on (singular) | `product`, `stock`, `slide`, `banner` |
| action | one verb from the list below | `get`, `update` |

Characters: `a-z`, `0-9`, `_` only (no dots, hyphens, capitals or `__`), **max 40 characters**.
Reasons: the Claude API accepts `^[a-zA-Z0-9_-]{1,128}$` and claude.ai requires ≤ 64; Claude Code
namespaces tools as `mcp__<server>__<tool>`; Cursor caps server + tool name at 60 characters and
rejects dots. Name the server in the client `prestashop` (or another short name).

## Core owners (reserved — a module with one of these names cannot register tools)

| owner | covers |
|---|---|
| `shop` | versions, shops, languages, currencies, configuration, PrestaShop system log |
| `catalog` | products, combinations, stock, categories, prices, specific prices, brands |
| `order` | orders, statuses, tracking, notes, sales statistics |
| `customer` | customers, groups, addresses |
| `cart` | carts, abandoned carts, cart rules (vouchers) |
| `diagnose` | "why" tools: cannot add to cart, product not visible, checkout problems |
| `module` | installed modules, how they work (module_explain), hooks, files, overrides |
| `mcp` | the MCP server itself: tool search, skills, activity log, whoami |

## Actions

| verb | meaning |
|---|---|
| `list` | collection with simple filters |
| `search` | free text + filters, paged |
| `get` | one record by id, full detail |
| `create` / `update` / `delete` | CRUD (write) |
| `set` | change one specific state/field (`order_status_set`, `catalog_stock_set`) |
| `add` / `remove` | add/remove an item to/from a collection (`order_note_add`) |
| `explain` | why a value is what it is (`catalog_price_explain`, `module_explain`) |
| `diagnose` | owner for problem analysis tools (`diagnose_cart_add`) |
| `scan` | find problems across many records (`catalog_product_issue_scan`) |
| `summary` | aggregated numbers (`order_stats_summary`) |

## Module tools

Modules declare **local** names (`slide_update`); ppmcp prefixes them with the module name:

```
module ppslider, local name slide_update  →  ppslider_slide_update
```

So "change the slider on mobile and desktop" reads naturally as *module ppslider → tool
slide_update*. The assistant can discover them with `mcp_tool_search` ("slider mobile") or browse
the hierarchy with `mcp_tool_search` and an empty query.

Keep module names short (≤ 15 characters) so that local names have room within the 40-character limit.
