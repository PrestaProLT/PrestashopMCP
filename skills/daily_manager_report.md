---
name: daily_manager_report
title: Daily shop manager check
description: Morning overview - sales, orders needing action, stock and catalog problems, errors, abandoned carts.
when_to_use: "What happened in the shop", "daily report", "what needs my attention today", weekly summaries.
---
# Daily manager check

Run read-only tools, then present one short report with action items first.

1. `shop_context_get` (once per conversation) – shop names, currency, multistore.
2. `order_stats_summary` for yesterday (and the same weekday last week for comparison):
   orders, revenue, average basket, top products.
3. Orders needing action: `order_search` with statuses such as awaiting payment / payment
   accepted / processing older than 1-2 days (`order_status_list` gives the ids).
4. Stock and catalog: `catalog_product_issue_scan` (active products out of stock that cannot
   be ordered, missing images/descriptions, price 0).
5. Abandoned carts with value: `cart_search` with `abandoned: true` for the last 24-48 h.
6. Errors: `shop_log_list` with `severity_min: 3` since yesterday – group repeated messages.
7. Report format:
   * **Needs action** (orders to ship / check, stock to replenish, errors) with ids;
   * **Numbers** (orders, revenue, vs last week);
   * **FYI** (catalog quality, abandoned carts).
Do not change anything during the report; propose actions and wait for approval.
