---
name: product_not_visible
title: Product not visible in the shop
description: Find why a product does not appear in its category, in search, or why its page returns 404 / redirects.
when_to_use: "Product not showing", "cannot find product in search", "product page 404", "product disappeared from category".
---
# Product not visible

1. `catalog_product_search` → id_product (search by reference if the name is uncertain).
2. `diagnose_product_visibility` with id_product (and id_customer / group if only some
   customers cannot see it). Typical causes it reports:
   * product inactive, or `visibility` = none / catalog only / search only;
   * not associated with this shop (multistore);
   * not in the expected category, or a parent category inactive;
   * customer group has no access to any of its categories;
   * redirect type set (301/302/404/410) on an inactive product;
   * search index not rebuilt (`indexed` = 0) – fix with the back-office search re-index;
   * faceted search cache not rebuilt (ps_facetedsearch) – category filters show stale lists.
3. Fix with `catalog_product_update` (active, visibility, id_category_default…) using
   `dry_run` first, then re-run the diagnosis.
4. If everything passes: theme or caching (Smarty cache, CDN/CloudFlare, full-page cache
   modules). Check `module_list` for cache modules and ask the manager to clear caches.
