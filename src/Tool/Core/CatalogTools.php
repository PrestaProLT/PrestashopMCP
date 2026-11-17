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
 * Catalog: products, combinations, stock, categories, specific prices,
 * price explanation, catalog quality scan and brands.
 *
 * Everything is read and written for $ctx->idShop only. Product objects are
 * always loaded with $full = false: $full = true replaces ->price with the
 * computed final price, and saving such an object would corrupt the price.
 */
class CatalogTools extends AbstractToolGroup
{
    /** Multilang product fields catalog_product_update accepts. */
    const PRODUCT_LANG_FIELDS = ['name', 'description_short', 'description', 'meta_title', 'meta_description', 'link_rewrite'];

    /** Scalar product fields catalog_product_update accepts => value type. */
    const PRODUCT_FIELDS = [
        'price' => 'float',
        'wholesale_price' => 'float',
        'reference' => 'string',
        'ean13' => 'string',
        'upc' => 'string',
        'isbn' => 'string',
        'mpn' => 'string',
        'weight' => 'float',
        'active' => 'bool',
        'visibility' => 'string',
        'available_for_order' => 'bool',
        'show_price' => 'bool',
        'online_only' => 'bool',
        'on_sale' => 'bool',
        'condition' => 'string',
        'minimal_quantity' => 'int',
        'id_category_default' => 'int',
        'id_manufacturer' => 'int',
    ];

    const CATEGORY_LANG_FIELDS = ['name', 'description', 'meta_title', 'meta_description', 'link_rewrite'];

    const ISSUES = [
        'no_cover' => 'No cover image in this shop',
        'empty_short_description' => 'Short description empty in at least one active language',
        'empty_description' => 'Description empty in at least one active language',
        'missing_translation' => 'Name empty or missing in at least one active language',
        'zero_price' => 'Base price is 0 and no combination adds a price',
        'unsellable_out_of_stock' => 'Active and orderable, quantity <= 0 and out-of-stock orders denied',
        'only_home_category' => 'Not in any category except Home/Root',
        'empty_link_rewrite' => 'Friendly URL (link_rewrite) empty in at least one active language',
        'duplicate_reference' => 'Reference shared by several products',
        'duplicate_ean13' => 'EAN13 shared by several products',
        'inactive_with_stock' => 'Inactive but has stock',
    ];

    /** @var array id_shop => bool */
    private $shareStock = [];

    public function owner(): string
    {
        return 'catalog';
    }

    public function tools(): array
    {
        $ml = function ($what) {
            return ['type' => ['string', 'object'], 'description' => $what . ' A string sets the current language; {"en": "...", "lt": "..."} sets only the listed languages.'];
        };
        $productLang = [];
        foreach (self::PRODUCT_LANG_FIELDS as $f) {
            $productLang[$f] = $ml(ucfirst(str_replace('_', ' ', $f)) . ($f === 'link_rewrite' ? ' (friendly URL slug, a-z0-9-).' : ($f === 'name' ? '.' : ' (HTML allowed for descriptions).')));
        }

        return [
            [
                'name' => 'catalog_product_search',
                'title' => 'Search products',
                'description' => 'Find products in the shop by free text (name words, reference, EAN13/UPC/ISBN/MPN, combination reference, or a product id) and filters: category, brand, active, stock state, base price range. Returns compact paged rows: id, name, reference, base price tax excl, final price tax incl (visitor, default currency), quantity, active, visibility, default category. Use catalog_product_get for full details of one product.',
                'params' => array_merge([
                    'query' => ['type' => 'string', 'description' => 'Words in the name, a reference, a barcode, or a product id.'],
                    'id_category' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Only products associated with this category (not its subcategories).'],
                    'id_manufacturer' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Only this brand (catalog_manufacturer_list).'],
                    'active' => self::boolParam('true = only enabled, false = only disabled. Omit for both.'),
                    'stock' => ['type' => 'string', 'enum' => ['any', 'in_stock', 'out_of_stock'], 'default' => 'any', 'description' => 'Stock filter on the product total quantity in this shop.'],
                    'price_min' => ['type' => 'number', 'minimum' => 0, 'description' => 'Minimum base price, tax excluded.'],
                    'price_max' => ['type' => 'number', 'minimum' => 0, 'description' => 'Maximum base price, tax excluded.'],
                    'sort' => ['type' => 'string', 'enum' => ['id_desc', 'id_asc', 'name_asc', 'price_asc', 'price_desc', 'quantity_asc', 'quantity_desc', 'date_upd_desc'], 'default' => 'id_desc'],
                ], self::paginationParams(20, 100)),
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminProducts', 'view'],
                'keywords' => ['find', 'item', 'article', 'sku', 'ean', 'barcode', 'goods'],
                'handler' => 'productSearch',
            ],
            [
                'name' => 'catalog_product_get',
                'title' => 'Get product details',
                'description' => 'Everything about one product in the shop: identity (reference, barcodes, brand, type), texts (current language, or all languages; long descriptions are shortened plain text unless full_text=true), prices (base, wholesale, tax rule, final tax excl/incl), stock (quantity, out-of-stock behaviour resolved against the shop setting, minimal quantity), visibility flags, categories, images with URLs, combination count, features, SEO (link_rewrite, meta, URL) and the shops it belongs to. Next: catalog_product_update, catalog_stock_set, catalog_combination_list, catalog_price_explain.',
                'params' => [
                    'id_product' => self::idParam('Product id (catalog_product_search finds it).'),
                    'all_languages' => self::boolParam('Return texts in every language, keyed by ISO code.', false),
                    'full_text' => self::boolParam('Return descriptions as full HTML instead of a 300-char plain-text excerpt.', false),
                ],
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminProducts', 'view'],
                'keywords' => ['item', 'article', 'details', 'description', 'images', 'seo'],
                'handler' => 'productGet',
            ],
            [
                'name' => 'catalog_product_update',
                'title' => 'Update product fields',
                'description' => 'Changes product fields in the shop: texts per language (name, short/long description, meta title/description, link_rewrite; only the languages you pass change), price and wholesale price (tax excl), reference/EAN13/UPC/ISBN/MPN, weight, active, visibility, available_for_order, show_price, online_only, on_sale, condition, minimal_quantity, default category (also added to the product categories), brand. Only the arguments you pass are changed. Every value is validated with PrestaShop rules first; use dry_run=true to preview. Does not change stock (use catalog_stock_set) or specific prices (catalog_specific_price_create).',
                'params' => array_merge(
                    ['id_product' => self::idParam('Product id.')],
                    $productLang,
                    [
                        'price' => ['type' => 'number', 'minimum' => 0, 'description' => 'Base price, tax excluded, shop default currency.'],
                        'wholesale_price' => ['type' => 'number', 'minimum' => 0, 'description' => 'Cost price, tax excluded.'],
                        'reference' => ['type' => 'string', 'maxLength' => 64],
                        'ean13' => ['type' => 'string', 'maxLength' => 13],
                        'upc' => ['type' => 'string', 'maxLength' => 12],
                        'isbn' => ['type' => 'string', 'maxLength' => 32],
                        'mpn' => ['type' => 'string', 'maxLength' => 40, 'description' => 'Manufacturer part number (PrestaShop 1.7.7+).'],
                        'weight' => ['type' => 'number', 'minimum' => 0],
                        'active' => self::boolParam('Enabled on the storefront.'),
                        'visibility' => ['type' => 'string', 'enum' => ['both', 'catalog', 'search', 'none'], 'description' => 'Where the product is listed.'],
                        'available_for_order' => self::boolParam('Can be added to cart.'),
                        'show_price' => self::boolParam('Show the price (ignored by PrestaShop when available_for_order is true).'),
                        'online_only' => self::boolParam('"Online only" label.'),
                        'on_sale' => self::boolParam('"On sale" flag (display only, not a discount).'),
                        'condition' => ['type' => 'string', 'enum' => ['new', 'used', 'refurbished']],
                        'minimal_quantity' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Minimum quantity per order line (product without combinations).'],
                        'id_category_default' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Main category (catalog_category_tree).'],
                        'id_manufacturer' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Brand id, 0 = none.'],
                    ]
                ),
                'write' => true,
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminProducts', 'edit'],
                'keywords' => ['edit', 'change', 'rename', 'price', 'enable', 'disable', 'seo', 'description'],
                'handler' => 'productUpdate',
            ],
            [
                'name' => 'catalog_stock_set',
                'title' => 'Set stock quantity',
                'description' => 'Sets the available quantity of a product or of one combination in the shop (shared stock groups are handled by PrestaShop), either as an absolute quantity or as a delta (+5 / -2), and/or its out-of-stock behaviour (0 deny orders, 1 allow orders, 2 use the shop default; applies to the whole product). Products with combinations keep stock per combination: pass id_product_attribute (catalog_combination_list). Returns quantity before and after.',
                'params' => [
                    'id_product' => self::idParam('Product id.'),
                    'id_product_attribute' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Combination id; 0 for a product without combinations.'],
                    'quantity' => ['type' => 'integer', 'description' => 'New absolute quantity.'],
                    'delta' => ['type' => 'integer', 'description' => 'Quantity to add (negative to remove). Use instead of quantity.'],
                    'out_of_stock' => ['type' => 'integer', 'enum' => [0, 1, 2], 'description' => '0 = deny orders when out of stock, 1 = allow, 2 = shop default (PS_ORDER_OUT_OF_STOCK).'],
                ],
                'write' => true,
                'shop' => true,
                'permission' => ['AdminProducts', 'edit'],
                'keywords' => ['inventory', 'quantity', 'qty', 'backorder', 'out of stock'],
                'handler' => 'stockSet',
            ],
            [
                'name' => 'catalog_combination_list',
                'title' => 'List product combinations',
                'description' => 'Lists the combinations (variants such as size/color) of a product in the shop: attribute names in the current language, reference, EAN13, price impact (tax excl), final price tax incl, quantity, minimal quantity, default flag. Use the id_product_attribute with catalog_stock_set or catalog_price_explain.',
                'params' => array_merge([
                    'id_product' => self::idParam('Product id.'),
                ], self::paginationParams(50, 200)),
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminProducts', 'view'],
                'keywords' => ['variant', 'attribute', 'size', 'color', 'option'],
                'handler' => 'combinationList',
            ],
            [
                'name' => 'catalog_category_tree',
                'title' => 'Category tree',
                'description' => 'Category tree of the shop from a root (default: the shop Home category), down to max_depth levels: id, name, active, optional direct product counts; nodes deeper than max_depth show only children_count. Use catalog_category_get for one category.',
                'params' => [
                    'id_root' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Start category; default the shop Home category.'],
                    'max_depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 6, 'default' => 2],
                    'with_product_counts' => self::boolParam('Add the number of products directly in each category (all / active).', false),
                    'include_inactive' => self::boolParam('Include disabled categories.', true),
                ],
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminCategories', 'view'],
                'keywords' => ['categories', 'menu', 'navigation', 'hierarchy'],
                'handler' => 'categoryTree',
            ],
            [
                'name' => 'catalog_category_get',
                'title' => 'Get category details',
                'description' => 'One category in the shop: name, description (plain-text excerpt unless full_text=true), SEO fields, link_rewrite and URL, active, parent, path, children and product counts, customer groups that can see it. all_languages=true returns texts in every language. Next: catalog_category_update.',
                'params' => [
                    'id_category' => self::idParam('Category id (catalog_category_tree).'),
                    'all_languages' => self::boolParam('Return texts in every language, keyed by ISO code.', false),
                    'full_text' => self::boolParam('Return the description as full HTML.', false),
                ],
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminCategories', 'view'],
                'keywords' => ['category', 'seo', 'description'],
                'handler' => 'categoryGet',
            ],
            [
                'name' => 'catalog_category_update',
                'title' => 'Update category',
                'description' => 'Changes a category in the shop: name, description, meta title/description, link_rewrite (per language: only the languages you pass change) and active. Validated with PrestaShop rules; dry_run=true previews. Does not move the category.',
                'params' => array_merge(
                    ['id_category' => self::idParam('Category id.')],
                    [
                        'name' => $ml('Name.'),
                        'description' => $ml('Description (HTML allowed).'),
                        'meta_title' => $ml('Meta title.'),
                        'meta_description' => $ml('Meta description.'),
                        'link_rewrite' => $ml('Friendly URL slug (a-z0-9-).'),
                        'active' => self::boolParam('Enabled on the storefront.'),
                    ]
                ),
                'write' => true,
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminCategories', 'edit'],
                'keywords' => ['category', 'seo', 'rename', 'enable', 'disable'],
                'handler' => 'categoryUpdate',
            ],
            [
                'name' => 'catalog_specific_price_list',
                'title' => 'List specific prices',
                'description' => 'Specific prices (discounts / fixed prices) of a product that apply in this shop: combination, currency, country, customer group, customer, from quantity, date range, fixed price or reduction (amount or percent, tax incl/excl), status (active/expired/upcoming) and whether it comes from a catalog price rule. Use catalog_price_explain to see which one wins for a given customer.',
                'params' => array_merge([
                    'id_product' => self::idParam('Product id.'),
                    'id_product_attribute' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Only rules for this combination (and rules for all combinations).'],
                    'active_only' => self::boolParam('Hide expired rules.', false),
                ], self::paginationParams(50, 200)),
                'shop' => true,
                'permission' => ['AdminProducts', 'view'],
                'keywords' => ['discount', 'promotion', 'sale', 'reduction', 'special price'],
                'handler' => 'specificPriceList',
            ],
            [
                'name' => 'catalog_specific_price_create',
                'title' => 'Create a specific price',
                'description' => 'Adds a specific price to a product: a reduction (percentage 0-100 or amount) and/or a fixed price, optionally limited to a combination, currency, country, customer group, customer, from quantity and date range. By default it applies to this shop only. Refuses exact duplicates. dry_run=true validates without saving. Check the result with catalog_price_explain.',
                'params' => [
                    'id_product' => self::idParam('Product id.'),
                    'id_product_attribute' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => '0 = all combinations.'],
                    'reduction_type' => ['type' => 'string', 'enum' => ['percentage', 'amount'], 'default' => 'percentage'],
                    'reduction' => ['type' => 'number', 'minimum' => 0, 'default' => 0, 'description' => 'Percent (e.g. 15 for -15%) or amount in the shop default currency (or id_currency).'],
                    'reduction_tax' => self::boolParam('For amount reductions: true = amount includes tax.', true),
                    'price' => ['type' => 'number', 'minimum' => 0, 'description' => 'Fixed price tax excl that replaces the base price. Omit to keep the base price.'],
                    'from_quantity' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                    'id_currency' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => '0 = all currencies.'],
                    'id_country' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => '0 = all countries.'],
                    'id_group' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => '0 = all customer groups.'],
                    'id_customer' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => '0 = all customers.'],
                    'from' => ['type' => 'string', 'description' => 'Start, "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS". Omit = immediately.'],
                    'to' => ['type' => 'string', 'description' => 'End, same format. A date without time ends at 23:59:59. Omit = unlimited.'],
                    'all_shops' => self::boolParam('Apply in every shop instead of this shop only.', false),
                ],
                'write' => true,
                'shop' => true,
                'permission' => ['AdminProducts', 'edit'],
                'keywords' => ['discount', 'promotion', 'sale', 'reduction', 'special price'],
                'handler' => 'specificPriceCreate',
            ],
            [
                'name' => 'catalog_specific_price_delete',
                'title' => 'Delete a specific price',
                'description' => 'Deletes one specific price by id (catalog_specific_price_list). Cannot be undone: the deleted rule is returned in changes so it can be recreated. Rules generated by a catalog price rule cannot be deleted here (the rule would recreate them).',
                'params' => [
                    'id_specific_price' => self::idParam('Specific price id.'),
                ],
                'write' => true,
                'destructive' => true,
                'shop' => true,
                'permission' => ['AdminProducts', 'edit'],
                'keywords' => ['discount', 'remove', 'promotion'],
                'handler' => 'specificPriceDelete',
            ],
            [
                'name' => 'catalog_price_explain',
                'title' => 'Explain a product price',
                'description' => 'Explains how the final price of a product (or combination) is computed for an optional customer, group, country, currency and quantity: base price, combination impact, the specific price that won and why (every candidate rule is checked), group/category reduction, tax rule and rate, ecotax, final price tax excl/incl and which one the group displays. Use it for "why does customer X see price Y". Read-only; the shop context is not changed.',
                'params' => [
                    'id_product' => self::idParam('Product id.'),
                    'id_product_attribute' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Combination id; omit for the default combination.'],
                    'id_customer' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Customer whose group, address country and own specific prices apply.'],
                    'id_group' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Customer group (overrides the customer default group). Default: visitor group.'],
                    'id_country' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Country for taxes and country rules. Default: customer first address, else shop default country.'],
                    'id_currency' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Default: shop default currency.'],
                    'quantity' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                ],
                'shop' => true,
                'permission' => ['AdminProducts', 'view'],
                'keywords' => ['why', 'price', 'discount', 'tax', 'vat', 'specific price', 'group'],
                'handler' => 'priceExplain',
            ],
            [
                'name' => 'catalog_product_issue_scan',
                'title' => 'Scan catalog for problems',
                'description' => 'Finds catalog quality problems in the shop. issue="all" returns the count per issue type plus a few sample products each; a single issue type returns paged products (or paged duplicate groups). Issue types: ' . implode('; ', array_map(function ($k, $v) {
                    return $k . ' (' . $v . ')';
                }, array_keys(self::ISSUES), self::ISSUES)) . '. Fix with catalog_product_update or catalog_stock_set.',
                'params' => array_merge([
                    'issue' => ['type' => 'string', 'enum' => array_merge(['all'], array_keys(self::ISSUES)), 'default' => 'all'],
                    'only_active' => self::boolParam('Only check enabled products (ignored by inactive_with_stock).', true),
                    'sample_size' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10, 'default' => 3, 'description' => 'Sample rows per issue when issue="all".'],
                ], self::paginationParams(20, 100)),
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminProducts', 'view'],
                'keywords' => ['audit', 'quality', 'missing', 'problems', 'seo', 'duplicates', 'check'],
                'handler' => 'productIssueScan',
            ],
            [
                'name' => 'catalog_manufacturer_list',
                'title' => 'List brands',
                'description' => 'Brands (manufacturers) of the shop with active flag and product counts (all / active products in this shop), filterable by name. Use id_manufacturer with catalog_product_search.',
                'params' => array_merge([
                    'query' => ['type' => 'string', 'description' => 'Part of the brand name.'],
                    'active' => self::boolParam('true = only enabled brands, false = only disabled.'),
                ], self::paginationParams(50, 200)),
                'shop' => true,
                'permission' => ['AdminManufacturers', 'view'],
                'keywords' => ['brand', 'manufacturer', 'maker'],
                'handler' => 'manufacturerList',
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Products
    // ------------------------------------------------------------------

    public function productSearch(array $args, ExecutionContext $ctx): array
    {
        $s = (int) $ctx->idShop;
        $l = (int) $ctx->idLang;
        $where = ['1'];
        if (isset($args['query']) && trim($args['query']) !== '') {
            $q = trim($args['query']);
            $or = [];
            $words = preg_split('/\s+/u', $q);
            $nameAnd = [];
            foreach ($words as $w) {
                $nameAnd[] = 'pl.`name` LIKE \'%' . pSQL($this->likeEscape($w)) . '%\'';
            }
            $or[] = '(' . implode(' AND ', $nameAnd) . ')';
            $like = '\'%' . pSQL($this->likeEscape($q)) . '%\'';
            $eq = '\'' . pSQL($q) . '\'';
            $or[] = 'p.`reference` LIKE ' . $like;
            $or[] = 'p.`ean13` = ' . $eq;
            $or[] = 'p.`upc` = ' . $eq;
            $or[] = 'p.`isbn` = ' . $eq;
            if (property_exists('Product', 'mpn')) {
                $or[] = 'p.`mpn` = ' . $eq;
            }
            $or[] = 'EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute` pa WHERE pa.`id_product` = p.`id_product` AND (pa.`reference` LIKE ' . $like . ' OR pa.`ean13` = ' . $eq . '))';
            if (ctype_digit($q)) {
                $or[] = 'p.`id_product` = ' . (int) $q;
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        if (!empty($args['id_category'])) {
            $where[] = 'EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'category_product` cp WHERE cp.`id_product` = p.`id_product` AND cp.`id_category` = ' . (int) $args['id_category'] . ')';
        }
        if (!empty($args['id_manufacturer'])) {
            $where[] = 'p.`id_manufacturer` = ' . (int) $args['id_manufacturer'];
        }
        if (isset($args['active'])) {
            $where[] = 'ps.`active` = ' . ($args['active'] ? 1 : 0);
        }
        if ($args['stock'] === 'in_stock') {
            $where[] = 'IFNULL(sa.`quantity`, 0) > 0';
        } elseif ($args['stock'] === 'out_of_stock') {
            $where[] = 'IFNULL(sa.`quantity`, 0) <= 0';
        }
        if (isset($args['price_min'])) {
            $where[] = 'ps.`price` >= ' . (float) $args['price_min'];
        }
        if (isset($args['price_max'])) {
            $where[] = 'ps.`price` <= ' . (float) $args['price_max'];
        }
        $sorts = [
            'id_desc' => 'p.`id_product` DESC',
            'id_asc' => 'p.`id_product` ASC',
            'name_asc' => 'pl.`name` ASC',
            'price_asc' => 'ps.`price` ASC',
            'price_desc' => 'ps.`price` DESC',
            'quantity_asc' => 'quantity ASC',
            'quantity_desc' => 'quantity DESC',
            'date_upd_desc' => 'ps.`date_upd` DESC',
        ];
        $order = isset($sorts[$args['sort']]) ? $sorts[$args['sort']] : $sorts['id_desc'];

        $from = ' FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $s . '
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.`id_product` = p.`id_product` AND pl.`id_lang` = ' . $l . ' AND pl.`id_shop` = ' . $s . '
            LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` sa ON sa.`id_product` = p.`id_product` AND sa.`id_product_attribute` = 0 AND ' . $this->stockShopSql('sa', $s) . '
            WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db()->getValue('SELECT COUNT(*)' . $from);
        $rows = $this->db()->executeS(
            'SELECT p.`id_product`, pl.`name`, p.`reference`, ps.`price`, IFNULL(sa.`quantity`, 0) AS quantity, ps.`active`, ps.`visibility`,
                ps.`id_category_default`, (SELECT cl.`name` FROM `' . _DB_PREFIX_ . 'category_lang` cl WHERE cl.`id_category` = ps.`id_category_default` AND cl.`id_lang` = ' . $l . ' AND cl.`id_shop` = ' . $s . ' LIMIT 1) AS category'
            . $from . ' ORDER BY ' . $order . ' LIMIT ' . (int) $args['offset'] . ', ' . (int) $args['limit']
        ) ?: [];
        $items = [];
        foreach ($rows as $r) {
            $id = (int) $r['id_product'];
            $items[] = [
                'id_product' => $id,
                'name' => (string) $r['name'],
                'reference' => (string) $r['reference'],
                'price_tax_excl' => round((float) $r['price'], 6),
                'final_price_tax_incl' => $this->finalPrice($id, null, true),
                'quantity' => (int) $r['quantity'],
                'active' => (bool) $r['active'],
                'visibility' => (string) $r['visibility'],
                'default_category' => $r['category'] !== null ? (string) $r['category'] : null,
            ];
        }
        $out = $this->paged($items, $total, $args, 'products');
        $out['currency'] = $this->context()->currency ? $this->context()->currency->iso_code : null;

        return $out;
    }

    public function productGet(array $args, ExecutionContext $ctx): array
    {
        $s = (int) $ctx->idShop;
        $l = (int) $ctx->idLang;
        $id = (int) $args['id_product'];
        $p = $this->loadProduct($id, $s);
        $all = !empty($args['all_languages']);
        $full = !empty($args['full_text']);

        $texts = [];
        foreach (['name', 'description_short', 'description', 'link_rewrite', 'meta_title', 'meta_description', 'available_now', 'available_later'] as $f) {
            if (!property_exists($p, $f)) {
                continue;
            }
            $long = in_array($f, ['description_short', 'description'], true);
            $texts[$f] = $this->mlOut((array) $p->$f, $all, $l, $long && !$full ? 300 : 0);
        }

        $oos = (int) \StockAvailable::outOfStock($id, $s);
        $stockMgmt = (bool) $this->conf('PS_STOCK_MANAGEMENT', $s);
        $shopOos = (int) $this->conf('PS_ORDER_OUT_OF_STOCK', $s);
        $idTrg = (int) $p->id_tax_rules_group;
        $taxRate = null;
        try {
            $taxRate = (float) $p->getTaxesRate();
        } catch (\Throwable $e) {
            $taxRate = null;
        }

        $cats = [];
        $catIds = array_map('intval', \Product::getProductCategories($id));
        if ($catIds) {
            foreach ($this->db()->executeS(
                'SELECT c.`id_category`, cl.`name`, c.`active` FROM `' . _DB_PREFIX_ . 'category` c
                 LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.`id_category` = c.`id_category` AND cl.`id_lang` = ' . $l . ' AND cl.`id_shop` = ' . $s . '
                 WHERE c.`id_category` IN (' . implode(',', $catIds) . ') ORDER BY c.`level_depth`, c.`id_category`'
            ) ?: [] as $c) {
                $cats[] = ['id_category' => (int) $c['id_category'], 'name' => (string) $c['name'], 'active' => (bool) $c['active'], 'is_default' => (int) $c['id_category'] === (int) $p->id_category_default];
            }
        }

        $images = [];
        $imgRows = $this->db()->executeS(
            'SELECT i.`id_image`, ims.`cover`, il.`legend` FROM `' . _DB_PREFIX_ . 'image` i
             INNER JOIN `' . _DB_PREFIX_ . 'image_shop` ims ON ims.`id_image` = i.`id_image` AND ims.`id_shop` = ' . $s . '
             LEFT JOIN `' . _DB_PREFIX_ . 'image_lang` il ON il.`id_image` = i.`id_image` AND il.`id_lang` = ' . $l . '
             WHERE i.`id_product` = ' . $id . ' ORDER BY i.`position` LIMIT 30'
        ) ?: [];
        $rewrite = is_array($p->link_rewrite) ? (isset($p->link_rewrite[$l]) ? $p->link_rewrite[$l] : reset($p->link_rewrite)) : (string) $p->link_rewrite;
        $link = $this->context()->link;
        foreach ($imgRows as $im) {
            $url = null;
            try {
                if ($link) {
                    $url = $link->getImageLink($rewrite ?: 'product', (string) (int) $im['id_image'], $this->imageType('large'));
                }
            } catch (\Throwable $e) {
                $url = null;
            }
            $images[] = ['id_image' => (int) $im['id_image'], 'cover' => (bool) $im['cover'], 'legend' => (string) $im['legend'], 'url' => $url];
        }
        $imageTotal = (int) $this->db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'image_shop` WHERE `id_product` = ' . $id . ' AND `id_shop` = ' . $s);

        $features = [];
        foreach (array_slice(\Product::getFrontFeaturesStatic($l, $id) ?: [], 0, 50) as $f) {
            $features[] = ['name' => (string) $f['name'], 'value' => (string) $f['value']];
        }

        $shops = [];
        $shopNames = [];
        foreach (\Shop::getShops(false) as $sh) {
            $shopNames[(int) $sh['id_shop']] = $sh['name'];
        }
        foreach ($p->getAssociatedShops() as $idS) {
            $shops[] = ['id_shop' => (int) $idS, 'name' => isset($shopNames[(int) $idS]) ? $shopNames[(int) $idS] : null];
        }

        $url = null;
        try {
            if ($link) {
                $url = $link->getProductLink($id, null, null, null, $l, $s);
            }
        } catch (\Throwable $e) {
            $url = null;
        }

        $combinations = (int) $this->db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_attribute_shop` WHERE `id_product` = ' . $id . ' AND `id_shop` = ' . $s);
        $spCount = (int) $this->db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'specific_price` WHERE `id_product` = ' . $id . ' AND `id_shop` IN (0, ' . $s . ') AND `id_cart` = 0');

        $flags = [
            'active' => (bool) $p->active,
            'visibility' => (string) $p->visibility,
            'available_for_order' => (bool) $p->available_for_order,
            'show_price' => (bool) $p->show_price,
            'online_only' => (bool) $p->online_only,
            'on_sale' => (bool) $p->on_sale,
            'condition' => (string) $p->condition,
            'show_condition' => (bool) $p->show_condition,
            'is_virtual' => (bool) $p->is_virtual,
            'is_pack' => (bool) $p->cache_is_pack,
            'customizable' => (int) $p->customizable,
            'redirect_type' => (string) $p->redirect_type,
            'id_type_redirected' => (int) $p->id_type_redirected,
        ];
        if (property_exists($p, 'product_type')) {
            $flags['product_type'] = (string) $p->product_type;
        }
        if (property_exists($p, 'state')) {
            $flags['state'] = (int) $p->state;
            $flags['state_legend'] = '1 = saved, 0 = temporary draft';
        }

        $mname = $p->id_manufacturer ? \Manufacturer::getNameById((int) $p->id_manufacturer) : null;

        $identity = [
            'id_product' => $id,
            'name' => $this->pickLang($p->name, $l),
            'reference' => (string) $p->reference,
            'supplier_reference' => (string) $p->supplier_reference,
            'ean13' => (string) $p->ean13,
            'upc' => (string) $p->upc,
            'isbn' => (string) $p->isbn,
            'id_manufacturer' => (int) $p->id_manufacturer,
            'manufacturer' => $mname ?: null,
            'id_supplier' => (int) $p->id_supplier,
            'weight' => (float) $p->weight,
            'date_add' => $p->date_add,
            'date_upd' => $p->date_upd,
        ];
        if (property_exists($p, 'mpn')) {
            $identity['mpn'] = (string) $p->mpn;
        }

        $quantity = (int) \StockAvailable::getQuantityAvailableByProduct($id, 0, $s);
        $warnings = [];
        if (!$p->active) {
            $warnings[] = 'Product is disabled in this shop.';
        }

        return [
            'identity' => $identity,
            'texts' => $texts,
            'prices' => [
                'currency' => $this->context()->currency ? $this->context()->currency->iso_code : null,
                'price_tax_excl' => round((float) $p->price, 6),
                'wholesale_price' => round((float) $p->wholesale_price, 6),
                'ecotax' => round((float) $p->ecotax, 6),
                'unit_price' => property_exists($p, 'unit_price') ? round((float) $p->unit_price, 6) : null,
                'unity' => (string) $p->unity,
                'id_tax_rules_group' => $idTrg,
                'tax_rules_group' => $idTrg ? $this->db()->getValue('SELECT `name` FROM `' . _DB_PREFIX_ . 'tax_rules_group` WHERE `id_tax_rules_group` = ' . $idTrg) : null,
                'tax_rate_default_country' => $taxRate,
                'final_price_tax_excl' => $this->finalPrice($id, null, false),
                'final_price_tax_incl' => $this->finalPrice($id, null, true),
                'final_price_tax_incl_without_reduction' => $this->finalPrice($id, null, true, false),
                'specific_prices_count' => $spCount,
                'note' => 'final prices: visitor group, default country and currency, quantity 1, default combination. catalog_price_explain gives the detail.',
            ],
            'stock' => [
                'quantity' => $quantity,
                'out_of_stock' => $oos,
                'out_of_stock_legend' => '0 deny orders, 1 allow orders, 2 shop default',
                'orders_when_out_of_stock' => \Product::isAvailableWhenOutOfStock($oos) ? 'allowed' : 'denied',
                'stock_management_enabled' => $stockMgmt,
                'shop_default_out_of_stock' => $shopOos,
                'minimal_quantity' => (int) $p->minimal_quantity,
                'low_stock_threshold' => $p->low_stock_threshold !== null ? (int) $p->low_stock_threshold : null,
                'location' => (string) \StockAvailable::getLocation($id, 0, $s),
                'available_date' => $p->available_date,
                'shared_stock' => $this->sharesStock($s),
            ],
            'flags' => $flags,
            'categories' => $cats,
            'id_category_default' => (int) $p->id_category_default,
            'images' => $images,
            'images_total' => $imageTotal,
            'combinations_count' => $combinations,
            'features' => $features,
            'url' => $url,
            'shops' => $shops,
            '_warnings' => $warnings,
        ];
    }

    public function productUpdate(array $args, ExecutionContext $ctx): array
    {
        $s = (int) $ctx->idShop;
        $id = (int) $args['id_product'];
        $p = $this->loadProduct($id, $s, null);
        $errors = [];
        $planned = [];

        foreach (self::PRODUCT_FIELDS as $f => $type) {
            if (!array_key_exists($f, $args)) {
                continue;
            }
            if (!property_exists($p, $f)) {
                $errors[] = sprintf('%s is not supported on PrestaShop %s.', $f, _PS_VERSION_);
                continue;
            }
            $after = $this->castValue($args[$f], $type);
            $before = $this->castValue($p->$f, $type);
            if ($f === 'id_category_default') {
                $cat = new \Category($after, null, $s);
                if (!\Validate::isLoadedObject($cat)) {
                    $errors[] = sprintf('Category #%d does not exist.', $after);
                    continue;
                }
                if (!$cat->isAssociatedToShop($s)) {
                    $errors[] = sprintf('Category #%d is not in shop #%d.', $after, $s);
                    continue;
                }
            }
            if ($f === 'id_manufacturer' && $after > 0 && !\Validate::isLoadedObject(new \Manufacturer($after))) {
                $errors[] = sprintf('Brand #%d does not exist (catalog_manufacturer_list).', $after);
                continue;
            }
            $check = $p->validateField($f, $after, null, [], true);
            if ($check !== true) {
                $errors[] = $f . ': ' . (is_string($check) ? $check : 'invalid value');
                continue;
            }
            if ($before !== $after) {
                $planned[] = [$f, null, $before, $after];
            }
        }

        foreach (self::PRODUCT_LANG_FIELDS as $f) {
            if (!array_key_exists($f, $args)) {
                continue;
            }
            foreach ($this->multilangInput($args[$f], $ctx, $f) as $idLang => $value) {
                $value = $f === 'link_rewrite' ? trim($value) : $value;
                if ($f === 'name' && trim($value) === '') {
                    $errors[] = sprintf('name (%s) cannot be empty.', $this->isoForLang($idLang));
                    continue;
                }
                $check = $p->validateField($f, $value, $idLang, [], true);
                if ($check !== true) {
                    $errors[] = $f . ' (' . $this->isoForLang($idLang) . '): ' . (is_string($check) ? $check : 'invalid value');
                    continue;
                }
                $current = is_array($p->$f) && isset($p->{$f}[$idLang]) ? (string) $p->{$f}[$idLang] : '';
                if ($current !== $value) {
                    $planned[] = [$f, $idLang, $current, $value];
                }
            }
        }
        if ($errors) {
            throw new ToolException('Validation failed: ' . implode(' | ', $errors), 'Fix the listed values; nothing was saved.');
        }

        $addCategory = false;
        foreach ($planned as $c) {
            list($f, $idLang, $before, $after) = $c;
            if ($idLang) {
                $arr = (array) $p->$f;
                $arr[$idLang] = $after;
                $p->$f = $arr;
            } else {
                $p->$f = $after;
            }
            if ($f === 'id_category_default' && !in_array($after, array_map('intval', \Product::getProductCategories($id)), true)) {
                $addCategory = $after;
            }
            $ctx->recordChange('product', $id, $f, $before, $after, $s, $idLang ?: null);
        }
        if ($addCategory) {
            $ctx->recordChange('product', $id, 'categories', null, 'add #' . $addCategory, $s);
        }
        $warnings = [];
        if (isset($args['show_price']) && !$args['show_price'] && $p->available_for_order) {
            $warnings[] = 'show_price=false has no effect while available_for_order is true.';
        }
        if (isset($args['name']) && !isset($args['link_rewrite'])) {
            $warnings[] = 'The name changed but link_rewrite (URL) did not; pass link_rewrite to change the URL too.';
        }

        if ($planned && !$ctx->dryRun) {
            if ($addCategory) {
                $p->addToCategories([$addCategory]);
            }
            if (!$p->update()) {
                throw new ToolException('PrestaShop refused to save the product.', 'Check the shop log (shop_log_list) for details.');
            }
            \Product::flushPriceCache();
        }

        return [
            'id_product' => $id,
            'id_shop' => $s,
            'fields_changed' => count($planned),
            '_warnings' => $warnings,
        ];
    }

    public function stockSet(array $args, ExecutionContext $ctx): array
    {
        $s = (int) $ctx->idShop;
        $id = (int) $args['id_product'];
        $ipa = (int) $args['id_product_attribute'];
        $p = $this->loadProduct($id, $s);
        $hasQty = isset($args['quantity']);
        $hasDelta = isset($args['delta']);
        $hasOos = isset($args['out_of_stock']);
        if ($hasQty && $hasDelta) {
            throw new ToolException('Pass either quantity or delta, not both.');
        }
        if (!$hasQty && !$hasDelta && !$hasOos) {
            throw new ToolException('Nothing to do.', 'Pass quantity, delta or out_of_stock.');
        }
        $combos = array_map('intval', array_column(\Product::getProductAttributesIds($id, true) ?: [], 'id_product_attribute'));
        if ($ipa && !in_array($ipa, $combos, true)) {
            throw new ToolException(sprintf('Combination #%d does not belong to product #%d in this shop.', $ipa, $id), 'Call catalog_combination_list.');
        }
        if (($hasQty || $hasDelta) && !$ipa && $combos) {
            throw new ToolException(
                sprintf('Product #%d has %d combinations: its quantity is the sum of the combinations.', $id, count($combos)),
                'Pass id_product_attribute (catalog_combination_list) to set the stock of one combination.'
            );
        }
        if (($hasQty || $hasDelta) && method_exists('StockAvailable', 'dependsOnStock') && \Configuration::get('PS_ADVANCED_STOCK_MANAGEMENT')
            && \StockAvailable::dependsOnStock($id, $s)) {
            throw new ToolException('This product uses advanced stock management (warehouses); its quantity is computed from warehouse stock.', 'Change the stock in Stock > Stock Management.');
        }
        if ($p->cache_is_pack) {
            $ctx->addWarning('This product is a pack; depending on the pack stock setting its availability may follow the pack items.');
        }

        $before = (int) \StockAvailable::getQuantityAvailableByProduct($id, $ipa, $s);
        $after = $before;
        if ($hasQty) {
            $after = (int) $args['quantity'];
        } elseif ($hasDelta) {
            $after = $before + (int) $args['delta'];
        }
        $oosBefore = (int) \StockAvailable::outOfStock($id, $s);
        $oosAfter = $hasOos ? (int) $args['out_of_stock'] : $oosBefore;
        if (!$this->conf('PS_STOCK_MANAGEMENT', $s)) {
            $ctx->addWarning('Stock management is disabled in this shop (PS_STOCK_MANAGEMENT=0): quantities are not checked on orders.');
        }

        $ctx->recordChange('stock_available', $ipa ? $id . ':' . $ipa : $id, 'quantity', $before, $after, $s);
        $ctx->recordChange('stock_available', $id, 'out_of_stock', $oosBefore, $oosAfter, $s);

        if (!$ctx->dryRun) {
            if ($after !== $before) {
                \StockAvailable::setQuantity($id, $ipa, $after, $s);
            }
            if ($oosAfter !== $oosBefore) {
                \StockAvailable::setProductOutOfStock($id, $oosAfter, $s);
            }
            if ($after !== $before) {
                $this->syncPhysicalQuantity($id, $s);
            }
            if (method_exists('StockAvailable', 'resetStaticCache')) {
                \StockAvailable::resetStaticCache();
            }
            \Cache::clean('StockAvailable::*');
            $after = (int) \StockAvailable::getQuantityAvailableByProduct($id, $ipa, $s);
        }

        return [
            'id_product' => $id,
            'id_product_attribute' => $ipa,
            'id_shop' => $s,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'product_total_quantity' => $ctx->dryRun ? null : (int) \StockAvailable::getQuantityAvailableByProduct($id, 0, $s),
            'out_of_stock' => $oosAfter,
            'orders_when_out_of_stock' => \Product::isAvailableWhenOutOfStock($oosAfter) ? 'allowed' : 'denied',
        ];
    }

    public function combinationList(array $args, ExecutionContext $ctx): array
    {
        $s = (int) $ctx->idShop;
        $l = (int) $ctx->idLang;
        $id = (int) $args['id_product'];
        $this->loadProduct($id, $s);
        $total = (int) $this->db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_attribute_shop` WHERE `id_product` = ' . $id . ' AND `id_shop` = ' . $s);
        $rows = $this->db()->executeS(
            'SELECT pa.`id_product_attribute`, pa.`reference`, pa.`ean13`, pas.`price`, pas.`weight`, pas.`minimal_quantity`, pas.`default_on`,
                IFNULL(sa.`quantity`, 0) AS quantity
             FROM `' . _DB_PREFIX_ . 'product_attribute` pa
             INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_shop` pas ON pas.`id_product_attribute` = pa.`id_product_attribute` AND pas.`id_shop` = ' . $s . '
             LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` sa ON sa.`id_product` = pa.`id_product` AND sa.`id_product_attribute` = pa.`id_product_attribute` AND ' . $this->stockShopSql('sa', $s) . '
             WHERE pa.`id_product` = ' . $id . '
             ORDER BY pa.`id_product_attribute` LIMIT ' . (int) $args['offset'] . ', ' . (int) $args['limit']
        ) ?: [];
        $names = [];
        if ($rows) {
            $ids = array_map(function ($r) {
                return (int) $r['id_product_attribute'];
            }, $rows);
            foreach ($this->db()->executeS(
                'SELECT pac.`id_product_attribute`, agl.`name` AS grp, al.`name` AS val
                 FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
                 INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.`id_attribute` = pac.`id_attribute`
                 LEFT JOIN `' . _DB_PREFIX_ . 'attribute_lang` al ON al.`id_attribute` = a.`id_attribute` AND al.`id_lang` = ' . $l . '
                 LEFT JOIN `' . _DB_PREFIX_ . 'attribute_group` ag ON ag.`id_attribute_group` = a.`id_attribute_group`
                 LEFT JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl ON agl.`id_attribute_group` = a.`id_attribute_group` AND agl.`id_lang` = ' . $l . '
                 WHERE pac.`id_product_attribute` IN (' . implode(',', $ids) . ')
                 ORDER BY ag.`position`, a.`position`'
            ) ?: [] as $n) {
                $names[(int) $n['id_product_attribute']][] = $n['grp'] . ': ' . $n['val'];
            }
        }
        $items = [];
        foreach ($rows as $r) {
            $ipa = (int) $r['id_product_attribute'];
            $items[] = [
                'id_product_attribute' => $ipa,
                'attributes' => isset($names[$ipa]) ? implode(', ', $names[$ipa]) : '',
                'reference' => (string) $r['reference'],
                'ean13' => (string) $r['ean13'],
                'price_impact_tax_excl' => round((float) $r['price'], 6),
                'final_price_tax_incl' => $this->finalPrice($id, $ipa, true),
                'quantity' => (int) $r['quantity'],
                'minimal_quantity' => (int) $r['minimal_quantity'],
                'weight_impact' => (float) $r['weight'],
                'default_on' => (bool) $r['default_on'],
            ];
        }

        return $this->paged($items, $total, $args, 'combinations');
    }

    // ------------------------------------------------------------------
    // Categories
    // ------------------------------------------------------------------

    public function categoryTree(array $args, ExecutionContext $ctx): array
    {
        $s = (int) $ctx->idShop;
        $l = (int) $ctx->idLang;
        $shop = new \Shop($s);
        $root = !empty($args['id_root']) ? (int) $args['id_root'] : (int) $shop->id_category;
        $maxDepth = (int) $args['max_depth'];
        $inactive = !empty($args['include_inactive']);
        $maxNodes = 300;

        $rootRow = $this->categoryRows([$root], $s, $l, true, 'c.`id_category`');
        if (!$rootRow) {
            throw new ToolException(sprintf('Category #%d does not exist in shop #%d.', $root, $s), 'Omit id_root to start at the shop Home category.');
        }
        $nodes = [];
        $nodes[$root] = $this->treeNode($rootRow[0]);
        $parents = [$root];
        $count = 1;
        $truncated = false;
        $lastLevel = [];
        for ($depth = 1; $depth <= $maxDepth && $parents; ++$depth) {
            $rows = $this->categoryRows($parents, $s, $l, $inactive, 'c.`id_parent`');
            $next = [];
            foreach ($rows as $r) {
                if ($count >= $maxNodes) {
                    $truncated = true;
                    break;
                }
                $cid = (int) $r['id_category'];
                $nodes[$cid] = $this->treeNode($r);
                $nodes[$cid]['_parent'] = (int) $r['id_parent'];
                $next[] = $cid;
                ++$count;
            }
            $parents = $next;
            $lastLevel = $next;
            if ($truncated) {
                break;
            }
        }
        // Leaves at the depth limit: tell how many children they hide.
        if ($lastLevel) {
            $cc = $this->db()->executeS(
                'SELECT c.`id_parent`, COUNT(*) n FROM `' . _DB_PREFIX_ . 'category` c
                 INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . $s . '
                 WHERE c.`id_parent` IN (' . implode(',', $lastLevel) . ')' . ($inactive ? '' : ' AND c.`active` = 1') . ' GROUP BY c.`id_parent`'
            ) ?: [];
            foreach ($cc as $r) {
                $nodes[(int) $r['id_parent']]['children_count'] = (int) $r['n'];
            }
        }
        if (!empty($args['with_product_counts'])) {
            $ids = array_keys($nodes);
            foreach ($this->db()->executeS(
                'SELECT cp.`id_category`, COUNT(*) n, SUM(ps.`active`) a FROM `' . _DB_PREFIX_ . 'category_product` cp
                 INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = cp.`id_product` AND ps.`id_shop` = ' . $s . '
                 WHERE cp.`id_category` IN (' . implode(',', $ids) . ') GROUP BY cp.`id_category`'
            ) ?: [] as $r) {
                $nodes[(int) $r['id_category']]['products'] = (int) $r['n'];
                $nodes[(int) $r['id_category']]['active_products'] = (int) $r['a'];
            }
            foreach ($nodes as $cid => $n) {
                if (!isset($n['products'])) {
                    $nodes[$cid]['products'] = 0;
                    $nodes[$cid]['active_products'] = 0;
                }
            }
        }
        // Assemble bottom-up (children were appended in parent order).
        foreach (array_reverse(array_keys($nodes)) as $cid) {
            if (!isset($nodes[$cid]['_parent'])) {
                continue;
            }
            $pid = $nodes[$cid]['_parent'];
            unset($nodes[$cid]['_parent']);
            if (isset($nodes[$pid])) {
                if (!isset($nodes[$pid]['children'])) {
                    $nodes[$pid]['children'] = [];
                }
                array_unshift($nodes[$pid]['children'], $nodes[$cid]);
            }
        }

        $out = [
            'id_shop' => $s,
            'id_root' => $root,
            'home_category' => (int) $shop->id_category,
            'max_depth' => $maxDepth,
            'node_count' => $count,
            'tree' => $nodes[$root],
        ];
        if ($truncated) {
            $out['truncated'] = sprintf('Stopped at %d categories; use a deeper id_root or a smaller max_depth.', $maxNodes);
        }

        return $out;
    }

    public function categoryGet(array $args, ExecutionContext $ctx): array
    {
        $s = (int) $ctx->idShop;
        $l = (int) $ctx->idLang;
        $c = $this->loadCategory((int) $args['id_category'], $s);
        $all = !empty($args['all_languages']);
        $full = !empty($args['full_text']);
        $id = (int) $c->id;
        $texts = [];
        foreach (self::CATEGORY_LANG_FIELDS as $f) {
            $texts[$f] = $this->mlOut((array) $c->$f, $all, $l, $f === 'description' && !$full ? 300 : 0);
        }
        if (property_exists($c, 'additional_description')) {
            $texts['additional_description'] = $this->mlOut((array) $c->additional_description, $all, $l, $full ? 0 : 300);
        }
        $path = [];
        foreach ($this->db()->executeS(
            'SELECT c.`id_category`, cl.`name` FROM `' . _DB_PREFIX_ . 'category` c
             LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.`id_category` = c.`id_category` AND cl.`id_lang` = ' . $l . ' AND cl.`id_shop` = ' . $s . '
             WHERE c.`nleft` <= ' . (int) $c->nleft . ' AND c.`nright` >= ' . (int) $c->nright . ' ORDER BY c.`level_depth`'
        ) ?: [] as $r) {
            $path[] = ['id_category' => (int) $r['id_category'], 'name' => (string) $r['name']];
        }
        $children = (int) $this->db()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'category` c INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . $s . ' WHERE c.`id_parent` = ' . $id
        );
        $products = $this->db()->getRow(
            'SELECT COUNT(*) n, IFNULL(SUM(ps.`active`), 0) a FROM `' . _DB_PREFIX_ . 'category_product` cp
             INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = cp.`id_product` AND ps.`id_shop` = ' . $s . '
             WHERE cp.`id_category` = ' . $id
        );
        $groups = [];
        foreach ($this->db()->executeS(
            'SELECT cg.`id_group`, gl.`name` FROM `' . _DB_PREFIX_ . 'category_group` cg
             LEFT JOIN `' . _DB_PREFIX_ . 'group_lang` gl ON gl.`id_group` = cg.`id_group` AND gl.`id_lang` = ' . $l . '
             WHERE cg.`id_category` = ' . $id . ' ORDER BY cg.`id_group`'
        ) ?: [] as $g) {
            $groups[] = ['id_group' => (int) $g['id_group'], 'name' => (string) $g['name']];
        }
        $url = null;
        try {
            if ($this->context()->link) {
                $url = $this->context()->link->getCategoryLink($id, null, $l, null, $s);
            }
        } catch (\Throwable $e) {
            $url = null;
        }

        return [
            'id_category' => $id,
            'texts' => $texts,
            'active' => (bool) $c->active,
            'id_parent' => (int) $c->id_parent,
            'level_depth' => (int) $c->level_depth,
            'is_root_category' => (bool) $c->is_root_category,
            'is_shop_home' => $id === (int) (new \Shop($s))->id_category,
            'position' => (int) $c->position,
            'path' => $path,
            'children_count' => $children,
            'products' => (int) $products['n'],
            'active_products' => (int) $products['a'],
            'groups' => $groups,
            'url' => $url,
            'date_upd' => $c->date_upd,
        ];
    }

    public function categoryUpdate(array $args, ExecutionContext $ctx): array
    {
        $s = (int) $ctx->idShop;
        $c = $this->loadCategory((int) $args['id_category'], $s, null);
        $id = (int) $c->id;
        $errors = [];
        $planned = [];
        foreach (self::CATEGORY_LANG_FIELDS as $f) {
            if (!array_key_exists($f, $args)) {
                continue;
            }
            foreach ($this->multilangInput($args[$f], $ctx, $f) as $idLang => $value) {
                $value = $f === 'link_rewrite' ? trim($value) : $value;
                if (in_array($f, ['name', 'link_rewrite'], true) && trim($value) === '') {
                    $errors[] = sprintf('%s (%s) cannot be empty.', $f, $this->isoForLang($idLang));
                    continue;
                }
                $check = $c->validateField($f, $value, $idLang, [], true);
                if ($check !== true) {
                    $errors[] = $f . ' (' . $this->isoForLang($idLang) . '): ' . (is_string($check) ? $check : 'invalid value');
                    continue;
                }
                $current = is_array($c->$f) && isset($c->{$f}[$idLang]) ? (string) $c->{$f}[$idLang] : '';
                if ($current !== $value) {
                    $planned[] = [$f, $idLang, $current, $value];
                }
            }
        }
        if (isset($args['active'])) {
            $after = (bool) $args['active'];
            $before = (bool) $c->active;
            if ($before !== $after) {
                if ($c->is_root_category || $id === (int) \Configuration::get('PS_ROOT_CATEGORY')) {
                    $errors[] = 'The root / home category cannot be disabled.';
                } else {
                    $planned[] = ['active', null, $before, $after];
                }
            }
        }
        if ($errors) {
            throw new ToolException('Validation failed: ' . implode(' | ', $errors), 'Fix the listed values; nothing was saved.');
        }
        foreach ($planned as $p) {
            list($f, $idLang, $before, $after) = $p;
            if ($idLang) {
                $arr = (array) $c->$f;
                $arr[$idLang] = $after;
                $c->$f = $arr;
            } else {
                $c->$f = $after;
            }
            $ctx->recordChange('category', $id, $f, $before, $after, $s, $idLang ?: null);
        }
        if ($planned && !$ctx->dryRun) {
            // Parent unchanged: skip the (slow) nested-set rebuild.
            $c->doNotRegenerateNTree = true;
            if (!$c->update()) {
                throw new ToolException('PrestaShop refused to save the category.', 'Check the shop log (shop_log_list).');
            }
        }

        return ['id_category' => $id, 'id_shop' => $s, 'fields_changed' => count($planned)];
    }

    // ------------------------------------------------------------------
    // Specific prices
    // ------------------------------------------------------------------

    public function specificPriceList(array $args, ExecutionContext $ctx): array
    {
        $s = (int) $ctx->idShop;
        $id = (int) $args['id_product'];
        $this->loadProduct($id, $s);
        $where = 'sp.`id_product` = ' . $id . ' AND sp.`id_shop` IN (0, ' . $s . ') AND sp.`id_cart` = 0';
        if (isset($args['id_product_attribute'])) {
            $where .= ' AND sp.`id_product_attribute` IN (0, ' . (int) $args['id_product_attribute'] . ')';
        }
        if (!empty($args['active_only'])) {
            $where .= ' AND (sp.`to` = \'0000-00-00 00:00:00\' OR sp.`to` >= NOW())';
        }
        $total = (int) $this->db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'specific_price` sp WHERE ' . $where);
        $rows = $this->db()->executeS(
            'SELECT sp.* FROM `' . _DB_PREFIX_ . 'specific_price` sp WHERE ' . $where . '
             ORDER BY sp.`id_product_attribute`, sp.`from_quantity`, sp.`id_specific_price` LIMIT ' . (int) $args['offset'] . ', ' . (int) $args['limit']
        ) ?: [];
        $items = [];
        foreach ($rows as $r) {
            $items[] = $this->formatSpecificPrice($r, (int) $ctx->idLang);
        }
        $out = $this->paged($items, $total, $args, 'specific_prices');
        $out['priority'] = \SpecificPrice::getPriority($id);
        $out['legend'] = 'Ids 0 = any. price null = base price kept. reduction_percent for percentage rules. Priority: the most specific match wins in that order (customer first), then the highest from_quantity.';

        return $out;
    }

    public function specificPriceCreate(array $args, ExecutionContext $ctx): array
    {
        $s = (int) $ctx->idShop;
        $id = (int) $args['id_product'];
        $this->loadProduct($id, $s);
        $ipa = (int) $args['id_product_attribute'];
        $errors = [];
        if ($ipa) {
            $own = (int) $this->db()->getValue('SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product_attribute` = ' . $ipa);
            if ($own !== $id) {
                $errors[] = sprintf('Combination #%d does not belong to product #%d.', $ipa, $id);
            }
        }
        foreach (['id_currency' => 'Currency', 'id_country' => 'Country', 'id_group' => 'Group', 'id_customer' => 'Customer'] as $f => $cls) {
            if ((int) $args[$f] > 0 && !\Validate::isLoadedObject(new $cls((int) $args[$f]))) {
                $errors[] = sprintf('%s #%d does not exist.', $cls, (int) $args[$f]);
            }
        }
        $type = $args['reduction_type'];
        $reduction = (float) $args['reduction'];
        if ($type === 'percentage' && $reduction > 100) {
            $errors[] = 'A percentage reduction must be between 0 and 100.';
        }
        $price = isset($args['price']) ? (float) $args['price'] : -1.0;
        if ($reduction <= 0 && $price < 0) {
            $errors[] = 'Pass a reduction > 0 or a fixed price.';
        }
        $from = $this->parseDate(isset($args['from']) ? $args['from'] : null, false, $errors, 'from');
        $to = $this->parseDate(isset($args['to']) ? $args['to'] : null, true, $errors, 'to');
        if ($to !== '0000-00-00 00:00:00' && $from !== '0000-00-00 00:00:00' && $to < $from) {
            $errors[] = '"to" is before "from".';
        }
        if ($to !== '0000-00-00 00:00:00' && $to < date('Y-m-d H:i:s')) {
            $errors[] = '"to" is in the past: the rule would never apply.';
        }
        $idShop = !empty($args['all_shops']) ? 0 : $s;
        if ($idShop === 0) {
            $this->assertAllShopsAllowed($ctx, 'A specific price for all shops');
        }
        if ($errors) {
            throw new ToolException('Validation failed: ' . implode(' | ', $errors), 'Fix the listed values; nothing was saved.');
        }
        if (\SpecificPrice::exists($id, $ipa, $idShop, (int) $args['id_group'], (int) $args['id_country'], (int) $args['id_currency'], (int) $args['id_customer'], (int) $args['from_quantity'], $from, $to)) {
            throw new ToolException('An identical specific price (same product, combination, shop, group, country, currency, customer, quantity and dates) already exists.', 'Delete it first with catalog_specific_price_delete (catalog_specific_price_list shows it).');
        }

        $sp = new \SpecificPrice();
        $sp->id_product = $id;
        $sp->id_product_attribute = $ipa;
        $sp->id_shop = $idShop;
        $sp->id_shop_group = 0;
        $sp->id_cart = 0;
        $sp->id_currency = (int) $args['id_currency'];
        $sp->id_country = (int) $args['id_country'];
        $sp->id_group = (int) $args['id_group'];
        $sp->id_customer = (int) $args['id_customer'];
        $sp->id_specific_price_rule = 0;
        $sp->price = $price;
        $sp->from_quantity = (int) $args['from_quantity'];
        $sp->reduction = $type === 'percentage' ? round($reduction / 100, 6) : $reduction;
        $sp->reduction_type = $type;
        $sp->reduction_tax = !empty($args['reduction_tax']) ? 1 : 0;
        $sp->from = $from;
        $sp->to = $to;
        $check = $sp->validateFields(false, true);
        if ($check !== true) {
            throw new ToolException('Validation failed: ' . (is_string($check) ? $check : 'invalid specific price'), 'Fix the values; nothing was saved.');
        }

        $summary = [
            'id_product' => $id, 'id_product_attribute' => $ipa, 'id_shop' => $idShop, 'id_currency' => $sp->id_currency,
            'id_country' => $sp->id_country, 'id_group' => $sp->id_group, 'id_customer' => $sp->id_customer,
            'price' => $price < 0 ? null : $price, 'from_quantity' => $sp->from_quantity, 'reduction_type' => $type,
            'reduction' => $reduction, 'reduction_tax' => (bool) $sp->reduction_tax, 'from' => $from, 'to' => $to,
        ];
        $newId = null;
        if (!$ctx->dryRun) {
            if (!$sp->add()) {
                throw new ToolException('PrestaShop refused to save the specific price.', 'Check the shop log (shop_log_list).');
            }
            $newId = (int) $sp->id;
            \Product::flushPriceCache();
        }
        $ctx->recordChange('specific_price', $newId ?: 'new', 'created', null, $summary, $idShop ?: null);
        $ctx->addWarning('Check the resulting price with catalog_price_explain.');

        return [
            'id_specific_price' => $newId,
            'id_product' => $id,
            'final_price_tax_incl_now' => $ctx->dryRun ? null : $this->finalPrice($id, $ipa ?: null, true),
        ];
    }

    public function specificPriceDelete(array $args, ExecutionContext $ctx): array
    {
        $s = (int) $ctx->idShop;
        $idSp = (int) $args['id_specific_price'];
        $row = $this->db()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'specific_price` WHERE `id_specific_price` = ' . $idSp);
        if (!$row) {
            throw new ToolException(sprintf('Specific price #%d does not exist.', $idSp), 'catalog_specific_price_list lists the rules of a product.');
        }
        if ((int) $row['id_shop'] !== 0 && (int) $row['id_shop'] !== $s) {
            throw new ToolException(sprintf('Specific price #%d belongs to shop #%d.', $idSp, (int) $row['id_shop']), 'Call the tool with id_shop=' . (int) $row['id_shop'] . '.');
        }
        if ((int) $row['id_specific_price_rule'] > 0) {
            throw new ToolException(
                sprintf('Specific price #%d is generated by catalog price rule #%d; it would be recreated.', $idSp, (int) $row['id_specific_price_rule']),
                'Change or delete the catalog price rule in Catalog > Discounts > Catalog Price Rules.'
            );
        }
        if ((int) $row['id_shop'] === 0) {
            $this->assertAllShopsAllowed($ctx, sprintf('Specific price #%d', $idSp));
            $ctx->addWarning('This rule applied to all shops; deleting it removes it everywhere.');
        }
        $before = $this->formatSpecificPrice($row, (int) $ctx->idLang);
        $ctx->recordChange('specific_price', $idSp, 'deleted', $before, null, (int) $row['id_shop'] ?: null);
        if (!$ctx->dryRun) {
            $sp = new \SpecificPrice($idSp);
            if (!$sp->delete()) {
                throw new ToolException('PrestaShop refused to delete the specific price.');
            }
            \Product::flushPriceCache();
        }

        return ['id_specific_price' => $idSp, 'id_product' => (int) $row['id_product'], 'deleted' => !$ctx->dryRun];
    }

    // ------------------------------------------------------------------
    // Price explanation
    // ------------------------------------------------------------------

    public function priceExplain(array $args, ExecutionContext $ctx): array
    {
        $s = (int) $ctx->idShop;
        $shop = new \Shop($s);
        $g = (int) $shop->id_shop_group;
        $id = (int) $args['id_product'];
        $p = $this->loadProduct($id, $s);
        $qty = (int) $args['quantity'];
        $why = [];

        // Combination
        $ipa = isset($args['id_product_attribute']) ? (int) $args['id_product_attribute'] : null;
        $combos = (int) $this->db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_attribute_shop` WHERE `id_product` = ' . $id . ' AND `id_shop` = ' . $s);
        if ($ipa === null) {
            $ipa = $combos ? (int) \Product::getDefaultAttribute($id) : 0;
            if ($ipa) {
                $why[] = sprintf('No combination given: the default combination #%d is used.', $ipa);
            }
        } elseif ($ipa > 0 && !(int) $this->db()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute_shop` WHERE `id_product` = ' . $id . ' AND `id_product_attribute` = ' . $ipa . ' AND `id_shop` = ' . $s)) {
            throw new ToolException(sprintf('Combination #%d does not belong to product #%d in this shop.', $ipa, $id), 'Call catalog_combination_list.');
        }

        // Customer, group, country, currency
        $idCustomer = !empty($args['id_customer']) ? (int) $args['id_customer'] : 0;
        $customer = null;
        if ($idCustomer) {
            $customer = new \Customer($idCustomer);
            if (!\Validate::isLoadedObject($customer)) {
                throw new ToolException(sprintf('Customer #%d does not exist.', $idCustomer));
            }
        }
        if (!empty($args['id_group'])) {
            $idGroup = (int) $args['id_group'];
            $why[] = sprintf('Group #%d given explicitly.', $idGroup);
        } elseif ($customer) {
            $idGroup = (int) $customer->id_default_group;
            $why[] = sprintf('Group #%d is the customer default group.', $idGroup);
        } else {
            $idGroup = \Group::isFeatureActive() ? (int) $this->conf('PS_UNIDENTIFIED_GROUP', $s) : (int) $this->conf('PS_CUSTOMER_GROUP', $s);
            $why[] = sprintf('No customer: visitor group #%d.', $idGroup);
        }
        $group = new \Group($idGroup, (int) $ctx->idLang);
        if (!\Validate::isLoadedObject($group)) {
            throw new ToolException(sprintf('Group #%d does not exist.', $idGroup));
        }
        $idState = 0;
        $zip = '';
        $address = null;
        if (!empty($args['id_country'])) {
            $idCountry = (int) $args['id_country'];
        } elseif ($customer && ($idAddr = (int) \Address::getFirstCustomerAddressId($idCustomer))) {
            $address = new \Address($idAddr);
            $idCountry = (int) $address->id_country;
            $idState = (int) $address->id_state;
            $zip = (string) $address->postcode;
            $why[] = sprintf('Country from the customer first address #%d (at checkout the cart address decides).', $idAddr);
        } else {
            $idCountry = (int) $this->conf('PS_COUNTRY_DEFAULT', $s);
        }
        $country = new \Country($idCountry, (int) $ctx->idLang);
        if (!\Validate::isLoadedObject($country)) {
            throw new ToolException(sprintf('Country #%d does not exist.', $idCountry));
        }
        $idCurrency = !empty($args['id_currency']) ? (int) $args['id_currency'] : (int) $this->conf('PS_CURRENCY_DEFAULT', $s);
        $currency = new \Currency($idCurrency);
        if (!\Validate::isLoadedObject($currency)) {
            throw new ToolException(sprintf('Currency #%d does not exist.', $idCurrency));
        }

        // Tax
        $taxEnabled = (bool) $this->conf('PS_TAX', $s) && !\Tax::excludeTaxeOption();
        if ($address && $taxEnabled && !empty($address->vat_number) && \Configuration::get('VATNUMBER_MANAGEMENT')
            && (int) $address->id_country !== (int) \Configuration::get('VATNUMBER_COUNTRY')) {
            $taxEnabled = false;
            $why[] = 'Customer address has a VAT number in another country: prices are tax-free.';
        }
        $idTrg = (int) \Product::getIdTaxRulesGroupByIdProduct($id);
        $taxAddr = new \Address();
        $taxAddr->id_country = $idCountry;
        $taxAddr->id_state = $idState;
        $taxAddr->postcode = $zip;
        $rate = 0.0;
        $taxNames = '';
        try {
            $calc = \TaxManagerFactory::getManager($taxAddr, $idTrg)->getTaxCalculator();
            $rate = (float) $calc->getTotalRate();
            $taxNames = (string) $calc->getTaxesName();
        } catch (\Throwable $e) {
            $ctx->addWarning('Tax rate could not be computed: ' . $e->getMessage());
        }

        // Raw amounts
        $base = $this->db()->getRow('SELECT `price`, `ecotax` FROM `' . _DB_PREFIX_ . 'product_shop` WHERE `id_product` = ' . $id . ' AND `id_shop` = ' . $s);
        $impact = ['price' => 0, 'ecotax' => 0];
        if ($ipa) {
            $impact = $this->db()->getRow('SELECT `price`, `ecotax` FROM `' . _DB_PREFIX_ . 'product_attribute_shop` WHERE `id_product_attribute` = ' . $ipa . ' AND `id_shop` = ' . $s) ?: $impact;
        }

        \Product::flushPriceCache();
        $calcFn = function ($useTax, $useReduc, $ecotax, $groupReduc, &$sp) use ($s, $id, $ipa, $idCountry, $idState, $zip, $idCurrency, $idGroup, $qty, $idCustomer) {
            $sp = null;

            return (float) \Product::priceCalculation($s, $id, $ipa, $idCountry, $idState, $zip, $idCurrency, $idGroup, $qty, (bool) $useTax, 6, false, (bool) $useReduc, (bool) $ecotax, $sp, (bool) $groupReduc, $idCustomer, true, 0, $qty);
        };
        $sp = null;
        $noReduc = $calcFn(false, false, false, false, $sp);
        $afterSp = $calcFn(false, true, false, false, $sp);
        $afterGroupEx = $calcFn(false, true, false, true, $sp);
        $finalEx = $calcFn(false, true, true, true, $sp);
        $finalIn = $calcFn($taxEnabled, true, true, true, $sp);
        $finalInNoReduc = $calcFn($taxEnabled, false, true, false, $sp);
        $winner = $sp && !empty($sp['id_specific_price']) ? (int) $sp['id_specific_price'] : 0;

        // Specific price candidates
        $now = date('Y-m-d H:i:s');
        $candidates = [];
        $rows = $this->db()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'specific_price` WHERE `id_product` IN (0, ' . $id . ') AND `id_cart` = 0
             ORDER BY `id_specific_price` LIMIT 100'
        ) ?: [];
        foreach ($rows as $r) {
            $fails = [];
            if ((int) $r['id_product'] === 0) {
                // catalog price rule on all products: keep, checked like the others
            }
            if ((int) $r['id_product_attribute'] && (int) $r['id_product_attribute'] !== (int) $ipa) {
                $fails[] = 'other combination #' . (int) $r['id_product_attribute'];
            }
            if ((int) $r['id_shop'] && (int) $r['id_shop'] !== $s) {
                $fails[] = 'other shop #' . (int) $r['id_shop'];
            }
            if ((int) $r['id_shop_group'] && (int) $r['id_shop_group'] !== $g) {
                $fails[] = 'other shop group';
            }
            if ((int) $r['id_currency'] && (int) $r['id_currency'] !== $idCurrency) {
                $fails[] = 'currency #' . (int) $r['id_currency'];
            }
            if ((int) $r['id_country'] && (int) $r['id_country'] !== $idCountry) {
                $fails[] = 'country #' . (int) $r['id_country'];
            }
            if ((int) $r['id_group'] && (int) $r['id_group'] !== $idGroup) {
                $fails[] = 'group #' . (int) $r['id_group'];
            }
            if ((int) $r['id_customer'] && (int) $r['id_customer'] !== $idCustomer) {
                $fails[] = 'customer #' . (int) $r['id_customer'];
            }
            if ((int) $r['from_quantity'] > $qty) {
                $fails[] = 'needs quantity >= ' . (int) $r['from_quantity'];
            }
            if ($r['from'] !== '0000-00-00 00:00:00' && $r['from'] > $now) {
                $fails[] = 'starts ' . $r['from'];
            }
            if ($r['to'] !== '0000-00-00 00:00:00' && $r['to'] < $now) {
                $fails[] = 'ended ' . $r['to'];
            }
            if ((int) $r['id_product'] === 0 && !$fails) {
                // rule rows with id_product 0 come from catalog price rules on product conditions; keep as matching
            }
            $row = $this->formatSpecificPrice($r, (int) $ctx->idLang);
            $row['matches'] = !$fails;
            $row['winner'] = (int) $r['id_specific_price'] === $winner;
            if ($fails) {
                $row['not_applied_because'] = implode(', ', $fails);
            }
            $candidates[] = $row;
        }
        usort($candidates, function ($a, $b) {
            $ka = ($a['winner'] ? 0 : ($a['matches'] ? 1 : 2));
            $kb = ($b['winner'] ? 0 : ($b['matches'] ? 1 : 2));

            return $ka === $kb ? $b['id_specific_price'] - $a['id_specific_price'] : $ka - $kb;
        });
        $matching = array_filter($candidates, function ($c) {
            return $c['matches'];
        });
        if ($winner) {
            $why[] = sprintf(
                'Specific price #%d applies%s.',
                $winner,
                count($matching) > 1 ? sprintf(' (%d rules match; PrestaShop takes the most specific by priority %s, then the highest from_quantity)', count($matching), implode(' > ', array_unique((array) \SpecificPrice::getPriority($id)))) : ''
            );
            if ((float) $sp['price'] >= 0) {
                $why[] = sprintf(
                    'It sets a fixed price of %s tax excl, which replaces the base price%s.',
                    round((float) $sp['price'], 6),
                    (int) $sp['id_product_attribute'] ? ' and the combination impact' : ' (the combination impact is still added)'
                );
            }
            if ((float) $sp['reduction'] > 0) {
                $why[] = $sp['reduction_type'] === 'percentage'
                    ? sprintf('It removes %s%%.', round((float) $sp['reduction'] * 100, 4))
                    : sprintf('It removes %s (%s).', round((float) $sp['reduction'], 6), $sp['reduction_tax'] ? 'tax incl' : 'tax excl');
            }
        } elseif ($matching) {
            $why[] = 'Matching rules exist but none was selected by PrestaShop (check shop group / catalog price rule conditions).';
        } else {
            $why[] = 'No specific price applies.';
        }

        $catReduction = \GroupReduction::getValueForProduct($id, $idGroup);
        $groupReduction = (float) \Group::getReductionByIdGroup($idGroup);
        if ($catReduction !== false) {
            $why[] = sprintf('Group category reduction %s%% applies (default category of the product), replacing the group reduction.', round((float) $catReduction * 100, 2));
        } elseif ($groupReduction > 0) {
            $why[] = sprintf('Group "%s" reduction %s%% applies.', $group->name, $groupReduction);
        }
        if (!$taxEnabled) {
            $why[] = 'Taxes are disabled (PS_TAX=0 or tax excluded option): tax incl = tax excl.';
        }
        $display = (int) \Group::getPriceDisplayMethod($idGroup);
        $why[] = sprintf('Group "%s" sees prices %s.', $group->name, $display ? 'tax excluded' : 'tax included');
        if (!$p->show_price && !$p->available_for_order) {
            $why[] = 'The product hides its price (show_price=0).';
        }
        if ($this->conf('PS_CATALOG_MODE', $s)) {
            $why[] = 'Catalog mode is on: customers may not see prices/cart.';
        }
        $r6 = function ($v) {
            return round((float) $v, 6);
        };

        return [
            'id_product' => $id,
            'id_product_attribute' => (int) $ipa,
            'id_shop' => $s,
            'inputs' => [
                'id_customer' => $idCustomer ?: null,
                'id_group' => $idGroup,
                'group' => is_array($group->name) ? reset($group->name) : $group->name,
                'id_country' => $idCountry,
                'country' => is_array($country->name) ? reset($country->name) : $country->name,
                'currency' => $currency->iso_code,
                'quantity' => $qty,
            ],
            'steps' => [
                'base_price_tax_excl_default_currency' => $r6($base['price']),
                'combination_impact_tax_excl' => $r6($impact['price']),
                'price_before_reductions_tax_excl' => $r6($noReduc),
                'after_specific_price_tax_excl' => $r6($afterSp),
                'after_group_reduction_tax_excl' => $r6($afterGroupEx),
                'ecotax_tax_excl' => $r6((float) $impact['ecotax'] > 0 ? $impact['ecotax'] : $base['ecotax']),
                'final_price_tax_excl' => $r6($finalEx),
                'tax_rules_group' => $idTrg ? (string) $this->db()->getValue('SELECT `name` FROM `' . _DB_PREFIX_ . 'tax_rules_group` WHERE `id_tax_rules_group` = ' . $idTrg) : null,
                'id_tax_rules_group' => $idTrg,
                'tax_rate' => $taxEnabled ? $rate : 0.0,
                'taxes' => $taxNames,
                'final_price_tax_incl' => $r6($finalIn),
                'final_price_tax_incl_without_reductions' => $r6($finalInNoReduc),
                'group_reduction_percent' => $groupReduction,
                'category_group_reduction_percent' => $catReduction !== false ? round((float) $catReduction * 100, 4) : null,
                'displayed_price' => round($display ? $finalEx : $finalIn, property_exists($currency, 'precision') ? (int) $currency->precision : 2),
                'displayed_as' => $display ? 'tax_excl' : 'tax_incl',
            ],
            'winning_specific_price' => $winner ?: null,
            'specific_price_candidates' => array_slice(array_map(function ($c) {
                return $c['matches'] ? $c : ['id_specific_price' => $c['id_specific_price'], 'status' => $c['status'], 'not_applied_because' => $c['not_applied_because']];
            }, $candidates), 0, 20),
            'specific_price_candidates_total' => count($candidates),
            'why' => $why,
            'note' => 'Computed with Product::priceCalculation for these inputs (no cart rules / vouchers). Context was not changed.',
        ];
    }

    // ------------------------------------------------------------------
    // Issue scan
    // ------------------------------------------------------------------

    public function productIssueScan(array $args, ExecutionContext $ctx): array
    {
        $s = (int) $ctx->idShop;
        $l = (int) $ctx->idLang;
        $issue = $args['issue'];
        $onlyActive = !empty($args['only_active']);
        $langIds = [];
        foreach (\Language::getLanguages(true, $s) as $lg) {
            $langIds[] = (int) $lg['id_lang'];
        }
        if (!$langIds) {
            $langIds = [$l];
        }

        if ($issue !== 'all') {
            if (in_array($issue, ['duplicate_reference', 'duplicate_ean13'], true)) {
                $col = $issue === 'duplicate_reference' ? 'reference' : 'ean13';
                list($total, $groups) = $this->duplicateGroups($col, $s, $onlyActive, (int) $args['limit'], (int) $args['offset']);
                $out = $this->paged($groups, $total, $args, 'groups');
                $out['issue'] = $issue;
                $out['description'] = self::ISSUES[$issue];

                return $out;
            }
            $where = $this->issueWhere($issue, $s, $langIds, $onlyActive);
            $total = (int) $this->db()->getValue('SELECT COUNT(*)' . $this->issueFrom($s, $l) . ' WHERE ' . $where);
            $rows = $this->issueRows($issue, $where, $s, $l, $langIds, (int) $args['limit'], (int) $args['offset']);
            $out = $this->paged($rows, $total, $args, 'products');
            $out['issue'] = $issue;
            $out['description'] = self::ISSUES[$issue];

            return $out;
        }

        $sample = (int) $args['sample_size'];
        $summary = [];
        foreach (array_keys(self::ISSUES) as $key) {
            if (in_array($key, ['duplicate_reference', 'duplicate_ean13'], true)) {
                $col = $key === 'duplicate_reference' ? 'reference' : 'ean13';
                list($total, $groups) = $this->duplicateGroups($col, $s, $onlyActive, $sample, 0);
                $summary[$key] = ['description' => self::ISSUES[$key], 'groups' => $total, 'sample' => $groups];
                continue;
            }
            $where = $this->issueWhere($key, $s, $langIds, $onlyActive);
            $count = (int) $this->db()->getValue('SELECT COUNT(*)' . $this->issueFrom($s, $l) . ' WHERE ' . $where);
            $summary[$key] = [
                'description' => self::ISSUES[$key],
                'count' => $count,
                'sample' => $count && $sample ? $this->issueRows($key, $where, $s, $l, $langIds, $sample, 0) : [],
            ];
        }
        $scanned = (int) $this->db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_shop` ps WHERE ps.`id_shop` = ' . $s . ($onlyActive ? ' AND ps.`active` = 1' : ''));
        $out = [
            'id_shop' => $s,
            'products_scanned' => $scanned,
            'only_active' => $onlyActive,
            'languages_checked' => array_map([$this, 'isoForLang'], $langIds),
            'issues' => $summary,
            'next' => 'Call again with issue=<type> for the full paged list.',
        ];
        if (!$this->conf('PS_STOCK_MANAGEMENT', $s)) {
            $out['note'] = 'Stock management is disabled: unsellable_out_of_stock is not checked.';
        }

        return $out;
    }

    public function manufacturerList(array $args, ExecutionContext $ctx): array
    {
        $s = (int) $ctx->idShop;
        $where = ['1'];
        if (isset($args['query']) && trim($args['query']) !== '') {
            $where[] = 'm.`name` LIKE \'%' . pSQL($this->likeEscape(trim($args['query']))) . '%\'';
        }
        if (isset($args['active'])) {
            $where[] = 'm.`active` = ' . ($args['active'] ? 1 : 0);
        }
        $from = ' FROM `' . _DB_PREFIX_ . 'manufacturer` m
            INNER JOIN `' . _DB_PREFIX_ . 'manufacturer_shop` ms ON ms.`id_manufacturer` = m.`id_manufacturer` AND ms.`id_shop` = ' . $s . '
            WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db()->getValue('SELECT COUNT(*)' . $from);
        $rows = $this->db()->executeS(
            'SELECT m.`id_manufacturer`, m.`name`, m.`active`,
                (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product` p INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $s . ' WHERE p.`id_manufacturer` = m.`id_manufacturer`) AS products,
                (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product` p INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $s . ' WHERE p.`id_manufacturer` = m.`id_manufacturer` AND ps.`active` = 1) AS active_products'
            . $from . ' ORDER BY TRIM(m.`name`) LIMIT ' . (int) $args['offset'] . ', ' . (int) $args['limit']
        ) ?: [];
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id_manufacturer' => (int) $r['id_manufacturer'],
                'name' => (string) $r['name'],
                'active' => (bool) $r['active'],
                'products' => (int) $r['products'],
                'active_products' => (int) $r['active_products'],
            ];
        }
        $out = $this->paged($items, $total, $args, 'manufacturers');
        if ((int) $args['offset'] === 0 && !isset($args['query'])) {
            $out['products_without_brand'] = (int) $this->db()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product` p INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $s . ' WHERE p.`id_manufacturer` = 0'
            );
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return \Product
     *
     * @throws ToolException
     */
    private function loadProduct(int $id, int $idShop, ?int $idLang = null)
    {
        if ($id <= 0) {
            throw new ToolException('id_product must be a positive integer.');
        }
        $p = new \Product($id, false, $idLang, $idShop);
        if (!\Validate::isLoadedObject($p)) {
            throw new ToolException(sprintf('Product #%d does not exist.', $id), 'Use catalog_product_search to find the product id.');
        }
        if (!$p->isAssociatedToShop($idShop)) {
            $shops = array_map('intval', $p->getAssociatedShops());
            throw new ToolException(
                sprintf('Product #%d is not available in shop #%d.', $id, $idShop),
                $shops ? 'It belongs to shop(s) ' . implode(', ', $shops) . '; pass id_shop.' : 'It is not associated with any shop.'
            );
        }

        return $p;
    }

    /**
     * @return \Category
     *
     * @throws ToolException
     */
    private function loadCategory(int $id, int $idShop, ?int $idLang = null)
    {
        $c = new \Category($id, $idLang, $idShop);
        if (!\Validate::isLoadedObject($c)) {
            throw new ToolException(sprintf('Category #%d does not exist.', $id), 'Use catalog_category_tree to find category ids.');
        }
        if (!$c->isAssociatedToShop($idShop)) {
            throw new ToolException(sprintf('Category #%d is not in shop #%d.', $id, $idShop));
        }

        return $c;
    }

    private function conf(string $key, int $idShop)
    {
        $shop = new \Shop($idShop);

        return \Configuration::get($key, null, (int) $shop->id_shop_group, $idShop);
    }

    private function sharesStock(int $idShop): bool
    {
        if (!isset($this->shareStock[$idShop])) {
            $shop = new \Shop($idShop);
            $group = new \ShopGroup((int) $shop->id_shop_group);
            $this->shareStock[$idShop] = (bool) $group->share_stock;
        }

        return $this->shareStock[$idShop];
    }

    /**
     * stock_available shop condition (shared stock groups keep rows per group with id_shop = 0).
     */
    private function stockShopSql(string $alias, int $idShop): string
    {
        if ($this->sharesStock($idShop)) {
            $shop = new \Shop($idShop);

            return $alias . '.`id_shop_group` = ' . (int) $shop->id_shop_group . ' AND ' . $alias . '.`id_shop` = 0';
        }

        return $alias . '.`id_shop` = ' . $idShop;
    }

    /**
     * Final price in the shop context (visitor / employee context, default currency).
     */
    private function finalPrice(int $id, ?int $ipa, bool $tax, bool $reduc = true): ?float
    {
        try {
            $sp = null;
            $v = \Product::getPriceStatic($id, $tax, $ipa, 6, null, false, $reduc, 1, false, null, null, null, $sp, true, true);

            return $v === null ? null : round((float) $v, 2);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * StockAvailable::setQuantity only changes `quantity`; the back office then
     * recomputes reserved/physical quantities (physical = available + reserved).
     */
    private function syncPhysicalQuantity(int $id, int $idShop): void
    {
        $adapter = 'PrestaShop\\PrestaShop\\Adapter\\StockManager';
        if (!$this->sharesStock($idShop) && class_exists($adapter) && method_exists($adapter, 'updatePhysicalProductQuantity')) {
            try {
                (new $adapter())->updatePhysicalProductQuantity(
                    $idShop,
                    (int) $this->conf('PS_OS_ERROR', $idShop),
                    (int) $this->conf('PS_OS_CANCELED', $idShop),
                    $id
                );

                return;
            } catch (\Throwable $e) {
                // fall back to the plain recomputation below
            }
        }
        $this->dbWrite()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'stock_available` sa SET sa.`physical_quantity` = sa.`quantity` + sa.`reserved_quantity`
             WHERE sa.`id_product` = ' . $id . ' AND ' . $this->stockShopSql('sa', $idShop)
        );
    }

    private function likeEscape(string $s): string
    {
        return addcslashes($s, '%_\\');
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private function castValue($value, string $type)
    {
        switch ($type) {
            case 'float':
                return round((float) $value, 6);
            case 'int':
                return (int) $value;
            case 'bool':
                return (bool) (int) $value;
            default:
                return trim((string) $value);
        }
    }

    private function pickLang($value, int $idLang)
    {
        if (!is_array($value)) {
            return $value;
        }

        return isset($value[$idLang]) ? $value[$idLang] : null;
    }

    /**
     * Multilang output: current language value, or every language keyed by ISO.
     * $truncate > 0 turns HTML into a plain-text excerpt (with its full length).
     */
    private function mlOut(array $byLang, bool $all, int $idLang, int $truncate = 0)
    {
        $fmt = function ($v) use ($truncate) {
            $v = (string) $v;
            if ($truncate <= 0) {
                return $v;
            }
            $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($v), ENT_QUOTES, 'UTF-8')));
            if (mb_strlen($plain) <= $truncate) {
                return $plain;
            }

            return mb_substr($plain, 0, $truncate) . '… [' . mb_strlen($v) . ' chars HTML, use full_text=true]';
        };
        if (!$all) {
            return $fmt(isset($byLang[$idLang]) ? $byLang[$idLang] : '');
        }
        $out = [];
        foreach ($byLang as $lid => $v) {
            $out[$this->isoForLang((int) $lid)] = $fmt($v);
        }

        return $out;
    }

    private function imageType(string $name): ?string
    {
        try {
            $formatted = method_exists('ImageType', 'getFormattedName') ? \ImageType::getFormattedName($name) : \ImageType::getFormatedName($name);
            if ($formatted && (int) $this->db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'image_type` WHERE `name` = \'' . pSQL($formatted) . '\' AND `products` = 1')) {
                return $formatted;
            }
        } catch (\Throwable $e) {
            // fall through to the original image
        }

        return null;
    }

    private function categoryRows(array $ids, int $s, int $l, bool $inactive, string $col): array
    {
        $ids = array_map('intval', $ids);

        return $this->db()->executeS(
            'SELECT c.`id_category`, c.`id_parent`, c.`active`, c.`level_depth`, cl.`name`
             FROM `' . _DB_PREFIX_ . 'category` c
             INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . $s . '
             LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.`id_category` = c.`id_category` AND cl.`id_lang` = ' . $l . ' AND cl.`id_shop` = ' . $s . '
             WHERE ' . $col . ' IN (' . implode(',', $ids) . ')' . ($inactive ? '' : ' AND c.`active` = 1') . '
             ORDER BY c.`id_parent`, cs.`position`, c.`id_category`'
        ) ?: [];
    }

    private function treeNode(array $r): array
    {
        return ['id_category' => (int) $r['id_category'], 'name' => (string) $r['name'], 'active' => (bool) $r['active']];
    }

    private function formatSpecificPrice(array $r, int $idLang): array
    {
        $now = date('Y-m-d H:i:s');
        $from = $r['from'];
        $to = $r['to'];
        $status = 'active';
        if ($to !== '0000-00-00 00:00:00' && $to < $now) {
            $status = 'expired';
        } elseif ($from !== '0000-00-00 00:00:00' && $from > $now) {
            $status = 'upcoming';
        }
        $out = [
            'id_specific_price' => (int) $r['id_specific_price'],
            'id_product_attribute' => (int) $r['id_product_attribute'],
            'id_shop' => (int) $r['id_shop'],
            'id_currency' => (int) $r['id_currency'],
            'id_country' => (int) $r['id_country'],
            'id_group' => (int) $r['id_group'],
            'id_customer' => (int) $r['id_customer'],
            'from_quantity' => (int) $r['from_quantity'],
            'price' => (float) $r['price'] < 0 ? null : round((float) $r['price'], 6),
            'reduction_type' => $r['reduction_type'],
            'reduction' => round((float) $r['reduction'], 6),
            'reduction_tax' => (bool) $r['reduction_tax'],
            'from' => $from === '0000-00-00 00:00:00' ? null : $from,
            'to' => $to === '0000-00-00 00:00:00' ? null : $to,
            'status' => $status,
        ];
        if ($r['reduction_type'] === 'percentage') {
            $out['reduction_percent'] = round((float) $r['reduction'] * 100, 4);
        }
        if ((int) $r['id_specific_price_rule']) {
            $out['id_specific_price_rule'] = (int) $r['id_specific_price_rule'];
        }
        if ((int) $r['id_currency']) {
            $out['currency'] = (string) $this->db()->getValue('SELECT `iso_code` FROM `' . _DB_PREFIX_ . 'currency` WHERE `id_currency` = ' . (int) $r['id_currency']) ?: null;
        }
        if ((int) $r['id_country']) {
            $out['country'] = \Country::getIsoById((int) $r['id_country']) ?: null;
        }
        if ((int) $r['id_group']) {
            $out['group'] = (string) $this->db()->getValue('SELECT `name` FROM `' . _DB_PREFIX_ . 'group_lang` WHERE `id_group` = ' . (int) $r['id_group'] . ' AND `id_lang` = ' . $idLang);
        }
        if ((int) $r['id_customer']) {
            $c = $this->db()->getRow('SELECT `firstname`, `lastname` FROM `' . _DB_PREFIX_ . 'customer` WHERE `id_customer` = ' . (int) $r['id_customer']);
            $out['customer'] = $c ? trim($c['firstname'] . ' ' . $c['lastname']) : null;
        }

        return $out;
    }

    /**
     * @param string[] $errors
     */
    private function parseDate(?string $value, bool $endOfDay, array &$errors, string $field): string
    {
        if ($value === null || trim($value) === '') {
            return '0000-00-00 00:00:00';
        }
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value .= $endOfDay ? ' 23:59:59' : ' 00:00:00';
        }
        $ts = strtotime($value);
        if ($ts === false || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $value)) {
            $errors[] = sprintf('%s must be "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS".', $field);

            return '0000-00-00 00:00:00';
        }

        return date('Y-m-d H:i:s', $ts);
    }

    private function issueFrom(int $s, int $l): string
    {
        return ' FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $s . '
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.`id_product` = p.`id_product` AND pl.`id_lang` = ' . $l . ' AND pl.`id_shop` = ' . $s . '
            LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` sa ON sa.`id_product` = p.`id_product` AND sa.`id_product_attribute` = 0 AND ' . $this->stockShopSql('sa', $s);
    }

    /**
     * SQL condition "field is non-empty" for product_lang alias x.
     */
    private static function nonEmptySql(string $col): string
    {
        return 'TRIM(REPLACE(REPLACE(REPLACE(IFNULL(x.`' . $col . '`, \'\'), \'<p>\', \'\'), \'</p>\', \'\'), \'&nbsp;\', \'\')) <> \'\'';
    }

    private static function langFieldOfIssue(string $issue): ?string
    {
        $map = [
            'empty_short_description' => 'description_short',
            'empty_description' => 'description',
            'missing_translation' => 'name',
            'empty_link_rewrite' => 'link_rewrite',
        ];

        return isset($map[$issue]) ? $map[$issue] : null;
    }

    private function issueWhere(string $issue, int $s, array $langIds, bool $onlyActive): string
    {
        $w = [];
        if ($onlyActive && $issue !== 'inactive_with_stock') {
            $w[] = 'ps.`active` = 1';
        }
        $col = self::langFieldOfIssue($issue);
        if ($col) {
            $w[] = '(SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_lang` x WHERE x.`id_product` = p.`id_product` AND x.`id_shop` = ' . $s
                . ' AND x.`id_lang` IN (' . implode(',', $langIds) . ') AND ' . self::nonEmptySql($col) . ') < ' . count($langIds);
        }
        switch ($issue) {
            case 'no_cover':
                $w[] = 'NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'image_shop` ims WHERE ims.`id_product` = p.`id_product` AND ims.`id_shop` = ' . $s . ' AND ims.`cover` = 1)';
                break;
            case 'zero_price':
                $w[] = 'ps.`price` <= 0 AND NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute_shop` pas WHERE pas.`id_product` = p.`id_product` AND pas.`id_shop` = ' . $s . ' AND pas.`price` > 0)';
                break;
            case 'unsellable_out_of_stock':
                if (!$this->conf('PS_STOCK_MANAGEMENT', $s)) {
                    $w[] = '0';
                    break;
                }
                $shopDeny = (int) $this->conf('PS_ORDER_OUT_OF_STOCK', $s) ? '0' : '1';
                $w[] = 'ps.`active` = 1 AND ps.`available_for_order` = 1 AND IFNULL(sa.`quantity`, 0) <= 0
                    AND (IFNULL(sa.`out_of_stock`, 2) = 0 OR (IFNULL(sa.`out_of_stock`, 2) = 2 AND ' . $shopDeny . ' = 1))';
                break;
            case 'only_home_category':
                $w[] = 'NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'category_product` cp WHERE cp.`id_product` = p.`id_product` AND cp.`id_category` NOT IN ('
                    . (int) \Configuration::get('PS_ROOT_CATEGORY') . ', ' . (int) \Configuration::get('PS_HOME_CATEGORY') . ', ' . (int) (new \Shop($s))->id_category . '))';
                break;
            case 'inactive_with_stock':
                $w[] = 'ps.`active` = 0 AND IFNULL(sa.`quantity`, 0) > 0';
                break;
        }

        return $w ? implode(' AND ', $w) : '1';
    }

    private function issueRows(string $issue, string $where, int $s, int $l, array $langIds, int $limit, int $offset): array
    {
        $rows = $this->db()->executeS(
            'SELECT p.`id_product`, pl.`name`, p.`reference`, ps.`active`, ps.`price`, IFNULL(sa.`quantity`, 0) AS quantity'
            . $this->issueFrom($s, $l) . ' WHERE ' . $where . ' ORDER BY p.`id_product` DESC LIMIT ' . (int) $offset . ', ' . (int) $limit
        ) ?: [];
        $col = self::langFieldOfIssue($issue);
        $filled = [];
        if ($col && $rows) {
            $ids = array_map(function ($r) {
                return (int) $r['id_product'];
            }, $rows);
            foreach ($this->db()->executeS(
                'SELECT x.`id_product`, x.`id_lang` FROM `' . _DB_PREFIX_ . 'product_lang` x WHERE x.`id_shop` = ' . $s . ' AND x.`id_product` IN (' . implode(',', $ids) . ')
                 AND x.`id_lang` IN (' . implode(',', $langIds) . ') AND ' . self::nonEmptySql($col)
            ) ?: [] as $f) {
                $filled[(int) $f['id_product']][(int) $f['id_lang']] = true;
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id_product'];
            $row = [
                'id_product' => $id,
                'name' => (string) $r['name'],
                'reference' => (string) $r['reference'],
                'active' => (bool) $r['active'],
            ];
            if ($col) {
                $missing = [];
                foreach ($langIds as $lid) {
                    if (empty($filled[$id][$lid])) {
                        $missing[] = $this->isoForLang($lid);
                    }
                }
                $row['empty_in'] = $missing;
            }
            if (in_array($issue, ['unsellable_out_of_stock', 'inactive_with_stock'], true)) {
                $row['quantity'] = (int) $r['quantity'];
            }
            if ($issue === 'zero_price') {
                $row['price_tax_excl'] = (float) $r['price'];
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return array [total groups, groups]
     */
    private function duplicateGroups(string $col, int $s, bool $onlyActive, int $limit, int $offset): array
    {
        $col = $col === 'ean13' ? 'ean13' : 'reference';
        $base = 'SELECT p.`' . $col . '` AS value, COUNT(*) AS n, GROUP_CONCAT(p.`id_product` ORDER BY p.`id_product` SEPARATOR \',\') AS ids
            FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $s . '
            WHERE p.`' . $col . '` IS NOT NULL AND TRIM(p.`' . $col . '`) <> \'\'' . ($col === 'ean13' ? ' AND p.`ean13` <> \'0\'' : '') . ($onlyActive ? ' AND ps.`active` = 1' : '') . '
            GROUP BY p.`' . $col . '` HAVING COUNT(*) > 1';
        $total = (int) $this->db()->getValue('SELECT COUNT(*) FROM (' . $base . ') t');
        if ($limit <= 0) {
            return [$total, []];
        }
        $rows = $this->db()->executeS($base . ' ORDER BY n DESC, value LIMIT ' . (int) $offset . ', ' . (int) $limit) ?: [];
        $groups = [];
        foreach ($rows as $r) {
            $ids = array_map('intval', array_filter(explode(',', (string) $r['ids'])));
            $groups[] = [$col => (string) $r['value'], 'count' => (int) $r['n'], 'id_products' => array_slice($ids, 0, 20)];
        }

        return [$total, $groups];
    }
}
