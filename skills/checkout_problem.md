---
name: checkout_problem
title: Order cannot be completed (checkout problem)
description: Find why a cart cannot be turned into an order - carriers, payment methods, addresses, minimum amount, stock, cart rules.
when_to_use: "No shipping method available", "payment method missing", "cannot place order", "order fails at payment", abandoned carts investigation.
---
# Checkout problem

1. Find the cart: `cart_search` (by customer email/id, latest first) → `cart_get`.
2. `diagnose_checkout` with the id_cart. Read:
   * **products** – any line no longer orderable (stock, deactivated product, min quantity);
   * **carriers** – available ones and the exclusion reason for each other carrier
     (zone of the delivery country, customer group, weight/price ranges, product carrier
     restrictions, size limits);
   * **payments** – available payment modules and why others are excluded (country,
     currency, group, carrier restrictions, module disabled in the shop);
   * **minimum purchase**, **cart rules** validity;
   * **log evidence** – PrestaShop log entries for this cart (payment failures are logged here).
3. Payment modules may apply their own conditions (amount limits, API errors) that MCP cannot
   evaluate: check `shop_log_list` (search the module name) and the module's own tools or
   `module_explain`.
4. Fixes are configuration changes (carrier zones/ranges, payment restrictions) usually done
   in the back office – explain exactly where (menu path) unless an MCP tool exists for it.
5. Never create an order for the customer through MCP.
