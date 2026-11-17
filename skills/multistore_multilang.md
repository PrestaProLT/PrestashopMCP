---
name: multistore_multilang
title: Working with multistore and multilanguage
description: Rules for shop ids, languages, shared stock and per-shop settings so changes land where intended.
when_to_use: The shop has several shops or languages (shop_context_get shows multistore_active or more than one language), or the manager mentions another country/site/language.
---
# Multistore and multilanguage rules

* `shop_context_get` lists shops, their domains, languages and currencies. Each shop has its
  own MCP endpoint; tools default to the endpoint's shop and accept `id_shop` (if the API key
  allows that shop).
* Product data: shared fields (reference, EAN, weight) are global; price, active, visibility,
  available_for_order, categories default etc. are **per shop**. A product can exist in one
  shop and not in another (`shops` in `catalog_product_get`).
* Stock is per shop unless the shop group shares stock – then a change affects every shop of
  the group.
* Configuration values can differ per shop and per shop group; always read them for the
  shop you talk about (`shop_config_get` with `id_shop`).
* Languages: `lang` accepts an ISO code (`lt`, `en`) or id. Text inputs accept
  `{"lt": "…", "en": "…"}`; only the given languages change. Write each language in that
  language; never copy one language's text into another without saying so.
* Language lists are per shop: a language can be inactive or not linked to a shop.
* When reporting numbers across shops, say per shop and in which currency.
