# Developing core tools (ppmcp internals)

This is the contract for tools shipped **inside** ppmcp (`src/Tool/Core/*Tools.php`).
Third-party modules use the same definition format through
`PpMcpToolProviderTrait` — see [INTEGRATION.md](INTEGRATION.md).

## Compatibility rules (non-negotiable)

* **PrestaShop 1.7.0 → 9.x from one code base. PHP 7.1 → 8.4.**
  * PHP 7.1 syntax only: no typed properties, arrow functions, `??=`, `?->`,
    `match`, named arguments, union types, `mixed`, attributes, trailing commas
    in calls. Avoid PHP ≥ 7.3 functions (`array_key_first`, `str_contains`,
    `JSON_THROW_ON_ERROR`, `is_countable`…).
  * PHP 8.4 safe: nullable params written `?Type $x = null`, no dynamic properties.
  * No API removed in PS 8/9: `Tools::jsonEncode`, `Tools::displayPrice`,
    `ajaxDie`, `Attribute` class (use SQL or `ProductAttribute` when it exists),
    `Order::getOrderByCartId`, `Cart::containsProduct`, `StockAvailable::dependsOnStock`,
    `Product::getStockMvts`, `meta_keywords`. Guard version-specific calls with
    `method_exists()` / `property_exists()` / `version_compare(_PS_VERSION_, …)`.
  * PS 9 typed some params as `bool`: never pass `null` where a bool is expected
    (`Product::getPriceStatic` `$usetax`, `Category::getNestedCategories` `$active`).
  * `Db::getRow()`/`getValue()` append `LIMIT 1` themselves — never add one.
  * `Db::executeS()` is for SELECT only (throws on others since 1.7.8).
* Read with `$this->db()` (slave), write with `$this->dbWrite()` or ObjectModel.
* Escape everything: `(int)`, `pSQL()`, `bqSQL()`. No raw argument in SQL.

## A tool group

```php
namespace PrestaPro\PpMcp\Tool\Core;

class CatalogTools extends AbstractToolGroup
{
    public function owner(): string { return 'catalog'; }   // every tool name starts with "catalog_"

    public function tools(): array
    {
        return [[
            'name' => 'catalog_product_get',          // <owner>_<resource>_<action>, [a-z0-9_], ≤ 40 chars
            'title' => 'Get product details',
            'description' => 'WHEN to use it, WHAT it returns, which tool to use next.',
            'params' => [
                'id_product' => self::idParam('Product ID (catalog_product_search finds it).'),
                'all_languages' => self::boolParam('Return multilang fields in every language.', false),
            ],
            'shop' => true,                            // injects id_shop + switches shop context
            'lang' => true,                            // injects lang + switches language context
            'permission' => ['AdminProducts', 'view'], // BO right of the key's employee
            'keywords' => ['item', 'article'],         // helps mcp_tool_search
            'handler' => 'productGet',
        ]];
    }

    public function productGet(array $args, ExecutionContext $ctx) { … return [...]; }
}
```

Register the group in `ToolRegistry::CORE_GROUPS` (already done for the
eight core owners: shop, catalog, order, customer, cart, diagnose, module, mcp).

### Definition keys

| key | meaning |
|---|---|
| `name` | required, `<owner>_<resource>_<action>`, ≤ 40 chars |
| `title`, `description` | required; description is what the model reads to pick the tool |
| `params` | compact JSON-schema per argument (`type`, `description`, `enum`, `minimum`, `maximum`, `default`, `items`, `required => true`) |
| `write` | changes data → needs key scope `write`, hidden in read-only mode, gets `dry_run` |
| `destructive` | deletes/overwrites non-restorable data |
| `idempotent` | defaults to `!write` |
| `scope` | `read` (default), `write`, `code` (source file access) |
| `permission` / `permissions` | `[Tab, view|add|edit|delete]`, or a list where ANY grants |
| `shop`, `lang` | inject and apply `id_shop` / `lang` |
| `handler` | public method `(array $args, ExecutionContext $ctx)` |

### Verbs

`list` (no/simple filter) · `search` (free text + filters) · `get` (one by id) ·
`create` · `update` · `delete` · `set` (one specific field/state, e.g.
`order_status_set`, `catalog_stock_set`) · `add` / `remove` (to a collection) ·
`explain` (why a value is what it is) · `diagnose` (owner) · `scan` (find problems) ·
`summary` (aggregates).

### ExecutionContext

* `$ctx->idShop`, `$ctx->idLang` — already resolved and applied to `Context`.
* `$ctx->dryRun` — write tools MUST compute everything and record changes,
  but not save, when true.
* `$ctx->recordChange($entity, $id, $field, $before, $after, $idShop, $idLang)` —
  call for **every field a write tool changes** (also in dry run). This feeds
  the tool result (`changes`) and the activity log. Unchanged values are ignored.
* `$ctx->addWarning($text)` — non-fatal remarks (e.g. "product is inactive").
* `$ctx->employee`, `$ctx->apiKey`.

### Results and errors

* Return a plain array. Keep it compact: ids, names, numbers; truncate long HTML
  (offer an argument to get it in full). Lists are paged with
  `self::paginationParams()` + `$this->paged($items, $total, $args, 'products')`.
* Monetary values: `$this->money($amount, $idCurrency)` → `{amount, currency, formatted}`
  or plain floats with an explicit `_tax_excl` / `_tax_incl` suffix.
* Model-fixable problems → `throw new ToolException($message, $hint)`. The hint
  says what to call or change. Unexpected exceptions are caught and logged.
* Write results are wrapped by the runner as
  `{dry_run, saved, changes: [...], result: <your array>}`.

### Multistore / multilang rules

* Shop-scoped data is read and written for `$ctx->idShop` only (context is switched).
  `Configuration::get($k, $idLang, $idShopGroup, $idShop)` — pass both group and shop.
* Multilang inputs accept a string (→ `$ctx->idLang`) or `{"en": "...", "lt": "..."}`:
  use `$this->multilangInput($value, $ctx, 'name')`. Only the given languages change.
* Multilang outputs: current language by default, all languages when
  `all_languages` is true, keyed by ISO code.
* Stock: `StockAvailable::getQuantityAvailableByProduct($id, $ipa, $idShop)`,
  `StockAvailable::outOfStock($id, $idShop)` (handles shared stock groups).
  `new Product($id, false, …)` does NOT load quantity/out_of_stock.
* **Never save a Product loaded with `$full = true`**: the constructor replaces `->price` with
  the computed final price (taxes/specific prices), so `save()` corrupts the base price. Load with
  `false` for writes (`$this->load('Product', …)` does).
* `StockAvailable::setQuantity()` on PS 8/9 also recalculates reserved quantities from open orders —
  expected core behaviour, mention it when a stock change also changes `reserved_quantity`.
* Test sites run in Docker containers: absolute symlinks created on the host do not resolve there.

## Testing

```bash
php7.1 -l file.php && php8.4 -l file.php
# deploy only your files into the test sites, then call:
scratchpad/mcp.sh "$URL" "$TOKEN" tools/call '{"name":"catalog_product_get","arguments":{"id_product":1}}'
```
