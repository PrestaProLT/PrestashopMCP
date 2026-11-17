---
name: order_investigation
title: Investigate an order or a customer complaint
description: Gather everything about an order (payment, status history, shipping, messages) and act safely on statuses, tracking and notes.
when_to_use: "Where is my order", "customer says they paid", "wrong status", "add tracking number", "what happened with order X".
---
# Investigate an order

1. Find it: `order_search` by reference, id, customer email or date. Confirm the right order
   with the manager if several match.
2. `order_get`: products, totals, payments (amount and transaction ids), carrier and tracking,
   status history (who changed what and when), messages and private notes.
3. Typical checks
   * **Paid?** Compare `total_paid` with the sum of payments; the current status' `paid` flag.
   * **Stuck in "awaiting payment"** → `shop_log_list` with object_type "Cart" and the cart id
     for payment-module errors; the payment module's own tools or `module_explain`.
   * **Shipping** → carrier + tracking number; carrier modules may provide their own tools
     (`mcp_tool_search` "tracking").
   * **Customer history** → `customer_get` (previous orders, groups).
4. Changes – always explain and confirm first:
   * Status: `order_status_list`, then `order_status_set` with `dry_run: true`. Statuses can
     send emails to the customer and trigger stock/invoice changes – say which (flags `send_email`,
     `paid`, `shipped`). Do not move an order backwards (e.g. Delivered → Processing) unless the
     manager insists; never set "Payment accepted" without a confirmed payment.
   * Tracking: `order_tracking_set`.
   * Notes: `order_note_add` – `private` for internal notes, `customer` only when the manager
     wants the customer to read it.
5. Summarise: timeline, current state, what was changed (with request_id).
