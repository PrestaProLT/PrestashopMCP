---
name: module_analysis
title: Analyse and explain a PrestaShop module
description: Methodology to explain how any installed module works - what to read, what to check, what to ignore.
when_to_use: The user asks how a module works, what it does, why it behaves a certain way, whether it is safe to remove/upgrade, or before changing anything a module manages.
---
# Analyse a PrestaShop module

Goal: explain what the module **actually does** (observed behaviour), not what its
marketing description says. Never guess — every statement should come from a tool result.

## 1. Locate and identify
1. `module_list` with `search` to find the technical name (directory name). Note installed /
   active in this shop / version on disk vs DB (`upgrade_pending` = files newer than the DB).
2. `module_explain` with the technical name. It returns identity, hooks, tables, config keys,
   controllers, tabs, overrides, docs (README/CHANGELOG) and the MCP tools it provides.
3. If the module provides MCP tools (owner = module name), prefer those tools for any change.

## 2. Technical analysis (in this order)
1. **Entry point** – main class: parent class (`PaymentModule`, `CarrierModule`, `Module`),
   `ps_versions_compliancy`, dependencies, `install()` / `uninstall()` (what it creates and
   whether uninstall cleans up).
2. **Hooks** – registered hooks (DB) vs implemented `hook*` methods.
   * registered but not implemented → dead registration (harmless, but a smell);
   * implemented but not registered → feature silently not running (common bug after upgrades);
   * display hooks = what the shopper sees; action hooks = business logic triggers.
   Read the important hook methods with `module_file_read` (line ranges, not whole files).
3. **Database** – its tables (exist? row counts), ObjectModel classes, `id_shop` / `id_lang`
   columns (multistore / multilang support).
4. **Configuration** – keys and current values (`shop_config_get` with the module prefix).
5. **Controllers** – front controllers (public URLs, AJAX endpoints: check token validation)
   and admin controllers/tabs.
6. **Overrides** – `module_override_list`. Overrides change core behaviour for the whole shop
   and are the first suspect in "core feature behaves strangely" problems.
7. **Templates** – only when the question is about display: theme overrides in
   `themes/<theme>/modules/<module>/` take precedence over the module's own templates.

## 3. Functional synthesis
Answer in this structure:
* **Purpose** (one sentence) and **category** (payment, shipping, catalog, marketing, SEO,
  CMS/content, checkout, admin tool, integration, security, theme…; primary + secondary).
* **Shopper-facing behaviour** (where it renders: hook → page).
* **Back-office features** (config page, tabs, cron jobs).
* **Data it owns** (tables, config) and what is lost on uninstall.
* **Interactions** – overrides, other modules it depends on, hooks it shares positions on.
* **Risks** – PS version compatibility, missing upgrade, unregistered hooks, overrides,
  unauthenticated AJAX endpoints, cron only via HTTP (100 s proxy limit).

## What to IGNORE (saves tokens, avoids false conclusions)
* `vendor/`, `node_modules/`, `.git/`, `tests/` – third-party code, not the module's logic
  (only note notable Composer dependencies).
* `translations/*`, `*.xlf`, `mails/` content – wording only.
* Minified / built assets (`*.min.js`, `*.min.css`, `views/dist/`, `_dev/` builds), images,
  fonts, `logo.png`.
* `index.php` files that only `header('Location: ../')` – PrestaShop directory guards.
* License headers, `@copyright` blocks, `config_xx.xml` (Module Manager cache).
* Upgrade scripts for very old versions – only read the latest few if the question is
  about upgrades.
* Do not read whole big files: search first (`module_code_search`), then read the lines.

## Safety
* Reading code needs an API key with the `code` scope.
* Never print secrets found in files or configuration (values are masked; keep it that way).
* Do not suggest editing module files on a live shop; propose the change and where it goes.
