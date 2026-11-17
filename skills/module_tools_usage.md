---
name: module_tools_usage
title: Finding the right tool, including module tools
description: How tool names are built, how to find the tool for a procedure, and what to do when a module has no MCP tools.
when_to_use: You are not sure which tool to call, the task concerns something a module manages (slider, menu, blog, banners, shipping module…), or a tool you expected does not exist.
---
# Finding the right tool

**Naming:** `<owner>_<resource>_<action>`.
* Core owners: `shop`, `catalog`, `order`, `customer`, `cart`, `diagnose`, `module`, `mcp`.
* Any other owner is a module's technical name: `ppslider_slide_update` = module **ppslider**,
  resource **slide**, action **update**.
* Actions: list, search, get, create, update, delete, set, add, remove, explain, diagnose,
  scan, summary. Write tools have a `dry_run` argument.

**Procedure "manager wants to change X":**
1. Is X managed by a module (slider, menu, banner, blog, reviews, shipping…)? →
   `mcp_tool_search` with the intent (e.g. "slider mobile"). If a module tool exists, use it.
2. Else check whether a module owns the feature: `module_list` with a search term, then
   `module_explain`. If the module has no MCP tools, explain where the setting is in the back
   office (module configuration page) – do not edit module tables or files directly.
3. Core catalog/order/customer data → core tools.
4. `mcp_tool_search` with an empty query prints the whole hierarchy (owners and their tools).

**When a tool is denied:** `mcp_whoami` shows the key's scopes (read/write/code), allowed
shops and the employee profile. Explain what right is missing instead of trying workarounds.
