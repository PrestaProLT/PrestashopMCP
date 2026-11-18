---
name: catalog_features_attributes
title: Managing features and attributes
description: How to create, rename, merge and delete product features, attributes and their values without duplicates or lost combinations.
when_to_use: The manager wants a new feature or attribute, a new value (size, color, material…), to fix or translate a name, to clean duplicates, or to delete one.
---
# Managing features and attributes

**Feature vs attribute.** A *feature* describes a product (Material: Cotton) and is shown in
the product details. An *attribute* builds *combinations* with their own stock and price
(Size: XL, Color: Red). Ask which one the manager means when it is not obvious.

| what | list | create | change | delete |
|---|---|---|---|---|
| feature | `catalog_feature_list` | `catalog_feature_create` | `catalog_feature_update` | `catalog_feature_delete` |
| feature value | `catalog_feature_list` + `id_feature` | `catalog_feature_value_create` | `catalog_feature_value_update` | `catalog_feature_value_delete` |
| attribute | `catalog_attribute_list` | `catalog_attribute_create` | `catalog_attribute_update` | `catalog_attribute_delete` |
| attribute value | `catalog_attribute_list` + `id_attribute_group` | `catalog_attribute_value_create` | `catalog_attribute_value_update` | `catalog_attribute_value_delete` |

Assign feature values to products with `catalog_product_feature_set` (predefined values only).

1. **Look before creating.** List first and search with `query`; near-duplicates ("Red",
   "red ", "Raudona") are the most common mess. Create tools refuse exact duplicates and return
   `existing_id` – use that record instead.
2. **Languages.** Pass every shop language: `{"en": "Red", "lt": "Raudona"}`. Languages left out
   get the default-language text and a warning – tell the manager which ones still need a
   translation, or fix them with the `*_update` tool.
3. **Multistore.** New features and attributes go to the current shop; `all_shops: true` adds them
   everywhere. Names are shared by every shop, so renaming changes them in all shops.
4. **Color attributes** (`group_type: color`) need `color: "#RRGGBB"` for each value.
5. **Deleting is permanent.** Always run with `dry_run: true` first and show the `affected` counts.
   - Feature / feature value: removed from every product.
   - Attribute value: PrestaShop **deletes every combination using it, with its stock**.
   - Attribute: its values are removed from combinations; combinations left without any
     attribute are deleted.

   When the record is in use the tool refuses until `confirm_in_use: true`. Only pass it after the
   manager explicitly approved the counts you showed.
6. **Merging duplicates.** Feature values: move products to the value you keep
   (`catalog_product_feature_set`, mode replace), check the old value has `products: 0`, then
   delete it. Attribute values cannot be merged here – combinations would be deleted; tell the
   manager to merge them in the back office product pages.
7. **Report** the ids created or changed; every change is in `mcp_log_list` with before/after.
