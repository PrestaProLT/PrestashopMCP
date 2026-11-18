# Changelog

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
