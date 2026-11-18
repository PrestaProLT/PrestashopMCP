<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Tool\Core;

use PrestaPro\PpMcp\Context\ExecutionContext;
use PrestaPro\PpMcp\Tool\AbstractToolGroup;
use PrestaPro\PpMcp\Tool\ToolException;

/**
 * Catalog dictionaries: features and their predefined values, attributes
 * (attribute groups such as "Size") and their values (such as "XL").
 *
 * Shares the "catalog" owner with CatalogTools. Names are global PrestaShop
 * records (not per shop), so changing one needs access to every shop it is
 * used in. Values are never created as "custom" (per-product free text):
 * only predefined values are managed here.
 */
class CatalogDictionaryTools extends AbstractToolGroup
{
    const GROUP_TYPES = ['select', 'radio', 'color'];

    public function owner(): string
    {
        return 'catalog';
    }

    public function tools(): array
    {
        $ml = function ($what, $create = false) {
            return [
                'type' => ['string', 'object'],
                'description' => $what . ' A string sets the current language; {"en": "...", "lt": "..."} sets the listed languages.'
                    . ($create ? ' Languages left out get the text of the shop default language (as the back office does); pass every language to translate.' : ' Only the listed languages change.'),
            ];
        };
        $confirm = self::boolParam('Required when the record is still in use; the response of a call without it says what would be affected.', false);

        $defs = [
            // ---------------------------------------------------------- features
            [
                'name' => 'catalog_feature_list',
                'title' => 'List product features',
                'description' => 'Lists the product features of the shop (Catalog > Attributes & Features > Features). Without id_feature: features with their number of predefined values and of products using them. With id_feature: the predefined values of that feature (id_feature_value), filterable by value text. Custom (per-product free text) values are never listed. Assign values with catalog_product_feature_set; manage them with catalog_feature_value_create/update/delete.',
                'params' => array_merge([
                    'id_feature' => ['type' => 'integer', 'minimum' => 1, 'description' => 'List the predefined values of this feature.'],
                    'query' => ['type' => 'string', 'description' => 'Part of the feature name (or of the value text when id_feature is given).'],
                ], self::paginationParams(50, 200)),
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminProducts', 'view'],
                'keywords' => ['feature', 'features', 'characteristic', 'specification', 'property', 'value'],
                'handler' => 'featureList',
            ],
            [
                'name' => 'catalog_feature_create',
                'title' => 'Create a feature',
                'description' => 'Creates a product feature (e.g. "Material"). Refused when a feature with the same name already exists in one of the given languages (case and surrounding spaces ignored); the existing id is returned in the error. Available in this shop, or every shop with all_shops=true. Add its values with catalog_feature_value_create.',
                'params' => [
                    'name' => $ml('Feature name.', true),
                    'all_shops' => self::boolParam('Make the feature available in every shop instead of this shop only.', false),
                ],
                'required' => ['name'],
                'write' => true,
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminFeatures', 'add'],
                'keywords' => ['feature', 'new feature', 'add feature', 'characteristic'],
                'handler' => 'featureCreate',
            ],
            [
                'name' => 'catalog_feature_update',
                'title' => 'Rename a feature',
                'description' => 'Renames a feature in one or more languages. The name is shared by every shop and product using the feature. Refused when another feature already has that name.',
                'params' => [
                    'id_feature' => self::idParam('Feature id (catalog_feature_list).'),
                    'name' => $ml('New name.'),
                ],
                'required' => ['name'],
                'write' => true,
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminFeatures', 'edit'],
                'keywords' => ['feature', 'rename', 'translate'],
                'handler' => 'featureUpdate',
            ],
            [
                'name' => 'catalog_feature_delete',
                'title' => 'Delete a feature',
                'description' => 'Deletes a feature with all its values and removes it from every product, in every shop. Cannot be undone. When products use it, the call is refused unless confirm_in_use=true; run with dry_run=true first to see the counts.',
                'params' => [
                    'id_feature' => self::idParam('Feature id.'),
                    'confirm_in_use' => $confirm,
                ],
                'write' => true,
                'destructive' => true,
                'shop' => true,
                'permission' => ['AdminFeatures', 'delete'],
                'keywords' => ['feature', 'remove feature'],
                'handler' => 'featureDelete',
            ],
            [
                'name' => 'catalog_feature_value_create',
                'title' => 'Add a feature value',
                'description' => 'Adds a predefined value to a feature (e.g. "Cotton" to "Material"). Refused when the feature already has that value in one of the given languages (case and surrounding spaces ignored); the existing id_feature_value is returned in the error. Assign it to products with catalog_product_feature_set.',
                'params' => [
                    'id_feature' => self::idParam('Feature id.'),
                    'value' => $ml('Value text.', true),
                ],
                'required' => ['value'],
                'write' => true,
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminFeatures', 'add'],
                'keywords' => ['feature value', 'add value', 'new value'],
                'handler' => 'featureValueCreate',
            ],
            [
                'name' => 'catalog_feature_value_update',
                'title' => 'Edit a feature value',
                'description' => 'Changes the text of a predefined feature value in one or more languages; every product using the value shows the new text. Custom (per-product) values cannot be edited here. Refused when the feature already has another value with that text.',
                'params' => [
                    'id_feature_value' => self::idParam('Predefined feature value id.'),
                    'value' => $ml('New text.'),
                ],
                'required' => ['value'],
                'write' => true,
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminFeatures', 'edit'],
                'keywords' => ['feature value', 'rename', 'translate', 'fix typo'],
                'handler' => 'featureValueUpdate',
            ],
            [
                'name' => 'catalog_feature_value_delete',
                'title' => 'Delete a feature value',
                'description' => 'Deletes a predefined feature value and removes it from every product. Cannot be undone. When products use it, the call is refused unless confirm_in_use=true; to merge duplicates, first move the products to the value you keep with catalog_product_feature_set.',
                'params' => [
                    'id_feature_value' => self::idParam('Predefined feature value id.'),
                    'confirm_in_use' => $confirm,
                ],
                'write' => true,
                'destructive' => true,
                'shop' => true,
                'permission' => ['AdminFeatures', 'delete'],
                'keywords' => ['feature value', 'remove value', 'duplicate'],
                'handler' => 'featureValueDelete',
            ],
            // -------------------------------------------------------- attributes
            [
                'name' => 'catalog_attribute_list',
                'title' => 'List attributes',
                'description' => 'Lists the attributes used to build combinations (Catalog > Attributes & Features > Attributes, e.g. "Size", "Color"). Without id_attribute_group: attributes with type (select / radio / color), public name, number of values and of combinations using them. With id_attribute_group: its values (id_attribute) with color, position and combination count, filterable by name.',
                'params' => array_merge([
                    'id_attribute_group' => ['type' => 'integer', 'minimum' => 1, 'description' => 'List the values of this attribute.'],
                    'query' => ['type' => 'string', 'description' => 'Part of the attribute name (or of the value name when id_attribute_group is given).'],
                ], self::paginationParams(50, 200)),
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminProducts', 'view'],
                'keywords' => ['attribute', 'attributes', 'size', 'color', 'colour', 'variant', 'combination', 'option'],
                'handler' => 'attributeList',
            ],
            [
                'name' => 'catalog_attribute_create',
                'title' => 'Create an attribute',
                'description' => 'Creates an attribute (attribute group) for combinations, e.g. "Size". name is the internal back-office name, public_name the name customers see (defaults to name). group_type: select (drop-down), radio (buttons) or color (swatches; values then need a color). Refused when an attribute with the same name exists. Add its values with catalog_attribute_value_create.',
                'params' => [
                    'name' => $ml('Internal name.', true),
                    'public_name' => $ml('Name shown to customers (default: name).', true),
                    'group_type' => ['type' => 'string', 'enum' => self::GROUP_TYPES, 'default' => 'select', 'description' => 'How customers choose the value on the product page.'],
                    'all_shops' => self::boolParam('Make the attribute available in every shop instead of this shop only.', false),
                ],
                'required' => ['name'],
                'write' => true,
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminAttributesGroups', 'add'],
                'keywords' => ['attribute', 'new attribute', 'size', 'color', 'variant'],
                'handler' => 'attributeCreate',
            ],
            [
                'name' => 'catalog_attribute_update',
                'title' => 'Edit an attribute',
                'description' => 'Changes an attribute (attribute group): internal name and/or public name per language, and/or group_type (select / radio / color). Shared by every shop and product using it. Refused when another attribute already has that internal name.',
                'params' => [
                    'id_attribute_group' => self::idParam('Attribute id (catalog_attribute_list).'),
                    'name' => $ml('Internal name.'),
                    'public_name' => $ml('Name shown to customers.'),
                    'group_type' => ['type' => 'string', 'enum' => self::GROUP_TYPES],
                ],
                'write' => true,
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminAttributesGroups', 'edit'],
                'keywords' => ['attribute', 'rename', 'translate', 'swatch'],
                'handler' => 'attributeUpdate',
            ],
            [
                'name' => 'catalog_attribute_delete',
                'title' => 'Delete an attribute',
                'description' => 'Deletes an attribute (attribute group) with all its values, in every shop. PrestaShop then removes the value from every combination and deletes combinations left without any attribute, with their stock. Cannot be undone. When combinations use it, the call is refused unless confirm_in_use=true; run with dry_run=true first.',
                'params' => [
                    'id_attribute_group' => self::idParam('Attribute id.'),
                    'confirm_in_use' => $confirm,
                ],
                'write' => true,
                'destructive' => true,
                'shop' => true,
                'permission' => ['AdminAttributesGroups', 'delete'],
                'keywords' => ['attribute', 'remove attribute'],
                'handler' => 'attributeDelete',
            ],
            [
                'name' => 'catalog_attribute_value_create',
                'title' => 'Add an attribute value',
                'description' => 'Adds a value to an attribute (e.g. "XL" to "Size", "Red" to "Color"). Color attributes need color (#RRGGBB). Refused when the attribute already has that value in one of the given languages; the existing id_attribute is returned in the error. Available in the shops of the attribute that this key may access.',
                'params' => [
                    'id_attribute_group' => self::idParam('Attribute id.'),
                    'name' => $ml('Value name.', true),
                    'color' => ['type' => 'string', 'pattern' => '^#[0-9A-Fa-f]{6}$', 'description' => 'Swatch color #RRGGBB (color attributes only).'],
                ],
                'required' => ['name'],
                'write' => true,
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminAttributesGroups', 'add'],
                'keywords' => ['attribute value', 'add value', 'size', 'color', 'variant option'],
                'handler' => 'attributeValueCreate',
            ],
            [
                'name' => 'catalog_attribute_value_update',
                'title' => 'Edit an attribute value',
                'description' => 'Changes an attribute value: name per language and/or swatch color (color attributes). Every combination using it shows the new name. Refused when the attribute already has another value with that name.',
                'params' => [
                    'id_attribute' => self::idParam('Attribute value id (catalog_attribute_list with id_attribute_group).'),
                    'name' => $ml('New name.'),
                    'color' => ['type' => 'string', 'pattern' => '^#[0-9A-Fa-f]{6}$', 'description' => 'Swatch color #RRGGBB.'],
                ],
                'write' => true,
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminAttributesGroups', 'edit'],
                'keywords' => ['attribute value', 'rename', 'translate', 'color', 'swatch'],
                'handler' => 'attributeValueUpdate',
            ],
            [
                'name' => 'catalog_attribute_value_delete',
                'title' => 'Delete an attribute value',
                'description' => 'Deletes an attribute value in every shop. PrestaShop deletes every combination that uses it, with its stock, and picks a new default combination where needed. Cannot be undone. When combinations use it, the call is refused unless confirm_in_use=true; run with dry_run=true first.',
                'params' => [
                    'id_attribute' => self::idParam('Attribute value id.'),
                    'confirm_in_use' => $confirm,
                ],
                'write' => true,
                'destructive' => true,
                'shop' => true,
                'permission' => ['AdminAttributesGroups', 'delete'],
                'keywords' => ['attribute value', 'remove value', 'duplicate'],
                'handler' => 'attributeValueDelete',
            ],
        ];

        // 'required' => [params] is shorthand for 'required' => true on each param.
        foreach ($defs as &$def) {
            if (isset($def['required'])) {
                foreach ((array) $def['required'] as $param) {
                    $def['params'][$param]['required'] = true;
                }
                unset($def['required']);
            }
        }
        unset($def);

        return $defs;
    }

    // ------------------------------------------------------------------
    // Features
    // ------------------------------------------------------------------

    public function featureList(array $args, ExecutionContext $ctx): array
    {
        $s = (int) $ctx->idShop;
        $l = (int) $ctx->idLang;
        $q = isset($args['query']) ? trim((string) $args['query']) : '';
        $warnings = [];
        if (\Configuration::hasKey('PS_FEATURE_FEATURE_ACTIVE') && !$this->conf('PS_FEATURE_FEATURE_ACTIVE', $s)) {
            $warnings[] = 'Features are disabled in this shop (PS_FEATURE_FEATURE_ACTIVE=0); they are not shown on the storefront.';
        }

        if (!empty($args['id_feature'])) {
            $idF = (int) $args['id_feature'];
            $feature = $this->featureRow($idF, $l, $s);
            $where = 'fv.`id_feature` = ' . $idF . ' AND ' . self::predefinedSql('fv');
            if ($q !== '') {
                $where .= ' AND fvl.`value` LIKE \'%' . self::likeValue($q) . '%\'';
            }
            $from = ' FROM `' . _DB_PREFIX_ . 'feature_value` fv
                LEFT JOIN `' . _DB_PREFIX_ . 'feature_value_lang` fvl ON fvl.`id_feature_value` = fv.`id_feature_value` AND fvl.`id_lang` = ' . $l . '
                WHERE ' . $where;
            $total = (int) $this->db()->getValue('SELECT COUNT(*)' . $from);
            $rows = $this->db()->executeS(
                'SELECT fv.`id_feature_value`, fvl.`value`,
                    (SELECT COUNT(DISTINCT fp.`id_product`) FROM `' . _DB_PREFIX_ . 'feature_product` fp WHERE fp.`id_feature_value` = fv.`id_feature_value`) AS products'
                . $from . ' ORDER BY fvl.`value` LIMIT ' . (int) $args['offset'] . ', ' . (int) $args['limit']
            ) ?: [];
            $items = [];
            foreach ($rows as $r) {
                $items[] = ['id_feature_value' => (int) $r['id_feature_value'], 'value' => (string) $r['value'], 'products' => (int) $r['products']];
            }
            $out = $this->paged($items, $total, $args, 'values');
            $out['feature'] = ['id_feature' => $feature['id_feature'], 'name' => $feature['name']];
            $out['_warnings'] = $warnings;

            return $out;
        }

        $where = '1';
        if ($q !== '') {
            $where = 'fl.`name` LIKE \'%' . self::likeValue($q) . '%\'';
        }
        $from = ' FROM `' . _DB_PREFIX_ . 'feature` f
            INNER JOIN `' . _DB_PREFIX_ . 'feature_shop` fs ON fs.`id_feature` = f.`id_feature` AND fs.`id_shop` = ' . $s . '
            LEFT JOIN `' . _DB_PREFIX_ . 'feature_lang` fl ON fl.`id_feature` = f.`id_feature` AND fl.`id_lang` = ' . $l . '
            WHERE ' . $where;
        $total = (int) $this->db()->getValue('SELECT COUNT(*)' . $from);
        $rows = $this->db()->executeS(
            'SELECT f.`id_feature`, f.`position`, fl.`name`,
                (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'feature_value` fv WHERE fv.`id_feature` = f.`id_feature` AND ' . self::predefinedSql('fv') . ') AS predefined_values,
                (SELECT COUNT(DISTINCT fp.`id_product`) FROM `' . _DB_PREFIX_ . 'feature_product` fp WHERE fp.`id_feature` = f.`id_feature`) AS products'
            . $from . ' ORDER BY f.`position`, fl.`name` LIMIT ' . (int) $args['offset'] . ', ' . (int) $args['limit']
        ) ?: [];
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id_feature' => (int) $r['id_feature'],
                'name' => (string) $r['name'],
                'position' => (int) $r['position'],
                'predefined_values' => (int) $r['predefined_values'],
                'products' => (int) $r['products'],
            ];
        }
        $out = $this->paged($items, $total, $args, 'features');
        $out['_warnings'] = $warnings;

        return $out;
    }

    public function featureCreate(array $args, ExecutionContext $ctx): array
    {
        $names = $this->langValues($args['name'], $ctx, 'name', true);
        $this->assertNoDuplicate('feature', $names, 0, 0);
        $shops = $this->targetShops($ctx, !empty($args['all_shops']), 'A feature for all shops');

        $f = new \Feature();
        $f->name = $names;
        $this->validateObject($f, ['name' => $names]);
        $ctx->recordChange('feature', 'new', 'created', null, $this->labelOf($names, $ctx), null, (int) $ctx->idLang);
        $ctx->recordChange('feature', 'new', 'shops', null, implode(',', $shops));
        if (!$ctx->dryRun) {
            $f->id_shop_list = $shops;
            if (!$f->add()) {
                throw new ToolException('PrestaShop refused to create the feature.');
            }
            $ctx->resolveCreatedId('feature', (int) $f->id);
            $this->afterDictionaryChange();
        }

        return ['id_feature' => $ctx->dryRun ? null : (int) $f->id, 'name' => $this->labelOf($names, $ctx), 'shops' => $shops];
    }

    public function featureUpdate(array $args, ExecutionContext $ctx): array
    {
        $id = (int) $args['id_feature'];
        $row = $this->featureRow($id, (int) $ctx->idLang, 0);
        $this->assertRecordShopsAllowed($ctx, 'feature_shop', 'id_feature', $id, sprintf('Feature #%d', $id));
        $f = new \Feature($id);
        $names = $this->langValues($args['name'], $ctx, 'name', false);
        $this->assertNoDuplicate('feature', $names, 0, $id);
        $this->validateObject($f, ['name' => $names]);
        $changed = $this->applyLang($f, 'name', $names, 'feature', $id, $ctx);
        if ($changed && !$ctx->dryRun) {
            if (!$f->update()) {
                throw new ToolException('PrestaShop refused to save the feature.');
            }
            $this->afterDictionaryChange();
        }

        return ['id_feature' => $id, 'fields_changed' => $changed, 'products_using' => $row['products']];
    }

    public function featureDelete(array $args, ExecutionContext $ctx): array
    {
        $id = (int) $args['id_feature'];
        $row = $this->featureRow($id, (int) $ctx->idLang, 0);
        $this->assertRecordShopsAllowed($ctx, 'feature_shop', 'id_feature', $id, sprintf('Feature #%d', $id));
        $values = (int) $this->db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'feature_value` WHERE `id_feature` = ' . $id);
        $usage = ['products' => $row['products'], 'values' => $values];
        $this->assertDeleteConfirmed($args, $ctx, $row['products'] > 0, sprintf('Feature "%s" is set on %d product(s) and has %d value(s).', $row['name'], $row['products'], $values));

        $ctx->recordChange('feature', $id, 'deleted', $row['name'] . ' (#' . $id . ')', null);
        if (!$ctx->dryRun) {
            $this->deleteEverywhere(new \Feature($id), 'feature_shop', 'id_feature', $ctx);
            $this->afterDictionaryChange();
        }

        return ['id_feature' => $id, 'deleted' => !$ctx->dryRun, 'affected' => $usage];
    }

    public function featureValueCreate(array $args, ExecutionContext $ctx): array
    {
        $idF = (int) $args['id_feature'];
        $feature = $this->featureRow($idF, (int) $ctx->idLang, (int) $ctx->idShop);
        $values = $this->langValues($args['value'], $ctx, 'value', true);
        $this->assertNoDuplicate('feature_value', $values, $idF, 0);

        $fv = new \FeatureValue();
        $fv->id_feature = $idF;
        $fv->custom = 0;
        $fv->value = $values;
        $this->validateObject($fv, ['value' => $values]);
        $ctx->recordChange('feature_value', 'new', 'created', null, $feature['name'] . ': ' . $this->labelOf($values, $ctx), null, (int) $ctx->idLang);
        if (!$ctx->dryRun) {
            if (!$fv->add()) {
                throw new ToolException('PrestaShop refused to create the feature value.');
            }
            $ctx->resolveCreatedId('feature_value', (int) $fv->id);
            $this->afterDictionaryChange();
        }

        return ['id_feature_value' => $ctx->dryRun ? null : (int) $fv->id, 'id_feature' => $idF, 'value' => $this->labelOf($values, $ctx)];
    }

    public function featureValueUpdate(array $args, ExecutionContext $ctx): array
    {
        $id = (int) $args['id_feature_value'];
        $fv = $this->loadPredefinedValue($id);
        $idF = (int) $fv->id_feature;
        $this->assertRecordShopsAllowed($ctx, 'feature_shop', 'id_feature', $idF, sprintf('Feature #%d', $idF));
        $values = $this->langValues($args['value'], $ctx, 'value', false);
        $this->assertNoDuplicate('feature_value', $values, $idF, $id);
        $this->validateObject($fv, ['value' => $values]);
        $changed = $this->applyLang($fv, 'value', $values, 'feature_value', $id, $ctx);
        if ($changed && !$ctx->dryRun) {
            if (!$fv->update()) {
                throw new ToolException('PrestaShop refused to save the feature value.');
            }
            $this->afterDictionaryChange();
        }

        return ['id_feature_value' => $id, 'id_feature' => $idF, 'fields_changed' => $changed, 'products_using' => $this->featureValueProducts($id)];
    }

    public function featureValueDelete(array $args, ExecutionContext $ctx): array
    {
        $id = (int) $args['id_feature_value'];
        $fv = $this->loadPredefinedValue($id);
        $idF = (int) $fv->id_feature;
        $this->assertRecordShopsAllowed($ctx, 'feature_shop', 'id_feature', $idF, sprintf('Feature #%d', $idF));
        $products = $this->featureValueProducts($id);
        $label = $this->pick($fv->value, (int) $ctx->idLang);
        $this->assertDeleteConfirmed($args, $ctx, $products > 0, sprintf('Value "%s" is set on %d product(s).', $label, $products));

        $ctx->recordChange('feature_value', $id, 'deleted', $label . ' (#' . $id . ', feature #' . $idF . ')', null);
        if (!$ctx->dryRun) {
            if (!$fv->delete()) {
                throw new ToolException('PrestaShop refused to delete the feature value.');
            }
            $this->afterDictionaryChange();
        }

        return ['id_feature_value' => $id, 'deleted' => !$ctx->dryRun, 'affected' => ['products' => $products]];
    }

    // ------------------------------------------------------------------
    // Attributes
    // ------------------------------------------------------------------

    public function attributeList(array $args, ExecutionContext $ctx): array
    {
        $s = (int) $ctx->idShop;
        $l = (int) $ctx->idLang;
        $q = isset($args['query']) ? trim((string) $args['query']) : '';

        if (!empty($args['id_attribute_group'])) {
            $idG = (int) $args['id_attribute_group'];
            $group = $this->groupRow($idG, $l, $s);
            $where = 'a.`id_attribute_group` = ' . $idG;
            if ($q !== '') {
                $where .= ' AND al.`name` LIKE \'%' . self::likeValue($q) . '%\'';
            }
            $from = ' FROM `' . _DB_PREFIX_ . 'attribute` a
                INNER JOIN `' . _DB_PREFIX_ . 'attribute_shop` ats ON ats.`id_attribute` = a.`id_attribute` AND ats.`id_shop` = ' . $s . '
                LEFT JOIN `' . _DB_PREFIX_ . 'attribute_lang` al ON al.`id_attribute` = a.`id_attribute` AND al.`id_lang` = ' . $l . '
                WHERE ' . $where;
            $total = (int) $this->db()->getValue('SELECT COUNT(*)' . $from);
            $rows = $this->db()->executeS(
                'SELECT a.`id_attribute`, a.`color`, a.`position`, al.`name`,
                    (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac WHERE pac.`id_attribute` = a.`id_attribute`) AS combinations'
                . $from . ' ORDER BY a.`position`, al.`name` LIMIT ' . (int) $args['offset'] . ', ' . (int) $args['limit']
            ) ?: [];
            $items = [];
            foreach ($rows as $r) {
                $item = ['id_attribute' => (int) $r['id_attribute'], 'name' => (string) $r['name'], 'position' => (int) $r['position'], 'combinations' => (int) $r['combinations']];
                if ($group['group_type'] === 'color') {
                    $item['color'] = (string) $r['color'];
                }
                $items[] = $item;
            }
            $out = $this->paged($items, $total, $args, 'values');
            $out['attribute'] = $group;

            return $out;
        }

        $where = '1';
        if ($q !== '') {
            $where = '(agl.`name` LIKE \'%' . self::likeValue($q) . '%\' OR agl.`public_name` LIKE \'%' . self::likeValue($q) . '%\')';
        }
        $from = ' FROM `' . _DB_PREFIX_ . 'attribute_group` ag
            INNER JOIN `' . _DB_PREFIX_ . 'attribute_group_shop` ags ON ags.`id_attribute_group` = ag.`id_attribute_group` AND ags.`id_shop` = ' . $s . '
            LEFT JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl ON agl.`id_attribute_group` = ag.`id_attribute_group` AND agl.`id_lang` = ' . $l . '
            WHERE ' . $where;
        $total = (int) $this->db()->getValue('SELECT COUNT(*)' . $from);
        $rows = $this->db()->executeS(
            'SELECT ag.`id_attribute_group`, ag.`group_type`, ag.`position`, agl.`name`, agl.`public_name`,
                (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'attribute` a WHERE a.`id_attribute_group` = ag.`id_attribute_group`) AS `values`,
                (SELECT COUNT(DISTINCT pac.`id_product_attribute`) FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
                    INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.`id_attribute` = pac.`id_attribute`
                    WHERE a.`id_attribute_group` = ag.`id_attribute_group`) AS combinations'
            . $from . ' ORDER BY ag.`position`, agl.`name` LIMIT ' . (int) $args['offset'] . ', ' . (int) $args['limit']
        ) ?: [];
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id_attribute_group' => (int) $r['id_attribute_group'],
                'name' => (string) $r['name'],
                'public_name' => (string) $r['public_name'],
                'group_type' => (string) $r['group_type'],
                'position' => (int) $r['position'],
                'values' => (int) $r['values'],
                'combinations' => (int) $r['combinations'],
            ];
        }

        return $this->paged($items, $total, $args, 'attributes');
    }

    public function attributeCreate(array $args, ExecutionContext $ctx): array
    {
        $names = $this->langValues($args['name'], $ctx, 'name', true);
        $public = isset($args['public_name']) ? $this->langValues($args['public_name'], $ctx, 'public_name', true) : $names;
        $type = isset($args['group_type']) ? (string) $args['group_type'] : 'select';
        $this->assertNoDuplicate('attribute', $names, 0, 0);
        $shops = $this->targetShops($ctx, !empty($args['all_shops']), 'An attribute for all shops');

        $g = new \AttributeGroup();
        $g->name = $names;
        $g->public_name = $public;
        $g->group_type = $type;
        $g->is_color_group = $type === 'color' ? 1 : 0;
        $this->validateObject($g, ['name' => $names, 'public_name' => $public]);
        $ctx->recordChange('attribute_group', 'new', 'created', null, $this->labelOf($names, $ctx) . ' [' . $type . ']', null, (int) $ctx->idLang);
        $ctx->recordChange('attribute_group', 'new', 'shops', null, implode(',', $shops));
        if (!$ctx->dryRun) {
            $g->id_shop_list = $shops;
            if (!$g->add()) {
                throw new ToolException('PrestaShop refused to create the attribute.');
            }
            $ctx->resolveCreatedId('attribute_group', (int) $g->id);
            $this->afterDictionaryChange();
        }

        return ['id_attribute_group' => $ctx->dryRun ? null : (int) $g->id, 'name' => $this->labelOf($names, $ctx), 'group_type' => $type, 'shops' => $shops];
    }

    public function attributeUpdate(array $args, ExecutionContext $ctx): array
    {
        $id = (int) $args['id_attribute_group'];
        $this->groupRow($id, (int) $ctx->idLang, 0);
        $this->assertRecordShopsAllowed($ctx, 'attribute_group_shop', 'id_attribute_group', $id, sprintf('Attribute #%d', $id));
        if (!isset($args['name']) && !isset($args['public_name']) && !isset($args['group_type'])) {
            throw new ToolException('Nothing to do.', 'Pass name, public_name and/or group_type.');
        }
        $g = new \AttributeGroup($id);
        $changed = 0;
        $check = [];
        if (isset($args['name'])) {
            $names = $this->langValues($args['name'], $ctx, 'name', false);
            $this->assertNoDuplicate('attribute', $names, 0, $id);
            $check['name'] = $names;
        }
        if (isset($args['public_name'])) {
            $check['public_name'] = $this->langValues($args['public_name'], $ctx, 'public_name', false);
        }
        $this->validateObject($g, $check);
        foreach ($check as $field => $vals) {
            $changed += $this->applyLang($g, $field, $vals, 'attribute_group', $id, $ctx);
        }
        if (isset($args['group_type']) && (string) $args['group_type'] !== (string) $g->group_type) {
            $type = (string) $args['group_type'];
            $ctx->recordChange('attribute_group', $id, 'group_type', (string) $g->group_type, $type);
            $g->group_type = $type;
            $g->is_color_group = $type === 'color' ? 1 : 0;
            ++$changed;
            if ($type === 'color') {
                $noColor = (int) $this->db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'attribute` WHERE `id_attribute_group` = ' . $id . ' AND (`color` IS NULL OR `color` = \'\')');
                if ($noColor) {
                    $ctx->addWarning(sprintf('%d value(s) have no color yet; set them with catalog_attribute_value_update (color).', $noColor));
                }
            }
        }
        if ($changed && !$ctx->dryRun) {
            if (!$g->update()) {
                throw new ToolException('PrestaShop refused to save the attribute.');
            }
            $this->afterDictionaryChange();
        }

        return ['id_attribute_group' => $id, 'fields_changed' => $changed];
    }

    public function attributeDelete(array $args, ExecutionContext $ctx): array
    {
        $id = (int) $args['id_attribute_group'];
        $row = $this->groupRow($id, (int) $ctx->idLang, 0);
        $this->assertRecordShopsAllowed($ctx, 'attribute_group_shop', 'id_attribute_group', $id, sprintf('Attribute #%d', $id));
        $products = (int) $this->db()->getValue(
            'SELECT COUNT(DISTINCT pa.`id_product`) FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
            INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.`id_attribute` = pac.`id_attribute`
            INNER JOIN `' . _DB_PREFIX_ . 'product_attribute` pa ON pa.`id_product_attribute` = pac.`id_product_attribute`
            WHERE a.`id_attribute_group` = ' . $id
        );
        $usage = ['values' => $row['values'], 'combinations' => $row['combinations'], 'products' => $products];
        $this->assertDeleteConfirmed($args, $ctx, $row['combinations'] > 0, sprintf(
            'Attribute "%s" is used by %d combination(s) of %d product(s); combinations left without any attribute are deleted with their stock.',
            $row['name'],
            $row['combinations'],
            $products
        ));

        $ctx->recordChange('attribute_group', $id, 'deleted', $row['name'] . ' (#' . $id . ', ' . $row['values'] . ' values)', null);
        if (!$ctx->dryRun) {
            $this->deleteEverywhere(new \AttributeGroup($id), 'attribute_group_shop', 'id_attribute_group', $ctx);
            $this->afterDictionaryChange();
        }

        return ['id_attribute_group' => $id, 'deleted' => !$ctx->dryRun, 'affected' => $usage];
    }

    public function attributeValueCreate(array $args, ExecutionContext $ctx): array
    {
        $idG = (int) $args['id_attribute_group'];
        $group = $this->groupRow($idG, (int) $ctx->idLang, (int) $ctx->idShop);
        $names = $this->langValues($args['name'], $ctx, 'name', true);
        $this->assertNoDuplicate('attribute_value', $names, $idG, 0);
        $color = isset($args['color']) ? strtoupper((string) $args['color']) : '';
        if ($group['group_type'] === 'color' && $color === '') {
            throw new ToolException(sprintf('Attribute "%s" is a color attribute: pass color (#RRGGBB).', $group['name']));
        }
        if ($group['group_type'] !== 'color' && $color !== '') {
            $ctx->addWarning('color is only shown for color attributes; it is stored but not displayed.');
        }
        // The value is available where its attribute is, limited to the shops this key may access.
        $shops = array_values(array_filter($this->recordShops('attribute_group_shop', 'id_attribute_group', $idG), function ($id) use ($ctx) {
            return $ctx->apiKey->allowsShop($id);
        }));

        $class = self::attributeClass();
        $a = new $class();
        $a->id_attribute_group = $idG;
        $a->name = $names;
        $a->color = $color;
        $this->validateObject($a, ['name' => $names]);
        $ctx->recordChange('attribute', 'new', 'created', null, $group['name'] . ': ' . $this->labelOf($names, $ctx) . ($color !== '' ? ' ' . $color : ''), null, (int) $ctx->idLang);
        $ctx->recordChange('attribute', 'new', 'shops', null, implode(',', $shops));
        if (!$ctx->dryRun) {
            $a->id_shop_list = $shops;
            if (!$a->add()) {
                throw new ToolException('PrestaShop refused to create the attribute value.');
            }
            $ctx->resolveCreatedId('attribute', (int) $a->id);
            $this->afterDictionaryChange();
        }

        return ['id_attribute' => $ctx->dryRun ? null : (int) $a->id, 'id_attribute_group' => $idG, 'name' => $this->labelOf($names, $ctx), 'shops' => $shops];
    }

    public function attributeValueUpdate(array $args, ExecutionContext $ctx): array
    {
        $id = (int) $args['id_attribute'];
        $a = $this->loadAttributeValue($id);
        $idG = (int) $a->id_attribute_group;
        $this->assertRecordShopsAllowed($ctx, 'attribute_shop', 'id_attribute', $id, sprintf('Attribute value #%d', $id));
        if (!isset($args['name']) && !isset($args['color'])) {
            throw new ToolException('Nothing to do.', 'Pass name and/or color.');
        }
        $changed = 0;
        if (isset($args['name'])) {
            $names = $this->langValues($args['name'], $ctx, 'name', false);
            $this->assertNoDuplicate('attribute_value', $names, $idG, $id);
            $this->validateObject($a, ['name' => $names]);
            $changed += $this->applyLang($a, 'name', $names, 'attribute', $id, $ctx);
        }
        if (isset($args['color'])) {
            $color = strtoupper((string) $args['color']);
            if ($color !== strtoupper((string) $a->color)) {
                $ctx->recordChange('attribute', $id, 'color', (string) $a->color, $color);
                $a->color = $color;
                ++$changed;
            }
        }
        if ($changed && !$ctx->dryRun) {
            if (!$a->update()) {
                throw new ToolException('PrestaShop refused to save the attribute value.');
            }
            $this->afterDictionaryChange();
        }

        return ['id_attribute' => $id, 'id_attribute_group' => $idG, 'fields_changed' => $changed, 'combinations_using' => $this->attributeCombinations($id)];
    }

    public function attributeValueDelete(array $args, ExecutionContext $ctx): array
    {
        $id = (int) $args['id_attribute'];
        $a = $this->loadAttributeValue($id);
        $this->assertRecordShopsAllowed($ctx, 'attribute_shop', 'id_attribute', $id, sprintf('Attribute value #%d', $id));
        $combinations = $this->attributeCombinations($id);
        $products = (int) $this->db()->getValue(
            'SELECT COUNT(DISTINCT pa.`id_product`) FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
            INNER JOIN `' . _DB_PREFIX_ . 'product_attribute` pa ON pa.`id_product_attribute` = pac.`id_product_attribute`
            WHERE pac.`id_attribute` = ' . $id
        );
        $label = $this->pick($a->name, (int) $ctx->idLang);
        $this->assertDeleteConfirmed($args, $ctx, $combinations > 0, sprintf(
            'Value "%s" is used by %d combination(s) of %d product(s); PrestaShop deletes those combinations with their stock.',
            $label,
            $combinations,
            $products
        ));

        $ctx->recordChange('attribute', $id, 'deleted', $label . ' (#' . $id . ', attribute #' . (int) $a->id_attribute_group . ')', null);
        if (!$ctx->dryRun) {
            $this->deleteEverywhere($a, 'attribute_shop', 'id_attribute', $ctx);
            $this->afterDictionaryChange();
        }

        return ['id_attribute' => $id, 'deleted' => !$ctx->dryRun, 'affected' => ['combinations' => $combinations, 'products' => $products]];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Multilang input as [id_lang => trimmed text]. On create, languages left
     * out get the default-language text (or the first given one).
     *
     * @throws ToolException
     */
    private function langValues($input, ExecutionContext $ctx, string $field, bool $fillMissing): array
    {
        $vals = [];
        foreach ($this->multilangInput($input, $ctx, $field) as $idLang => $v) {
            $v = trim(preg_replace('/\s+/u', ' ', (string) $v));
            if ($v === '') {
                throw new ToolException(sprintf('%s (%s) cannot be empty.', $field, $this->isoForLang($idLang)));
            }
            $vals[$idLang] = $v;
        }
        if (!$vals) {
            throw new ToolException(sprintf('%s is required.', $field));
        }
        if ($fillMissing) {
            $default = (int) \Configuration::get('PS_LANG_DEFAULT');
            $source = isset($vals[$default]) ? $vals[$default] : reset($vals);
            $copied = [];
            foreach ($this->languageMap() as $iso => $idLang) {
                if (!isset($vals[$idLang])) {
                    $vals[$idLang] = $source;
                    $copied[] = $iso;
                }
            }
            if ($copied) {
                $ctx->addWarning(sprintf('%s: languages %s got the text "%s" (not translated); update them with the matching *_update tool.', $field, implode(', ', $copied), $source));
            }
        }

        return $vals;
    }

    /**
     * Same name (case and spacing ignored) in the same language.
     *
     * @param string $kind feature | feature_value | attribute | attribute_value
     *
     * @throws ToolException
     */
    private function assertNoDuplicate(string $kind, array $vals, int $parentId, int $excludeId): void
    {
        $p = _DB_PREFIX_;
        switch ($kind) {
            case 'feature':
                $sql = 'SELECT l.`id_feature` AS id, l.`id_lang`, l.`name` AS txt FROM `' . $p . 'feature_lang` l WHERE 1';
                $what = 'A feature';
                $tool = 'catalog_feature_list';
                break;
            case 'feature_value':
                $sql = 'SELECT l.`id_feature_value` AS id, l.`id_lang`, l.`value` AS txt FROM `' . $p . 'feature_value_lang` l
                    INNER JOIN `' . $p . 'feature_value` v ON v.`id_feature_value` = l.`id_feature_value`
                    WHERE v.`id_feature` = ' . $parentId . ' AND ' . self::predefinedSql('v');
                $what = 'A value of this feature';
                $tool = 'catalog_feature_list with id_feature';
                break;
            case 'attribute':
                $sql = 'SELECT l.`id_attribute_group` AS id, l.`id_lang`, l.`name` AS txt FROM `' . $p . 'attribute_group_lang` l WHERE 1';
                $what = 'An attribute';
                $tool = 'catalog_attribute_list';
                break;
            default:
                $sql = 'SELECT l.`id_attribute` AS id, l.`id_lang`, l.`name` AS txt FROM `' . $p . 'attribute_lang` l
                    INNER JOIN `' . $p . 'attribute` v ON v.`id_attribute` = l.`id_attribute`
                    WHERE v.`id_attribute_group` = ' . $parentId;
                $what = 'A value of this attribute';
                $tool = 'catalog_attribute_list with id_attribute_group';
        }
        $col = $kind === 'feature_value' ? 'value' : 'name';
        $or = [];
        foreach ($vals as $idLang => $v) {
            // The collation match is a superset (it may also ignore accents); the exact check is below.
            $or[] = '(l.`id_lang` = ' . (int) $idLang . ' AND TRIM(l.`' . $col . '`) = \'' . pSQL($v) . '\')';
        }
        $rows = $this->db()->executeS($sql . ($excludeId ? ' AND l.`' . ($kind === 'feature' ? 'id_feature' : ($kind === 'feature_value' ? 'id_feature_value' : ($kind === 'attribute' ? 'id_attribute_group' : 'id_attribute'))) . '` <> ' . $excludeId : '') . ' AND (' . implode(' OR ', $or) . ')') ?: [];
        foreach ($rows as $r) {
            $idLang = (int) $r['id_lang'];
            if (isset($vals[$idLang]) && self::norm($r['txt']) === self::norm($vals[$idLang])) {
                throw new ToolException(
                    sprintf('%s named "%s" (%s) already exists: #%d.', $what, trim($r['txt']), $this->isoForLang($idLang), (int) $r['id']),
                    'Use the existing record (' . $tool . '), or choose another name.',
                    ['existing_id' => (int) $r['id']]
                );
            }
        }
    }

    private static function norm($s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $s));

        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    /**
     * @throws ToolException
     */
    private function validateObject(\ObjectModel $obj, array $langFields): void
    {
        $errors = [];
        foreach ($langFields as $field => $vals) {
            foreach ($vals as $idLang => $v) {
                $check = $obj->validateField($field, $v, $idLang, [], true);
                if ($check !== true) {
                    $errors[] = $field . ' (' . $this->isoForLang($idLang) . '): ' . (is_string($check) ? $check : 'invalid value');
                }
            }
        }
        if ($errors) {
            throw new ToolException('Validation failed: ' . implode(' | ', $errors), 'Nothing was saved.');
        }
    }

    /** Writes the changed languages into $obj->$field and records them. Returns the number of changes. */
    private function applyLang(\ObjectModel $obj, string $field, array $vals, string $entity, int $id, ExecutionContext $ctx): int
    {
        $current = is_array($obj->$field) ? $obj->$field : [];
        $changed = 0;
        foreach ($vals as $idLang => $v) {
            $before = isset($current[$idLang]) ? (string) $current[$idLang] : '';
            if ($before !== $v) {
                $ctx->recordChange($entity, $id, $field, $before, $v, null, $idLang);
                $current[$idLang] = $v;
                ++$changed;
            }
        }
        $obj->$field = $current;

        return $changed;
    }

    /**
     * Shops a new feature / attribute is attached to.
     *
     * @throws \PrestaPro\PpMcp\Tool\PermissionDeniedException
     */
    private function targetShops(ExecutionContext $ctx, bool $allShops, string $what): array
    {
        if (!$allShops) {
            return [(int) $ctx->idShop];
        }
        $this->assertAllShopsAllowed($ctx, $what);

        return array_map('intval', \Shop::getShops(false, null, true));
    }

    private function recordShops(string $table, string $col, int $id): array
    {
        return array_map('intval', array_column($this->db()->executeS(
            'SELECT `id_shop` FROM `' . _DB_PREFIX_ . bqSQL($table) . '` WHERE `' . bqSQL($col) . '` = ' . $id
        ) ?: [], 'id_shop'));
    }

    /**
     * Names are global: the key must be allowed in every shop the record is in.
     *
     * @throws \PrestaPro\PpMcp\Tool\PermissionDeniedException
     */
    private function assertRecordShopsAllowed(ExecutionContext $ctx, string $table, string $col, int $id, string $what): void
    {
        foreach ($this->recordShops($table, $col, $id) as $idShop) {
            $this->assertShopAllowed($ctx, $idShop, $what . ' (shared by all its shops)');
        }
    }

    /**
     * @throws ToolException
     */
    private function assertDeleteConfirmed(array $args, ExecutionContext $ctx, bool $inUse, string $usage): void
    {
        if (!$inUse) {
            return;
        }
        if (empty($args['confirm_in_use'])) {
            if ($ctx->dryRun) {
                $ctx->addWarning($usage . ' The real call needs confirm_in_use=true.');

                return;
            }
            throw new ToolException($usage . ' Nothing was deleted.', 'Show this to the manager; repeat with confirm_in_use=true only after explicit approval.');
        }
        $ctx->addWarning($usage);
    }

    /**
     * ObjectModel::delete() in a single-shop context only drops that shop's
     * association; these records are removed from every shop.
     *
     * @throws ToolException
     */
    private function deleteEverywhere(\ObjectModel $obj, string $shopTable, string $col, ExecutionContext $ctx): void
    {
        $obj->id_shop_list = $this->recordShops($shopTable, $col, (int) $obj->id);
        \Shop::setContext(\Shop::CONTEXT_ALL);
        try {
            $ok = $obj->delete();
        } finally {
            \Shop::setContext(\Shop::CONTEXT_SHOP, (int) $ctx->idShop);
        }
        if (!$ok) {
            throw new ToolException('PrestaShop refused to delete the record.', 'Check the shop log (shop_log_list).');
        }
    }

    /**
     * @throws ToolException
     */
    private function featureRow(int $id, int $idLang, int $idShop): array
    {
        $r = $this->db()->getRow(
            'SELECT f.`id_feature`, fl.`name`' . ($idShop ? ', fs.`id_feature` AS in_shop' : '') . ',
                (SELECT COUNT(DISTINCT fp.`id_product`) FROM `' . _DB_PREFIX_ . 'feature_product` fp WHERE fp.`id_feature` = f.`id_feature`) AS products
            FROM `' . _DB_PREFIX_ . 'feature` f
            LEFT JOIN `' . _DB_PREFIX_ . 'feature_lang` fl ON fl.`id_feature` = f.`id_feature` AND fl.`id_lang` = ' . $idLang . '
            ' . ($idShop ? 'LEFT JOIN `' . _DB_PREFIX_ . 'feature_shop` fs ON fs.`id_feature` = f.`id_feature` AND fs.`id_shop` = ' . $idShop : '') . '
            WHERE f.`id_feature` = ' . $id
        );
        if (!$r) {
            throw new ToolException(sprintf('Feature #%d does not exist.', $id), 'Call catalog_feature_list.');
        }
        if ($idShop && !$r['in_shop']) {
            throw new ToolException(sprintf('Feature #%d is not available in shop #%d.', $id, $idShop));
        }

        return ['id_feature' => (int) $r['id_feature'], 'name' => (string) $r['name'], 'products' => (int) $r['products']];
    }

    /**
     * @throws ToolException
     */
    private function groupRow(int $id, int $idLang, int $idShop): array
    {
        $r = $this->db()->getRow(
            'SELECT ag.`id_attribute_group`, ag.`group_type`, agl.`name`, agl.`public_name`' . ($idShop ? ', ags.`id_attribute_group` AS in_shop' : '') . ',
                (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'attribute` a WHERE a.`id_attribute_group` = ag.`id_attribute_group`) AS `values`,
                (SELECT COUNT(DISTINCT pac.`id_product_attribute`) FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
                    INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.`id_attribute` = pac.`id_attribute`
                    WHERE a.`id_attribute_group` = ag.`id_attribute_group`) AS combinations
            FROM `' . _DB_PREFIX_ . 'attribute_group` ag
            LEFT JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl ON agl.`id_attribute_group` = ag.`id_attribute_group` AND agl.`id_lang` = ' . $idLang . '
            ' . ($idShop ? 'LEFT JOIN `' . _DB_PREFIX_ . 'attribute_group_shop` ags ON ags.`id_attribute_group` = ag.`id_attribute_group` AND ags.`id_shop` = ' . $idShop : '') . '
            WHERE ag.`id_attribute_group` = ' . $id
        );
        if (!$r) {
            throw new ToolException(sprintf('Attribute #%d does not exist.', $id), 'Call catalog_attribute_list.');
        }
        if ($idShop && !$r['in_shop']) {
            throw new ToolException(sprintf('Attribute #%d is not available in shop #%d.', $id, $idShop));
        }

        return [
            'id_attribute_group' => (int) $r['id_attribute_group'],
            'name' => (string) $r['name'],
            'public_name' => (string) $r['public_name'],
            'group_type' => (string) $r['group_type'],
            'values' => (int) $r['values'],
            'combinations' => (int) $r['combinations'],
        ];
    }

    /**
     * @return \FeatureValue
     *
     * @throws ToolException
     */
    private function loadPredefinedValue(int $id)
    {
        $fv = new \FeatureValue($id);
        if (!\Validate::isLoadedObject($fv)) {
            throw new ToolException(sprintf('Feature value #%d does not exist.', $id), 'Call catalog_feature_list with id_feature.');
        }
        if ((int) $fv->custom === 1) {
            throw new ToolException(sprintf('Feature value #%d is a custom (per-product) value; only predefined values are managed here.', $id));
        }

        return $fv;
    }

    /**
     * @return \ObjectModel ProductAttribute (PS 8+) or Attribute (PS 1.7)
     *
     * @throws ToolException
     */
    private function loadAttributeValue(int $id)
    {
        $class = self::attributeClass();
        $a = new $class($id);
        if (!\Validate::isLoadedObject($a)) {
            throw new ToolException(sprintf('Attribute value #%d does not exist.', $id), 'Call catalog_attribute_list with id_attribute_group.');
        }

        return $a;
    }

    /**
     * PS 8 renamed Attribute to ProductAttribute; on PHP 8 "Attribute" is a
     * native class, so ProductAttribute must be checked first.
     */
    private static function attributeClass(): string
    {
        return class_exists('ProductAttribute') ? 'ProductAttribute' : 'Attribute';
    }

    private function featureValueProducts(int $id): int
    {
        return (int) $this->db()->getValue('SELECT COUNT(DISTINCT `id_product`) FROM `' . _DB_PREFIX_ . 'feature_product` WHERE `id_feature_value` = ' . $id);
    }

    private function attributeCombinations(int $id): int
    {
        return (int) $this->db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_attribute_combination` WHERE `id_attribute` = ' . $id);
    }

    private function labelOf(array $vals, ExecutionContext $ctx): string
    {
        return isset($vals[(int) $ctx->idLang]) ? $vals[(int) $ctx->idLang] : (string) reset($vals);
    }

    private function pick($value, int $idLang): string
    {
        if (is_array($value)) {
            return isset($value[$idLang]) ? (string) $value[$idLang] : (string) reset($value);
        }

        return (string) $value;
    }

    private function conf(string $key, int $idShop)
    {
        $shop = new \Shop($idShop);

        return \Configuration::get($key, null, (int) $shop->id_shop_group, $idShop);
    }

    private function afterDictionaryChange(): void
    {
        if (method_exists('Product', 'resetStaticCache')) {
            \Product::resetStaticCache();
        }
        \Cache::clean('*');
        $this->invalidateFacetedCache();
    }

    /** Predefined (non-custom) feature values; custom is NULL on some old rows. */
    private static function predefinedSql(string $alias): string
    {
        return 'IFNULL(' . $alias . '.`custom`, 0) = 0';
    }
}
