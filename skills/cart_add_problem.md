---
name: cart_add_problem
title: Customer cannot add a product to the cart
description: Find why a specific customer (or visitors) cannot add product Y (combination, quantity) to the cart, and how to fix it.
when_to_use: "Client cannot add X to cart", "add to cart button missing/greyed", "says maximum quantity reached", "product cannot be ordered".
---
# Customer cannot add a product to the cart

## 1. Collect the facts (ask only for what is missing)
* Product: id, reference or name → `catalog_product_search`.
* Combination (size/colour) if the product has combinations → `catalog_combination_list`.
* Quantity attempted.
* Customer: email or id (or "not logged in") → `customer_search`. Their cart → `cart_search`.
* Shop (multistore) and the customer's country if known.
* The exact message the customer saw. Note: PrestaShop shows **"You already have the maximum
  quantity available for this product"** for several unrelated causes (catalog mode,
  `available_for_order` off, wrong combination) – do not trust the wording alone.

## 2. Run the diagnosis
`diagnose_cart_add` with id_product, id_product_attribute, quantity, id_customer (or email),
id_cart if the customer has a cart, id_country if known.

Read the result in this order: `verdict` → every `blocking` item (detail + fix) → `warnings`.
`can_add_but_cannot_checkout` means the product goes in the cart but the order will fail
later (no carrier/payment for that country or group, minimum purchase…) → continue with the
`checkout_problem` skill.

## 3. If the diagnosis passes but the customer still fails
* The problem is probably not PrestaShop core logic:
  1. `shop_log_list` with `object_type` "Cart" and the cart id, and recent severity ≥ 3 errors.
  2. Modules hooked on the cart/product (`module_hook_list` for `actionCartUpdateQuantityBefore`,
     `actionCartSave`, `displayProductAdditionalInfo`) and overrides of `Cart` / `CartController`
     (`module_override_list`) – overrides are a frequent cause.
  3. Theme / JavaScript problems (button not clickable) cannot be seen from MCP: ask for a
     screenshot or browser console error.

## 4. Fix
Use the tool named in each `fix` (e.g. `catalog_stock_set`, `catalog_product_update`).
Always `dry_run: true` first, show the changes, then apply. Re-run `diagnose_cart_add` to
confirm the verdict is `can_add`.

## Do not
* Do not enable "allow orders when out of stock" shop-wide (PS_ORDER_OUT_OF_STOCK) to fix one
  product – change the product's own out-of-stock behaviour.
* Do not change the customer's groups without the manager's explicit approval.
