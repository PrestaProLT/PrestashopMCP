---
name: banner_change
title: Change the promo banner
description: How to change the promo banner text or its desktop/mobile visibility safely.
when_to_use: The manager asks to change, hide or translate the top promo/announcement banner.
---
# Change the promo banner

1. `ppmcpexample_banner_get` — read the current texts (all languages) and device visibility.
2. Prepare the new text for **every active language** (ask for translations if missing; never
   leave one language with the old campaign text).
3. `ppmcpexample_banner_update` with `dry_run: true`; show the `changes` to the manager.
4. Call again without `dry_run`.
5. Multistore: repeat per shop (`id_shop`) — the banner is stored per shop.
