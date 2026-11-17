# Changelog

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
