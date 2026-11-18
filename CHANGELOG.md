# Changelog

## 1.3.0 — 2026-11-18

* **OAuth sign-in** for Claude (claude.ai, mobile apps, Desktop), ChatGPT and other MCP clients:
  discovery (RFC 9728 / RFC 8414 under `/.well-known/`), dynamic client registration limited to
  allowed redirect hosts, authorization code + PKCE S256, 1-hour access tokens with rotating refresh
  tokens, revocation, code-replay protection. Approval happens in the back office with a one-time
  code (no password form, the back-office URL is never revealed); the access becomes a normal API key.
* New **Approve connection** page; settings: OAuth sign-in, OAuth redirect hosts, access token lifetime.
* The module no longer has a sidebar section: it is opened from the Module Manager (Configure) and
  its pages are tabs at the top (Settings, Approve connection, API keys, Activity log, Tools & skills).
  Upgrading removes the old "PrestaPro MCP" menu.
* Renamed to "PrestaShop MCP - easy connect with AI".
* API keys list shows how each key was created (API key / OAuth app).

## 1.2.1 — 2026-11-18

* `module_hook_list` (module mode) now loads the module class, so hook methods inherited from a
  shared parent class or trait are seen. Before, they were all reported as MISSING (found on
  modules extending a common base class, e.g. carrier modules). If the class cannot be loaded,
  hooks a non-core parent class may implement are no longer flagged MISSING.

## 1.2.0 — 2026-11-18

* Features: `catalog_feature_create`, `catalog_feature_update`, `catalog_feature_delete` and
  `catalog_feature_value_create`, `catalog_feature_value_update`, `catalog_feature_value_delete`
  (predefined values only; custom per-product values are never created or edited).
* Attributes: `catalog_attribute_list`, `catalog_attribute_create`, `catalog_attribute_update`,
  `catalog_attribute_delete` and `catalog_attribute_value_create`, `catalog_attribute_value_update`,
  `catalog_attribute_value_delete` (select / radio / color, swatch colors).
* Duplicate names are refused (case and spacing ignored, per language) and the existing id is
  returned. Languages left out on create get the default-language text with a warning.
* Deleting a record still in use (products, combinations) needs `confirm_in_use: true`; dry run
  shows what would be affected. Deletes remove the record from every shop.
* New skill `catalog_features_attributes`. Core tool owners can now span several classes
  (`catalog` = `CatalogTools` + `CatalogDictionaryTools`).

## 1.1.0 — 2026-11-18

* `catalog_feature_list`: features of the shop and their predefined values.
* `catalog_product_feature_set`: assign predefined feature values to a product (replace or add
  per feature) and remove features. Custom free-text values and new features/values are refused;
  a custom value replaced by a predefined one is deleted, as the back office does. Search index
  and faceted-search cache are refreshed.
* `catalog_product_get` now returns feature and value ids and the custom flag.

## 1.0.0 — 2026-11-17

First release.

* MCP Streamable HTTP endpoint (`/module/ppmcp/server`), stateless, dual protocol era
  (2025-03-26 … 2025-11-25 and 2026-07-28).
* API keys acting as employees with scopes (read / write / code), shop restriction, expiry.
* Activity log with arguments, results (optional) and per-field changes; retention purge.
* Core tool groups: shop, catalog, order, customer, cart, diagnose, module, mcp.
* Skills (playbooks) exposed as tools, prompts and resources.
* `PpMcpToolProviderTrait` for other modules + example module `ppmcpexample`.
* Back office: configuration with client setup guide, API keys, activity log, tools & skills.
