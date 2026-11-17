---
name: catalog_bulk_edit
title: Safe bulk catalog changes
description: Procedure for changing many products, prices, stock or texts without surprises in multistore / multilanguage shops.
when_to_use: Any change touching more than a handful of products - price updates, stock corrections, activating/deactivating, rewriting descriptions, fixing translations.
---
# Safe bulk catalog changes

1. **Scope precisely.** Build the product list with `catalog_product_search` (filters, paging
   with `limit`/`offset`) or `catalog_product_issue_scan`. Show the manager the count and a sample
   before changing anything. Never assume "all products" – confirm.
2. **Multistore.** `shop_context_get`: if multistore is active, ask which shops. Each call
   works on one `id_shop`; repeat per shop. Shared stock groups change stock for every shop in
   the group.
3. **Multilanguage.** Text fields are per language. Pass `{"en": "…", "lt": "…"}` for every
   language that must change. Never write English text into another language field. If a
   translation is missing, ask or write it in that language – and say so.
4. **Prices.** `price` is the tax-excluded base price. Combinations have price *impacts*.
   Discounts belong in specific prices (`catalog_specific_price_create`) – do not lower the base
   price for a temporary promotion. Check the result with `catalog_price_explain`.
5. **Dry run first.** Run the first 2-3 products with `dry_run: true`, show the `changes`
   (before → after), get approval, then apply.
6. **Batch and verify.** Apply in small batches (≤ 20 calls), stop on the first error and
   report it. Afterwards verify a sample with `catalog_product_get`.
7. **Report.** List product ids changed, and point to `mcp_log_list` (`writes_only: true`)
   – every change is logged with before/after values, which is also the way to revert.
