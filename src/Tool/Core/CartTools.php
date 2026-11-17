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
 * Carts (abandoned carts, cart contents and totals) and cart rules (vouchers).
 */
class CartTools extends AbstractToolGroup
{
    public function owner(): string
    {
        return 'cart';
    }

    public function tools(): array
    {
        $ml = ['type' => ['string', 'object'], 'description' => 'Text for the current language, or {"en": "...", "lt": "..."}.'];

        return [
            [
                'name' => 'cart_search',
                'title' => 'Search carts',
                'description' => 'Finds shopping carts (latest update first) by customer, update date range, "abandoned" (no order, has products, not updated for N hours) and minimum estimated value. Rows: cart id, customer, last update, lines, items, estimated value (catalog base prices tax excl, without discounts), order id if ordered. Use cart_get for exact totals.',
                'params' => array_merge([
                    'id_customer' => ['type' => 'integer', 'minimum' => 1],
                    'customer_email' => ['type' => 'string', 'description' => 'Partial customer email.'],
                    'date_from' => ['type' => 'string', 'description' => 'Last update from, YYYY-MM-DD[ HH:MM:SS].'],
                    'date_to' => ['type' => 'string', 'description' => 'Last update to (inclusive).'],
                    'abandoned' => self::boolParam('Only carts with products, without order, not updated for abandoned_hours.', false),
                    'abandoned_hours' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8760, 'default' => 24],
                    'ordered' => self::boolParam('true = only carts that became an order, false = only carts without order.'),
                    'min_value' => ['type' => 'number', 'minimum' => 0, 'description' => 'Minimum estimated value tax excl (shop currency).'],
                    'with_customer_only' => self::boolParam('Skip anonymous visitor carts.', false),
                ], self::paginationParams(20, 100)),
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminCarts', 'view'],
                'keywords' => ['abandoned cart', 'basket', 'checkout'],
                'handler' => 'cartSearch',
            ],
            [
                'name' => 'cart_get',
                'title' => 'Get cart details',
                'description' => 'One cart: products (name, combination, quantity, unit and line prices tax excl/incl), totals tax excl/incl computed by PrestaShop for the cart customer, currency and address (products, discounts, shipping, wrapping, total), applied cart rules, selected carrier/delivery option, addresses, customer, order id if ordered, dates.',
                'params' => [
                    'id_cart' => self::idParam('Cart ID (cart_search finds it).'),
                ],
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminCarts', 'view'],
                'keywords' => ['basket', 'cart total', 'cart content'],
                'handler' => 'cartGet',
            ],
            [
                'name' => 'cart_rule_list',
                'title' => 'List cart rules (vouchers)',
                'description' => 'Cart rules / vouchers of the shop with name, code, active, validity dates, quantity left, per-user quantity, customer restriction, action summary (percent/amount/free shipping/gift) and which restrictions are set, plus how many times used. Filters: active, code or name text, valid at a date / now, customer. Use cart_rule_get for the full conditions.',
                'params' => array_merge([
                    'query' => ['type' => 'string', 'description' => 'Part of the name or code.'],
                    'code' => ['type' => 'string', 'description' => 'Exact code.'],
                    'active' => self::boolParam('Active flag.'),
                    'valid_now' => self::boolParam('Only rules whose date range includes now and with quantity left.'),
                    'valid_on' => ['type' => 'string', 'description' => 'Only rules valid at this date (YYYY-MM-DD).'],
                    'id_customer' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Rules restricted to this customer (0 = rules for everybody).'],
                    'with_code' => self::boolParam('true = only rules with a code, false = only automatic rules (no code).'),
                ], self::paginationParams(30, 200)),
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminCartRules', 'view'],
                'keywords' => ['voucher', 'coupon', 'discount code', 'promo code'],
                'handler' => 'ruleList',
            ],
            [
                'name' => 'cart_rule_get',
                'title' => 'Get cart rule details',
                'description' => 'Full cart rule: names in all languages, code, dates, quantities, priority, partial use, minimum amount, restrictions (customer, countries, carriers, groups, shops, other cart rules compatibility, product selection rules) and actions (free shipping, percent or amount discount, target, gift product), usage count.',
                'params' => [
                    'id_cart_rule' => self::idParam('Cart rule ID (cart_rule_list finds it). Or pass code.', false),
                    'code' => ['type' => 'string', 'description' => 'Voucher code.'],
                ],
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminCartRules', 'view'],
                'keywords' => ['voucher', 'coupon', 'discount conditions'],
                'handler' => 'ruleGet',
            ],
            [
                'name' => 'cart_rule_create',
                'title' => 'Create cart rule (voucher)',
                'description' => 'Creates a cart rule / voucher: name (multilang), code (or generate_code), reduction_percent OR reduction_amount (+ reduction_tax, reduction_currency), free_shipping, validity dates (default now → +1 month), quantity, quantity_per_user, minimum amount, restriction to one customer, highlight, active. Product/country/carrier/group restrictions are not set by this tool (use the back office). Use dry_run=true to validate first.',
                'params' => [
                    'name' => $ml + ['required' => true],
                    'description' => ['type' => 'string', 'maxLength' => 65534, 'description' => 'Internal description.'],
                    'code' => ['type' => 'string', 'maxLength' => 254, 'description' => 'Voucher code customers type. Empty + generate_code=false = automatic rule (applied without code when conditions match).'],
                    'generate_code' => self::boolParam('Generate a random 8 character code (with optional code_prefix).', false),
                    'code_prefix' => ['type' => 'string', 'maxLength' => 20],
                    'reduction_percent' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
                    'reduction_amount' => ['type' => 'number', 'minimum' => 0],
                    'reduction_tax' => self::boolParam('reduction_amount includes tax.', true),
                    'reduction_currency' => ['type' => ['integer', 'string'], 'description' => 'Currency id or ISO code of reduction_amount (default: shop currency).'],
                    'reduction_product' => ['type' => 'integer', 'minimum' => -2, 'default' => 0, 'description' => '0 = whole order, -1 = cheapest product, -2 = selected products, >0 = this product id.'],
                    'reduction_exclude_special' => self::boolParam('Exclude discounted products from the percent reduction.', false),
                    'free_shipping' => self::boolParam('Free shipping.', false),
                    'date_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD[ HH:MM:SS], default now.'],
                    'date_to' => ['type' => 'string', 'description' => 'YYYY-MM-DD[ HH:MM:SS], default +1 month.'],
                    'quantity' => ['type' => 'integer', 'minimum' => 0, 'default' => 1, 'description' => 'Total uses available.'],
                    'quantity_per_user' => ['type' => 'integer', 'minimum' => 0, 'default' => 1],
                    'minimum_amount' => ['type' => 'number', 'minimum' => 0, 'default' => 0],
                    'minimum_amount_tax' => self::boolParam('Minimum amount includes tax.', false),
                    'minimum_amount_shipping' => self::boolParam('Minimum amount includes shipping.', false),
                    'minimum_amount_currency' => ['type' => ['integer', 'string'], 'description' => 'Currency of minimum_amount (default: shop currency).'],
                    'id_customer' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Only this customer may use it (0 = everybody).'],
                    'priority' => ['type' => 'integer', 'minimum' => 0, 'default' => 1],
                    'partial_use' => self::boolParam('If the voucher is worth more than the cart, keep the remainder as a new voucher.', true),
                    'highlight' => self::boolParam('Show the voucher in the cart summary to customers who can use it.', false),
                    'active' => self::boolParam('Active.', true),
                ],
                'write' => true,
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminCartRules', 'add'],
                'permissions' => [['AdminCartRules', 'edit']],
                'keywords' => ['voucher', 'coupon', 'discount code', 'promo'],
                'handler' => 'ruleCreate',
            ],
            [
                'name' => 'cart_rule_update',
                'title' => 'Update cart rule',
                'description' => 'Changes a limited set of cart rule fields: active, date_from, date_to, quantity, quantity_per_user, code, name (multilang: only the given languages change), highlight, priority. Every change is recorded.',
                'params' => [
                    'id_cart_rule' => self::idParam('Cart rule ID.'),
                    'active' => self::boolParam('Active.'),
                    'date_from' => ['type' => 'string'],
                    'date_to' => ['type' => 'string'],
                    'quantity' => ['type' => 'integer', 'minimum' => 0],
                    'quantity_per_user' => ['type' => 'integer', 'minimum' => 0],
                    'code' => ['type' => 'string', 'maxLength' => 254, 'description' => '"" makes it an automatic rule.'],
                    'name' => $ml,
                    'highlight' => self::boolParam('Highlight in cart.'),
                    'priority' => ['type' => 'integer', 'minimum' => 0],
                ],
                'write' => true,
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminCartRules', 'edit'],
                'keywords' => ['voucher', 'disable coupon', 'extend voucher'],
                'handler' => 'ruleUpdate',
            ],
        ];
    }

    // ----------------------------------------------------------------- carts

    public function cartSearch(array $args, ExecutionContext $ctx): array
    {
        $p = _DB_PREFIX_;
        $idShop = (int) $ctx->idShop;
        $value = '(SELECT SUM(cp.`quantity` * (IFNULL(ps.`price`, 0) + IFNULL(pas.`price`, 0)))
            FROM `' . $p . 'cart_product` cp
            LEFT JOIN `' . $p . 'product_shop` ps ON ps.`id_product` = cp.`id_product` AND ps.`id_shop` = c.`id_shop`
            LEFT JOIN `' . $p . 'product_attribute_shop` pas ON pas.`id_product_attribute` = cp.`id_product_attribute` AND pas.`id_shop` = c.`id_shop`
            WHERE cp.`id_cart` = c.`id_cart`)';
        $hasOrder = 'EXISTS (SELECT 1 FROM `' . $p . 'orders` o WHERE o.`id_cart` = c.`id_cart`)';
        $hasProducts = 'EXISTS (SELECT 1 FROM `' . $p . 'cart_product` cp2 WHERE cp2.`id_cart` = c.`id_cart`)';

        $where = ['c.`id_shop` = ' . $idShop];
        if (!empty($args['id_customer'])) {
            $where[] = 'c.`id_customer` = ' . (int) $args['id_customer'];
        }
        if (!empty($args['customer_email'])) {
            $where[] = 'cu.`email` LIKE \'%' . self::likeValue((string) $args['customer_email']) . '%\'';
        }
        if (!empty($args['with_customer_only'])) {
            $where[] = 'c.`id_customer` > 0';
        }
        if (!empty($args['date_from'])) {
            $where[] = 'c.`date_upd` >= \'' . pSQL(OrderTools::parseDate($args['date_from'], false, 'date_from')) . '\'';
        }
        if (!empty($args['date_to'])) {
            $where[] = 'c.`date_upd` <= \'' . pSQL(OrderTools::parseDate($args['date_to'], true, 'date_to')) . '\'';
        }
        if (!empty($args['abandoned'])) {
            $where[] = 'c.`date_upd` < \'' . pSQL(date('Y-m-d H:i:s', time() - 3600 * (int) $args['abandoned_hours'])) . '\'';
            $where[] = 'NOT ' . $hasOrder;
            $where[] = $hasProducts;
        }
        if (isset($args['ordered'])) {
            $where[] = ($args['ordered'] ? '' : 'NOT ') . $hasOrder;
        }
        if (isset($args['min_value'])) {
            $where[] = $value . ' >= ' . (float) $args['min_value'];
        }
        $w = implode(' AND ', $where);
        $from = '`' . $p . 'cart` c LEFT JOIN `' . $p . 'customer` cu ON cu.`id_customer` = c.`id_customer`';
        $total = (int) $this->db()->getValue('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $w);
        $rows = $this->db()->executeS(
            'SELECT c.`id_cart`, c.`id_customer`, cu.`firstname`, cu.`lastname`, cu.`email`, c.`id_guest`, c.`id_currency`, c.`date_add`, c.`date_upd`,
                (SELECT COUNT(*) FROM `' . $p . 'cart_product` cp3 WHERE cp3.`id_cart` = c.`id_cart`) AS lines_count,
                (SELECT SUM(cp4.`quantity`) FROM `' . $p . 'cart_product` cp4 WHERE cp4.`id_cart` = c.`id_cart`) AS items,
                ' . $value . ' AS est_value,
                (SELECT MIN(o.`id_order`) FROM `' . $p . 'orders` o WHERE o.`id_cart` = c.`id_cart`) AS id_order
             FROM ' . $from . ' WHERE ' . $w . ' ORDER BY c.`date_upd` DESC, c.`id_cart` DESC
             LIMIT ' . (int) $args['offset'] . ', ' . (int) $args['limit']
        ) ?: [];
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id_cart' => (int) $r['id_cart'],
                'customer' => (int) $r['id_customer'] ? ['id_customer' => (int) $r['id_customer'], 'name' => trim($r['firstname'] . ' ' . $r['lastname']), 'email' => $r['email']] : null,
                'id_guest' => (int) $r['id_guest'],
                'date_add' => $r['date_add'],
                'date_upd' => $r['date_upd'],
                'lines' => (int) $r['lines_count'],
                'items' => (int) $r['items'],
                'estimated_value_tax_excl' => round((float) $r['est_value'], 2),
                'id_order' => (int) $r['id_order'] ?: null,
            ];
        }
        $out = $this->paged($items, $total, $args, 'carts');
        $out['value_note'] = 'estimated_value_tax_excl = quantity x catalog base price (+ combination impact), shop default currency, before specific prices and cart rules.';

        return $out;
    }

    public function cartGet(array $args, ExecutionContext $ctx): array
    {
        /** @var \Cart $cart */
        $cart = $this->load('Cart', (int) $args['id_cart'], null, null, 'Use cart_search to find the cart id.');
        if ((int) $cart->id_shop !== (int) $ctx->idShop) {
            if (!$ctx->apiKey->allowsShop((int) $cart->id_shop)) {
                throw new ToolException(sprintf('Cart #%d belongs to shop #%d, which this API key may not access.', (int) $cart->id, (int) $cart->id_shop));
            }
            throw new ToolException(sprintf('Cart #%d belongs to shop #%d.', (int) $cart->id, (int) $cart->id_shop), sprintf('Call again with id_shop=%d (totals depend on the shop context).', (int) $cart->id_shop));
        }
        $p = _DB_PREFIX_;
        $db = $this->db();
        $idLang = (int) $ctx->idLang;
        $cur = (int) $cart->id_currency;

        // Totals depend on Context cart/customer/currency/country: set them from the cart, restore afterwards.
        $c = $this->context();
        $saved = [
            'cart' => isset($c->cart) ? $c->cart : null,
            'customer' => isset($c->customer) ? $c->customer : null,
            'currency' => $c->currency,
            'country' => isset($c->country) ? $c->country : null,
            'language' => $c->language,
        ];
        $products = [];
        $totals = [];
        $cartRules = [];
        $errors = [];
        try {
            $c->cart = $cart;
            $customer = new \Customer((int) $cart->id_customer);
            $c->customer = $customer;
            $currency = new \Currency($cur);
            if (\Validate::isLoadedObject($currency)) {
                $c->currency = $currency;
            }
            $idAddress = (int) $cart->id_address_delivery ?: (int) $cart->id_address_invoice;
            $idCountry = $idAddress ? (int) $db->getValue('SELECT `id_country` FROM `' . $p . 'address` WHERE `id_address` = ' . $idAddress) : 0;
            $country = new \Country($idCountry ?: (int) \Configuration::get('PS_COUNTRY_DEFAULT'));
            if (\Validate::isLoadedObject($country)) {
                $c->country = $country;
            }
            \Product::flushPriceCache();

            foreach ($cart->getProducts(true) as $row) {
                $qty = isset($row['cart_quantity']) ? (int) $row['cart_quantity'] : (int) $row['quantity'];
                $products[] = [
                    'id_product' => (int) $row['id_product'],
                    'id_product_attribute' => (int) $row['id_product_attribute'],
                    'id_customization' => isset($row['id_customization']) ? (int) $row['id_customization'] : 0,
                    'name' => $row['name'],
                    'attributes' => isset($row['attributes']) && $row['attributes'] !== '' ? $row['attributes'] : null,
                    'reference' => $row['reference'],
                    'quantity' => $qty,
                    'unit_price_tax_excl' => round((float) $row['price'], 6),
                    'unit_price_tax_incl' => round((float) $row['price_wt'], 6),
                    'total_tax_excl' => round((float) $row['total'], 6),
                    'total_tax_incl' => round((float) $row['total_wt'], 6),
                    'active' => (bool) $row['active'],
                    'stock_quantity' => isset($row['stock_quantity']) ? (int) $row['stock_quantity'] : null,
                ];
            }
            $types = [
                'products' => \Cart::ONLY_PRODUCTS,
                'discounts' => \Cart::ONLY_DISCOUNTS,
                'shipping' => \Cart::ONLY_SHIPPING,
                'wrapping' => \Cart::ONLY_WRAPPING,
                'total' => \Cart::BOTH,
            ];
            foreach ($types as $k => $type) {
                try {
                    $totals[$k . '_tax_excl'] = round((float) $cart->getOrderTotal(false, $type), 2);
                    $totals[$k . '_tax_incl'] = round((float) $cart->getOrderTotal(true, $type), 2);
                } catch (\Throwable $e) {
                    $errors[] = $k . ': ' . $e->getMessage();
                }
            }
            if (isset($totals['total_tax_incl'])) {
                $totals['total_tax_incl_formatted'] = $this->money($totals['total_tax_incl'], $cur)['formatted'];
            }
            foreach ($cart->getCartRules() as $cr) {
                $cartRules[] = [
                    'id_cart_rule' => (int) $cr['id_cart_rule'],
                    'name' => $cr['name'],
                    'code' => $cr['code'] ?: null,
                    'value_tax_incl' => isset($cr['value_real']) ? round((float) $cr['value_real'], 2) : null,
                    'value_tax_excl' => isset($cr['value_tax_exc']) ? round((float) $cr['value_tax_exc'], 2) : null,
                    'free_shipping' => (bool) $cr['free_shipping'],
                ];
            }
        } finally {
            $c->cart = $saved['cart'];
            $c->customer = $saved['customer'];
            $c->currency = $saved['currency'];
            $c->country = $saved['country'];
            $c->language = $saved['language'];
            \Product::flushPriceCache();
        }
        if ($errors) {
            $ctx->addWarning('Some totals could not be computed: ' . implode('; ', $errors));
        }
        $ordered = (int) $db->getValue('SELECT COUNT(*) FROM `' . $p . 'orders` WHERE `id_cart` = ' . (int) $cart->id);
        if ($ordered) {
            $ctx->addWarning('This cart became an order: totals are recomputed with current prices and rules; use order_get for the amounts actually charged.');
        }

        $customerOut = null;
        if ((int) $cart->id_customer) {
            $cu = $db->getRow('SELECT `id_customer`, `firstname`, `lastname`, `email`, `is_guest`, `id_default_group` FROM `' . $p . 'customer` WHERE `id_customer` = ' . (int) $cart->id_customer);
            if ($cu) {
                $customerOut = [
                    'id_customer' => (int) $cu['id_customer'],
                    'name' => trim($cu['firstname'] . ' ' . $cu['lastname']),
                    'email' => $cu['email'],
                    'is_guest' => (bool) $cu['is_guest'],
                    'id_default_group' => (int) $cu['id_default_group'],
                ];
            }
        }
        $carrier = null;
        if ((int) $cart->id_carrier) {
            $name = $db->getValue('SELECT `name` FROM `' . $p . 'carrier` WHERE `id_carrier` = ' . (int) $cart->id_carrier);
            $carrier = ['id_carrier' => (int) $cart->id_carrier, 'name' => $name === '0' ? \Configuration::get('PS_SHOP_NAME') : $name];
        }
        $deliveryOption = null;
        if ($cart->delivery_option) {
            $decoded = json_decode((string) $cart->delivery_option, true);
            $deliveryOption = is_array($decoded) ? $decoded : (string) $cart->delivery_option;
        }
        $orders = array_map('intval', array_column($db->executeS('SELECT `id_order` FROM `' . $p . 'orders` WHERE `id_cart` = ' . (int) $cart->id) ?: [], 'id_order'));

        return [
            'id_cart' => (int) $cart->id,
            'id_shop' => (int) $cart->id_shop,
            'date_add' => $cart->date_add,
            'date_upd' => $cart->date_upd,
            'currency' => (new \Currency($cur))->iso_code,
            'id_lang' => (int) $cart->id_lang,
            'customer' => $customerOut,
            'id_guest' => (int) $cart->id_guest,
            'id_orders' => $orders,
            'ordered' => (bool) $orders,
            'products' => $products,
            'totals' => $totals,
            'cart_rules' => $cartRules,
            'carrier' => $carrier,
            'delivery_option' => $deliveryOption,
            'address_delivery' => CustomerTools::addressRow((int) $cart->id_address_delivery, $idLang),
            'address_invoice' => (int) $cart->id_address_invoice === (int) $cart->id_address_delivery ? 'same as delivery' : CustomerTools::addressRow((int) $cart->id_address_invoice, $idLang),
            'gift' => (bool) $cart->gift,
            'gift_message' => $cart->gift ? $cart->gift_message : null,
        ];
    }

    // ------------------------------------------------------------ cart rules

    public function ruleList(array $args, ExecutionContext $ctx): array
    {
        $p = _DB_PREFIX_;
        $where = [$this->shopCondition((int) $ctx->idShop)];
        if (!empty($args['query'])) {
            $q = self::likeValue((string) $args['query']);
            $where[] = '(cr.`code` LIKE \'%' . $q . '%\' OR crl.`name` LIKE \'%' . $q . '%\')';
        }
        if (!empty($args['code'])) {
            $where[] = 'cr.`code` = \'' . pSQL(trim($args['code'])) . '\'';
        }
        if (isset($args['active'])) {
            $where[] = 'cr.`active` = ' . ($args['active'] ? 1 : 0);
        }
        if (!empty($args['valid_now'])) {
            $now = pSQL(date('Y-m-d H:i:s'));
            $where[] = 'cr.`date_from` <= \'' . $now . '\' AND cr.`date_to` >= \'' . $now . '\' AND cr.`quantity` > 0';
        }
        if (!empty($args['valid_on'])) {
            $d = pSQL(OrderTools::parseDate($args['valid_on'], false, 'valid_on'));
            $where[] = 'cr.`date_from` <= \'' . $d . '\' AND cr.`date_to` >= \'' . $d . '\'';
        }
        if (isset($args['id_customer'])) {
            $where[] = 'cr.`id_customer` = ' . (int) $args['id_customer'];
        }
        if (isset($args['with_code'])) {
            $where[] = $args['with_code'] ? 'cr.`code` <> \'\'' : 'cr.`code` = \'\'';
        }
        $w = implode(' AND ', $where);
        $from = '`' . $p . 'cart_rule` cr LEFT JOIN `' . $p . 'cart_rule_lang` crl ON crl.`id_cart_rule` = cr.`id_cart_rule` AND crl.`id_lang` = ' . (int) $ctx->idLang;
        $total = (int) $this->db()->getValue('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $w);
        $rows = $this->db()->executeS(
            'SELECT cr.*, crl.`name`,
                (SELECT COUNT(*) FROM `' . $p . 'order_cart_rule` ocr WHERE ocr.`id_cart_rule` = cr.`id_cart_rule`) AS times_used
             FROM ' . $from . ' WHERE ' . $w . ' ORDER BY cr.`id_cart_rule` DESC LIMIT ' . (int) $args['offset'] . ', ' . (int) $args['limit']
        ) ?: [];
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id_cart_rule' => (int) $r['id_cart_rule'],
                'name' => $r['name'],
                'code' => $r['code'] !== '' ? $r['code'] : null,
                'active' => (bool) $r['active'],
                'date_from' => $r['date_from'],
                'date_to' => $r['date_to'],
                'quantity_left' => (int) $r['quantity'],
                'quantity_per_user' => (int) $r['quantity_per_user'],
                'id_customer' => (int) $r['id_customer'] ?: null,
                'priority' => (int) $r['priority'],
                'highlight' => (bool) $r['highlight'],
                'minimum_amount' => (float) $r['minimum_amount'] > 0 ? (float) $r['minimum_amount'] : null,
                'action' => $this->actionSummary($r),
                'restrictions' => $this->restrictionFlags($r),
                'times_used' => (int) $r['times_used'],
            ];
        }

        return $this->paged($items, $total, $args, 'cart_rules');
    }

    public function ruleGet(array $args, ExecutionContext $ctx): array
    {
        $p = _DB_PREFIX_;
        $db = $this->db();
        $idLang = (int) $ctx->idLang;
        if (!empty($args['id_cart_rule'])) {
            $id = (int) $args['id_cart_rule'];
        } elseif (!empty($args['code'])) {
            $id = (int) \CartRule::getIdByCode(trim($args['code']));
            if (!$id) {
                throw new ToolException(sprintf('No cart rule with code "%s".', $args['code']), 'Use cart_rule_list with query.');
            }
        } else {
            throw new ToolException('Pass id_cart_rule or code.');
        }
        $r = $db->getRow('SELECT * FROM `' . $p . 'cart_rule` WHERE `id_cart_rule` = ' . $id);
        if (!$r) {
            throw new ToolException(sprintf('Cart rule #%d does not exist.', $id), 'Use cart_rule_list.');
        }
        $this->assertCartRuleAllowed($ctx, $id);
        $names = [];
        foreach ($db->executeS('SELECT `id_lang`, `name` FROM `' . $p . 'cart_rule_lang` WHERE `id_cart_rule` = ' . $id) ?: [] as $n) {
            $names[$this->isoForLang((int) $n['id_lang'])] = $n['name'];
        }
        $curIso = function ($idCurrency) use ($db, $p) {
            return (int) $idCurrency ? $db->getValue('SELECT `iso_code` FROM `' . $p . 'currency` WHERE `id_currency` = ' . (int) $idCurrency) : null;
        };

        $restrictions = [];
        if ((int) $r['id_customer']) {
            $cu = $db->getRow('SELECT `firstname`, `lastname`, `email` FROM `' . $p . 'customer` WHERE `id_customer` = ' . (int) $r['id_customer']);
            $restrictions['customer'] = ['id_customer' => (int) $r['id_customer'], 'name' => $cu ? trim($cu['firstname'] . ' ' . $cu['lastname']) : null, 'email' => $cu ? $cu['email'] : null];
        }
        if ($r['country_restriction']) {
            $restrictions['countries'] = $db->executeS(
                'SELECT c.`id_country`, cl.`name` FROM `' . $p . 'cart_rule_country` c
                 LEFT JOIN `' . $p . 'country_lang` cl ON cl.`id_country` = c.`id_country` AND cl.`id_lang` = ' . $idLang . '
                 WHERE c.`id_cart_rule` = ' . $id
            ) ?: [];
        }
        if ($r['carrier_restriction']) {
            $restrictions['carriers'] = $db->executeS(
                'SELECT crc.`id_carrier` AS id_reference, (SELECT ca.`name` FROM `' . $p . 'carrier` ca WHERE ca.`id_reference` = crc.`id_carrier` ORDER BY ca.`deleted` ASC, ca.`id_carrier` DESC LIMIT 1) AS name
                 FROM `' . $p . 'cart_rule_carrier` crc WHERE crc.`id_cart_rule` = ' . $id
            ) ?: [];
        }
        if ($r['group_restriction']) {
            $restrictions['groups'] = $db->executeS(
                'SELECT g.`id_group`, gl.`name` FROM `' . $p . 'cart_rule_group` g
                 LEFT JOIN `' . $p . 'group_lang` gl ON gl.`id_group` = g.`id_group` AND gl.`id_lang` = ' . $idLang . '
                 WHERE g.`id_cart_rule` = ' . $id
            ) ?: [];
        }
        if ($r['shop_restriction']) {
            $restrictions['shops'] = array_map('intval', array_column($db->executeS('SELECT `id_shop` FROM `' . $p . 'cart_rule_shop` WHERE `id_cart_rule` = ' . $id) ?: [], 'id_shop'));
        }
        if ($r['cart_rule_restriction']) {
            $restrictions['combinable_only_with_cart_rules'] = array_map('intval', array_column($db->executeS(
                'SELECT IF(`id_cart_rule_1` = ' . $id . ', `id_cart_rule_2`, `id_cart_rule_1`) AS other FROM `' . $p . 'cart_rule_combination`
                 WHERE `id_cart_rule_1` = ' . $id . ' OR `id_cart_rule_2` = ' . $id . ' LIMIT 200'
            ) ?: [], 'other'));
        }
        if ($r['product_restriction']) {
            $groups = [];
            foreach ($db->executeS('SELECT `id_product_rule_group`, `quantity` FROM `' . $p . 'cart_rule_product_rule_group` WHERE `id_cart_rule` = ' . $id) ?: [] as $g) {
                $rules = [];
                foreach ($db->executeS('SELECT `id_product_rule`, `type` FROM `' . $p . 'cart_rule_product_rule` WHERE `id_product_rule_group` = ' . (int) $g['id_product_rule_group']) ?: [] as $pr) {
                    $vals = $db->executeS('SELECT `id_item` FROM `' . $p . 'cart_rule_product_rule_value` WHERE `id_product_rule` = ' . (int) $pr['id_product_rule'] . ' LIMIT 101') ?: [];
                    $ids = array_map('intval', array_column($vals, 'id_item'));
                    $rule = ['type' => $pr['type'], 'ids' => array_slice($ids, 0, 100)];
                    if (count($ids) > 100) {
                        $rule['truncated'] = true;
                    }
                    $rules[] = $rule;
                }
                $groups[] = ['min_quantity_in_cart' => (int) $g['quantity'], 'rules_all_required' => $rules];
            }
            $restrictions['product_rule_groups'] = $groups;
        }

        $out = [
            'id_cart_rule' => $id,
            'name' => isset($names[$this->isoForLang($idLang)]) ? $names[$this->isoForLang($idLang)] : reset($names),
            'names' => $names,
            'description' => $r['description'],
            'code' => $r['code'] !== '' ? $r['code'] : null,
            'automatic' => $r['code'] === '',
            'active' => (bool) $r['active'],
            'highlight' => (bool) $r['highlight'],
            'partial_use' => (bool) $r['partial_use'],
            'priority' => (int) $r['priority'],
            'date_from' => $r['date_from'],
            'date_to' => $r['date_to'],
            'quantity_left' => (int) $r['quantity'],
            'quantity_per_user' => (int) $r['quantity_per_user'],
            'minimum_amount' => [
                'amount' => (float) $r['minimum_amount'],
                'currency' => $curIso($r['minimum_amount_currency']),
                'tax_included' => (bool) $r['minimum_amount_tax'],
                'shipping_included' => (bool) $r['minimum_amount_shipping'],
            ],
            'restrictions' => $restrictions ?: 'none',
            'actions' => [
                'summary' => $this->actionSummary($r),
                'free_shipping' => (bool) $r['free_shipping'],
                'reduction_percent' => (float) $r['reduction_percent'],
                'reduction_amount' => (float) $r['reduction_amount'],
                'reduction_currency' => $curIso($r['reduction_currency']),
                'reduction_tax_included' => (bool) $r['reduction_tax'],
                'reduction_target' => $this->targetLabel((int) $r['reduction_product']),
                'reduction_exclude_special' => (bool) $r['reduction_exclude_special'],
                'gift_product' => (int) $r['gift_product'] ? ['id_product' => (int) $r['gift_product'], 'id_product_attribute' => (int) $r['gift_product_attribute'], 'name' => \Product::getProductName((int) $r['gift_product'], (int) $r['gift_product_attribute'] ?: null, $idLang)] : null,
            ],
            'times_used' => (int) $db->getValue('SELECT COUNT(*) FROM `' . $p . 'order_cart_rule` WHERE `id_cart_rule` = ' . $id),
            'in_open_carts' => (int) $db->getValue('SELECT COUNT(*) FROM `' . $p . 'cart_cart_rule` ccr WHERE ccr.`id_cart_rule` = ' . $id . ' AND NOT EXISTS (SELECT 1 FROM `' . $p . 'orders` o WHERE o.`id_cart` = ccr.`id_cart`)'),
            'date_add' => $r['date_add'],
            'date_upd' => $r['date_upd'],
        ];
        // PS 9.1+ fields
        if (array_key_exists('minimum_product_quantity', $r)) {
            $out['minimum_product_quantity'] = (int) $r['minimum_product_quantity'];
        }
        if (array_key_exists('id_cart_rule_type', $r)) {
            $out['id_cart_rule_type'] = (int) $r['id_cart_rule_type'] ?: null;
        }

        return $out;
    }

    public function ruleCreate(array $args, ExecutionContext $ctx): array
    {
        $idShop = (int) $ctx->idShop;
        $shop = new \Shop($idShop);
        $defaultCurrency = (int) \Configuration::get('PS_CURRENCY_DEFAULT', null, (int) $shop->id_shop_group, $idShop);

        $percent = isset($args['reduction_percent']) ? (float) $args['reduction_percent'] : 0.0;
        $amount = isset($args['reduction_amount']) ? (float) $args['reduction_amount'] : 0.0;
        if ($percent > 0 && $amount > 0) {
            throw new ToolException('Give reduction_percent OR reduction_amount, not both.');
        }
        $freeShipping = !empty($args['free_shipping']);
        if ($percent <= 0 && $amount <= 0 && !$freeShipping) {
            throw new ToolException('The cart rule has no action.', 'Set reduction_percent, reduction_amount or free_shipping=true.');
        }
        $target = (int) $args['reduction_product'];
        if ($target === -2) {
            throw new ToolException('reduction_product=-2 (selected products) needs product restrictions, which this tool does not create.', 'Use 0 (order), -1 (cheapest) or a product id, or create the rule in the back office.');
        }
        if ($target > 0 && !\Validate::isLoadedObject(new \Product($target))) {
            throw new ToolException(sprintf('Product #%d does not exist (reduction_product).', $target));
        }
        if ($amount > 0 && $target === -1) {
            throw new ToolException('An amount reduction cannot target the cheapest product (PrestaShop only allows that for percent reductions).');
        }

        $code = isset($args['code']) ? trim((string) $args['code']) : '';
        if (!empty($args['generate_code'])) {
            if ($code !== '') {
                throw new ToolException('Pass code OR generate_code, not both.');
            }
            $prefix = isset($args['code_prefix']) ? strtoupper(trim((string) $args['code_prefix'])) : '';
            for ($i = 0; $i < 20 && ($code === '' || \CartRule::getIdByCode($code)); ++$i) {
                $code = $prefix . strtoupper(\Tools::passwdGen(8, 'ALPHANUMERIC'));
            }
        } elseif ($code !== '' && \CartRule::getIdByCode($code)) {
            throw new ToolException(sprintf('The code "%s" is already used by cart rule #%d.', $code, (int) \CartRule::getIdByCode($code)), 'Choose another code or use generate_code=true.');
        }

        $from = !empty($args['date_from']) ? OrderTools::parseDate($args['date_from'], false, 'date_from') : date('Y-m-d H:i:s');
        $to = !empty($args['date_to']) ? OrderTools::parseDate($args['date_to'], true, 'date_to') : date('Y-m-d H:i:s', strtotime('+1 month'));
        if ($from > $to) {
            throw new ToolException('date_from is after date_to.');
        }
        $idCustomer = (int) $args['id_customer'];
        if ($idCustomer && !\Validate::isLoadedObject(new \Customer($idCustomer))) {
            throw new ToolException(sprintf('Customer #%d does not exist.', $idCustomer), 'Use customer_search.');
        }

        // Names: given languages, the others get the first given value (back office requires the default language).
        $given = $this->multilangInput($args['name'], $ctx, 'name');
        $first = reset($given);
        $names = [];
        foreach (\Language::getLanguages(false) as $l) {
            $idL = (int) $l['id_lang'];
            $names[$idL] = isset($given[$idL]) && $given[$idL] !== '' ? $given[$idL] : $first;
        }

        $cr = new \CartRule();
        $cr->name = $names;
        $cr->description = isset($args['description']) ? (string) $args['description'] : '';
        $cr->code = $code;
        $cr->date_from = $from;
        $cr->date_to = $to;
        $cr->quantity = (int) $args['quantity'];
        $cr->quantity_per_user = (int) $args['quantity_per_user'];
        $cr->priority = (int) $args['priority'];
        $cr->partial_use = !empty($args['partial_use']);
        $cr->id_customer = $idCustomer;
        $cr->minimum_amount = (float) $args['minimum_amount'];
        $cr->minimum_amount_tax = !empty($args['minimum_amount_tax']);
        $cr->minimum_amount_shipping = !empty($args['minimum_amount_shipping']);
        $cr->minimum_amount_currency = isset($args['minimum_amount_currency']) ? $this->currencyId($args['minimum_amount_currency']) : $defaultCurrency;
        $cr->free_shipping = $freeShipping;
        $cr->reduction_percent = $percent;
        $cr->reduction_amount = $amount;
        $cr->reduction_tax = !empty($args['reduction_tax']);
        $cr->reduction_currency = isset($args['reduction_currency']) ? $this->currencyId($args['reduction_currency']) : $defaultCurrency;
        $cr->reduction_product = $target;
        $cr->reduction_exclude_special = !empty($args['reduction_exclude_special']);
        $cr->highlight = !empty($args['highlight']);
        $cr->active = !empty($args['active']);
        $restrictShop = \Shop::isFeatureActive();
        $cr->shop_restriction = $restrictShop;

        $err = $cr->validateFields(false, true);
        if ($err !== true) {
            throw new ToolException('Invalid cart rule: ' . $err);
        }
        $err = $cr->validateFieldsLang(false, true);
        if ($err !== true) {
            throw new ToolException('Invalid cart rule name: ' . $err);
        }

        $planned = [
            'name' => $names, 'code' => $code, 'date_from' => $from, 'date_to' => $to, 'quantity' => (int) $cr->quantity,
            'quantity_per_user' => (int) $cr->quantity_per_user, 'priority' => (int) $cr->priority, 'partial_use' => (int) $cr->partial_use,
            'id_customer' => $idCustomer, 'minimum_amount' => (float) $cr->minimum_amount, 'free_shipping' => (int) $freeShipping,
            'reduction_percent' => $percent, 'reduction_amount' => $amount, 'reduction_tax' => (int) $cr->reduction_tax,
            'reduction_currency' => (int) $cr->reduction_currency, 'reduction_product' => $target, 'highlight' => (int) $cr->highlight,
            'active' => (int) $cr->active, 'shop_restriction' => $restrictShop ? [$idShop] : 0,
        ];
        if ($amount <= 0) {
            unset($planned['reduction_tax'], $planned['reduction_currency']);
        }
        foreach ($planned as $f => $v) {
            if ($v === '' || $v === 0 || $v === 0.0) {
                continue;
            }
            $ctx->recordChange('CartRule', 'new', $f, null, $v, $idShop);
        }
        $result = ['code' => $code !== '' ? $code : null, 'automatic' => $code === '', 'action' => $this->actionSummary([
            'free_shipping' => $freeShipping, 'reduction_percent' => $percent, 'reduction_amount' => $amount, 'reduction_currency' => $cr->reduction_currency,
            'reduction_tax' => $cr->reduction_tax, 'reduction_product' => $target, 'gift_product' => 0,
        ])];
        if ($ctx->dryRun) {
            return $result;
        }
        if (!$cr->add()) {
            throw new ToolException('PrestaShop could not save the cart rule.');
        }
        if ($restrictShop) {
            $this->dbWrite()->insert('cart_rule_shop', ['id_cart_rule' => (int) $cr->id, 'id_shop' => $idShop]);
        }
        $result['id_cart_rule'] = (int) $cr->id;
        $ctx->resolveCreatedId('CartRule', (int) $cr->id);

        return $result;
    }

    public function ruleUpdate(array $args, ExecutionContext $ctx): array
    {
        /** @var \CartRule $cr */
        $cr = $this->load('CartRule', (int) $args['id_cart_rule'], null, null, 'Use cart_rule_list.');
        $id = (int) $cr->id;
        $this->assertCartRuleAllowed($ctx, $id);
        $idShop = (int) $ctx->idShop;
        $changed = false;

        $set = function ($field, $new) use ($cr, $ctx, $id, $idShop, &$changed) {
            $old = $cr->$field;
            if (is_bool($new)) {
                $old = (bool) $old;
            } elseif (is_int($new)) {
                $old = (int) $old;
            } else {
                $old = (string) $old;
            }
            if ($old !== $new) {
                $ctx->recordChange('CartRule', $id, $field, is_bool($old) ? (int) $old : $old, is_bool($new) ? (int) $new : $new, $idShop);
                $cr->$field = $new;
                $changed = true;
            }
        };
        foreach (['active', 'highlight'] as $f) {
            if (isset($args[$f])) {
                $set($f, (bool) $args[$f]);
            }
        }
        foreach (['quantity', 'quantity_per_user', 'priority'] as $f) {
            if (isset($args[$f])) {
                $set($f, (int) $args[$f]);
            }
        }
        if (!empty($args['date_from'])) {
            $set('date_from', OrderTools::parseDate($args['date_from'], false, 'date_from'));
        }
        if (!empty($args['date_to'])) {
            $set('date_to', OrderTools::parseDate($args['date_to'], true, 'date_to'));
        }
        if ($cr->date_from > $cr->date_to) {
            throw new ToolException('date_from would be after date_to.');
        }
        if (isset($args['code'])) {
            $code = trim((string) $args['code']);
            $other = $code !== '' ? (int) \CartRule::getIdByCode($code) : 0;
            if ($other && $other !== $id) {
                throw new ToolException(sprintf('The code "%s" is already used by cart rule #%d.', $code, $other));
            }
            $set('code', $code);
        }
        if (isset($args['name'])) {
            $names = is_array($cr->name) ? $cr->name : [];
            foreach ($this->multilangInput($args['name'], $ctx, 'name') as $idL => $v) {
                $old = isset($names[$idL]) ? (string) $names[$idL] : '';
                if ($v === '') {
                    throw new ToolException('name cannot be empty.');
                }
                if ($old !== $v) {
                    $ctx->recordChange('CartRule', $id, 'name', $old, $v, $idShop, $idL);
                    $names[$idL] = $v;
                    $changed = true;
                }
            }
            $cr->name = $names;
        }
        if ((int) $cr->quantity === 0 && $cr->active) {
            $ctx->addWarning('quantity is 0: the voucher cannot be used any more.');
        }

        $err = $cr->validateFields(false, true);
        if ($err !== true) {
            throw new ToolException('Invalid cart rule: ' . $err);
        }
        $err = $cr->validateFieldsLang(false, true);
        if ($err !== true) {
            throw new ToolException('Invalid cart rule name: ' . $err);
        }
        $result = ['id_cart_rule' => $id, 'code' => $cr->code !== '' ? $cr->code : null, 'active' => (bool) $cr->active, 'date_from' => $cr->date_from, 'date_to' => $cr->date_to, 'quantity' => (int) $cr->quantity];
        if ($ctx->dryRun || !$changed) {
            return $result;
        }
        if (!$cr->update()) {
            throw new ToolException('PrestaShop could not save the cart rule.');
        }

        return $result;
    }

    // --------------------------------------------------------------- helpers

    /**
     * Cart rules usable in the shop: no shop restriction, or the shop listed.
     */
    private function shopCondition(int $idShop): string
    {
        return '(cr.`shop_restriction` = 0 OR EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'cart_rule_shop` crs WHERE crs.`id_cart_rule` = cr.`id_cart_rule` AND crs.`id_shop` = ' . $idShop . '))';
    }

    private function actionSummary(array $r): string
    {
        $parts = [];
        if ((float) $r['reduction_percent'] > 0) {
            $parts[] = rtrim(rtrim(number_format((float) $r['reduction_percent'], 2, '.', ''), '0'), '.') . '% off ' . $this->targetLabel((int) $r['reduction_product']);
        }
        if ((float) $r['reduction_amount'] > 0) {
            $iso = (int) $r['reduction_currency'] ? (string) $this->db()->getValue('SELECT `iso_code` FROM `' . _DB_PREFIX_ . 'currency` WHERE `id_currency` = ' . (int) $r['reduction_currency']) : '';
            $parts[] = number_format((float) $r['reduction_amount'], 2, '.', '') . ' ' . $iso . ' ' . ($r['reduction_tax'] ? 'tax incl' : 'tax excl') . ' off ' . $this->targetLabel((int) $r['reduction_product']);
        }
        if (!empty($r['free_shipping'])) {
            $parts[] = 'free shipping';
        }
        if (!empty($r['gift_product'])) {
            $parts[] = 'gift product #' . (int) $r['gift_product'];
        }

        return $parts ? implode(' + ', $parts) : 'no action';
    }

    private function targetLabel(int $reductionProduct): string
    {
        if ($reductionProduct === 0) {
            return 'order (without shipping)';
        }
        if ($reductionProduct === -1) {
            return 'cheapest product';
        }
        if ($reductionProduct === -2) {
            return 'selected products';
        }

        return 'product #' . $reductionProduct;
    }

    private function restrictionFlags(array $r): array
    {
        $out = [];
        foreach (['country', 'carrier', 'group', 'cart_rule', 'product', 'shop'] as $k) {
            if (!empty($r[$k . '_restriction'])) {
                $out[] = $k;
            }
        }
        if ((int) $r['id_customer']) {
            $out[] = 'customer';
        }
        if ((float) $r['minimum_amount'] > 0) {
            $out[] = 'minimum_amount';
        }

        return $out;
    }

    /**
     * @param int|string $value currency id or ISO code
     *
     * @throws ToolException
     */
    private function currencyId($value): int
    {
        if (is_int($value) || ctype_digit((string) $value)) {
            $id = (int) $value;
        } else {
            $id = (int) \Currency::getIdByIsoCode(strtoupper(trim((string) $value)));
        }
        if (!$id || !\Validate::isLoadedObject(new \Currency($id))) {
            throw new ToolException(sprintf('Unknown currency "%s".', $value), 'Call shop_context_get for the shop currencies.');
        }

        return $id;
    }
}
