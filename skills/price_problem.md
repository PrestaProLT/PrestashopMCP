---
name: price_problem
title: Explain a price
description: Explain why a product shows a given price for a customer (specific prices, group reductions, taxes, combinations, currency).
when_to_use: "Why is the price X", "discount not applied", "customer sees a different price", "price with/without VAT wrong".
---
# Explain a price

1. `catalog_product_search` → id_product (and `catalog_combination_list` for the variant).
2. `catalog_price_explain` with the product, combination, quantity and – if the complaint is
   about one customer – id_customer (their group, country and currency drive the result).
3. Read the explanation in order: base price → combination impact → specific price that won
   (priority: customer > group > country > currency > shop, then quantity) → group reduction →
   tax rule for the country → final price tax excl/incl.
4. Typical causes: a forgotten specific price with no end date, a group reduction, the
   customer's address country with another tax rate, prices displayed tax excl for B2B groups,
   catalog price rules, a module overriding the price (`module_override_list` for Product).
5. Changes: specific prices via `catalog_specific_price_*`; base price via
   `catalog_product_update` – always `dry_run` first.
