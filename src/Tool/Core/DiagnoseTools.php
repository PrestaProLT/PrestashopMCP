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
 * Storefront diagnostics: why a shopper cannot add a product to the cart,
 * why a product is not visible, why a cart cannot check out.
 *
 * Everything is read-only: conditions of core (CartController, Cart::updateQty,
 * Carrier::getAvailableCarrierList, Hook::getHookModuleExecList('paymentOptions')…)
 * are reproduced with SQL and side-effect-free static calls. The Context is
 * switched to the shopper (employee removed, so Hook works in front-office
 * mode) only around such calls and always restored.
 */
class DiagnoseTools extends AbstractToolGroup
{
    const PERMISSIONS = [['AdminOrders', 'view'], ['AdminProducts', 'view'], ['AdminCustomers', 'view']];

    /** Hooks where modules commonly alter or block cart / checkout behaviour. */
    const CART_HOOKS = [
        'actionCartUpdateQuantityBefore', 'actionCartSave', 'actionBeforeCartUpdateQty',
        'overrideMinimalPurchasePrice', 'actionGetIDZoneByAddressID', 'actionCheckoutRender',
        'actionValidateStepComplete', 'actionPresentCart',
    ];

    /** @var array */
    private $report = [];
    /** @var string|null label of the cart line being checked (diagnose_checkout) */
    private $item;
    /** @var array */
    private $columnCache = [];
    /** @var array|null carrier ids core offered (diagnose_checkout) */
    private $coreCarrierIds;

    public function owner(): string
    {
        return 'diagnose';
    }

    public function tools(): array
    {
        $customerParams = [
            'id_customer' => self::idParam('Customer to evaluate as (customer_search finds it). Omit both id_customer and email to evaluate as a not-logged-in visitor.', false),
            'email' => ['type' => 'string', 'description' => 'Customer e-mail, alternative to id_customer.'],
        ];

        return [
            [
                'name' => 'diagnose_cart_add',
                'title' => 'Why can\'t a customer add a product to the cart',
                'description' => 'Answers "customer X cannot add product Y (combination Z, quantity N) to the cart / cannot order it". Replays every storefront condition of the PrestaShop version in order (maintenance, catalog mode, group show_prices, geolocation, customer account, product active/shop/group access, combination, available_for_order, stock and out-of-stock rule, packs, minimal quantity, required customization, then checkout: currency, country/zone, carriers that can deliver it, payment modules allowed, minimum purchase, cart rules). Read-only: nothing is saved. Returns verdict can_add / blocked / can_add_but_cannot_checkout, blocking[] and warnings[] with the message the shopper sees and the fix (with the ppmcp tool to use), passed[] and the context used. Use diagnose_checkout for an existing cart, diagnose_product_visibility when the product is not shown.',
                'params' => array_merge([
                    'id_product' => self::idParam('Product ID (catalog_product_search finds it).'),
                    'id_product_attribute' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Combination ID. Omit to use the combination core picks when none is chosen (default combination with stock).'],
                    'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1, 'description' => 'Quantity the shopper tries to add.'],
                ], $customerParams, [
                    'id_cart' => self::idParam('Existing cart to add to: quantities already in it count (stock, minimal quantity, minimum purchase). Never modified.', false),
                    'id_country' => self::idParam('Delivery country. Default: country of the cart/customer address, else the shop default country.', false),
                ]),
                'shop' => true,
                'lang' => true,
                'permissions' => self::PERMISSIONS,
                'keywords' => ['add to cart', 'cannot buy', 'cannot order', 'out of stock', 'basket', 'why', 'troubleshoot', 'customer complaint'],
                'handler' => 'cartAdd',
            ],
            [
                'name' => 'diagnose_product_visibility',
                'title' => 'Why isn\'t a product visible',
                'description' => 'Answers "why is product Y not shown on category pages / in search / why does its page give 404 or redirect". Checks active, shop association, visibility (both/catalog/search/none), redirect_type of disabled products (legacy and 8+ values), categories and their active state up the tree, customer group access to its categories, search index (product.indexed, PS_SEARCH_INDEXATION, words indexed), faceted search index when ps_facetedsearch is installed, available_date, "new" period. Optionally evaluates group access for a customer or group. Read-only.',
                'params' => array_merge([
                    'id_product' => self::idParam('Product ID (catalog_product_search finds it).'),
                ], $customerParams, [
                    'id_group' => self::idParam('Evaluate category access for this customer group instead of a customer.', false),
                ]),
                'shop' => true,
                'lang' => true,
                'permissions' => self::PERMISSIONS,
                'keywords' => ['not visible', 'hidden product', '404', 'not found', 'search', 'category page', 'listing', 'redirect'],
                'handler' => 'productVisibility',
            ],
            [
                'name' => 'diagnose_checkout',
                'title' => 'Why can\'t this cart check out',
                'description' => 'Answers "why can\'t this cart be ordered". For id_cart (or a customer → their latest cart without order) checks every line is still orderable (active, group access, combination, stock, minimal quantity), delivery/invoice addresses (country active, zone, zip/DNI), carriers available with the reason every other carrier is excluded (zone, group, ranges, product carrier restriction, size/weight, module), payment modules available with exclusion reasons (module_country/currency/group/carrier, shop, hook exceptions), minimum purchase, cart rule validity, and lists PrestaShop log entries of the cart. Read-only.',
                'params' => array_merge([
                    'id_cart' => self::idParam('Cart ID (cart_search finds it). Or give a customer to use their latest cart without order.', false),
                ], $customerParams, [
                    'core_delivery_check' => self::boolParam('Also ask core (Cart::getDeliveryOptionList) which delivery options it offers. This runs carrier modules\' price calculation (may be slow or call carrier APIs).', true),
                ]),
                'shop' => true,
                'lang' => true,
                'permissions' => self::PERMISSIONS,
                'keywords' => ['checkout', 'cannot order', 'no carrier', 'no payment method', 'abandoned cart', 'order failed', 'shipping'],
                'handler' => 'checkout',
            ],
        ];
    }

    // ------------------------------------------------------------------
    // diagnose_cart_add
    // ------------------------------------------------------------------

    public function cartAdd(array $args, ExecutionContext $ctx): array
    {
        $this->resetReport();
        $idProduct = (int) $args['id_product'];
        $ipa = isset($args['id_product_attribute']) ? (int) $args['id_product_attribute'] : null;
        $qty = isset($args['quantity']) ? max(1, (int) $args['quantity']) : 1;

        $customer = $this->resolveCustomer($args, (int) $ctx->idShop);
        if ($customer) {
            $this->assertShopAllowed($ctx, (int) $customer->id_shop, sprintf('Customer #%d', (int) $customer->id));
        }
        $cart = null;
        if (!empty($args['id_cart'])) {
            $cart = $this->loadCart((int) $args['id_cart'], $ctx);
            if (!$customer && (int) $cart->id_customer) {
                $customer = new \Customer((int) $cart->id_customer);
            } elseif ($customer && (int) $cart->id_customer && (int) $cart->id_customer !== (int) $customer->id) {
                $this->warn('cart_owner', sprintf('Cart #%d belongs to customer #%d, not #%d. Evaluating with customer #%d.', (int) $cart->id, (int) $cart->id_customer, (int) $customer->id, (int) $customer->id));
            }
            $orderId = $this->orderIdForCart((int) $cart->id);
            if ($orderId) {
                $this->fail('cart_already_ordered', 'cart_add', sprintf('Order #%d was already placed with cart #%d; the shop starts a new cart for the shopper.', $orderId, (int) $cart->id), null, 'Evaluate without id_cart.');
            }
        }
        $sh = $this->buildShopper($ctx, $customer, $cart, isset($args['id_country']) ? (int) $args['id_country'] : 0);

        $this->shopChecks($sh, 'cart_add');
        $this->customerChecks($sh);

        $data = $this->withShopper($sh, function () use ($sh, $idProduct, $ipa, $qty) {
            $existing = $sh['cart'] ? $this->cartLines($sh['cart']) : [];
            $inCart = 0;
            $resolvedIpa = $ipa;
            if ($resolvedIpa === null || $resolvedIpa === 0) {
                $resolvedIpa = $this->resolveDefaultCombination($idProduct, (int) $sh['id_shop']);
            }
            foreach ($existing as $l) {
                if ((int) $l['id_product'] === $idProduct && (int) $l['id_product_attribute'] === (int) $resolvedIpa && !(int) $l['id_customization']) {
                    $inCart = (int) $l['cart_quantity'];
                }
            }
            $line = $this->productChecks($sh, $idProduct, $ipa, $qty, $inCart, 'cart_add', 0);

            return ['line' => $line, 'existing' => $existing];
        });
        $line = $data['line'];

        $context = $this->contextBlock($sh);
        if ($line) {
            $context['product'] = [
                'id_product' => $idProduct,
                'name' => $line['name'],
                'id_product_attribute' => $line['id_product_attribute'],
                'combination' => $line['combination'],
                'quantity_requested' => $qty,
                'quantity_already_in_cart' => $line['in_cart'],
                'stock_available' => $line['stock'],
                'out_of_stock_rule' => $line['oos_rule'],
                'minimal_quantity' => $line['minimal_quantity'],
                'unit_price_tax_excl' => $line['unit_price_tax_excl'],
                'visibility' => $line['visibility'],
            ];
            // Checkout-level checks with the cart as it would be after the add.
            $lines = [];
            foreach ($data['existing'] as $l) {
                if ((int) $l['id_product'] === $idProduct && (int) $l['id_product_attribute'] === (int) $line['id_product_attribute'] && !(int) $l['id_customization']) {
                    continue;
                }
                $lines[] = $this->lineFromCartRow($l);
            }
            $lines[] = $line;
            $res = $this->checkoutChecks($sh, $lines, null, 'checkout');
            $this->addCorePaymentCheck($context, $sh, $res, 0);
        } else {
            $this->skip('checkout_checks', 'product cannot be added');
        }

        $verdict = 'can_add';
        foreach ($this->report['blocking'] as $b) {
            if ($b['blocks'] === 'cart_add') {
                $verdict = 'blocked';
                break;
            }
            $verdict = 'can_add_but_cannot_checkout';
        }

        return $this->output($verdict, $context);
    }

    // ------------------------------------------------------------------
    // diagnose_checkout
    // ------------------------------------------------------------------

    public function checkout(array $args, ExecutionContext $ctx): array
    {
        $this->resetReport();
        $customer = $this->resolveCustomer($args, (int) $ctx->idShop);
        if ($customer) {
            $this->assertShopAllowed($ctx, (int) $customer->id_shop, sprintf('Customer #%d', (int) $customer->id));
        }
        if (!empty($args['id_cart'])) {
            $cart = $this->loadCart((int) $args['id_cart'], $ctx);
        } elseif ($customer) {
            $idCart = (int) $this->db()->getValue(
                'SELECT c.`id_cart` FROM `' . _DB_PREFIX_ . 'cart` c
                 LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_cart` = c.`id_cart`
                 WHERE c.`id_customer` = ' . (int) $customer->id . ' AND c.`id_shop` = ' . (int) $ctx->idShop . ' AND o.`id_order` IS NULL
                 ORDER BY c.`date_upd` DESC'
            );
            if (!$idCart) {
                throw new ToolException(sprintf('Customer #%d has no cart without an order in shop #%d.', (int) $customer->id, (int) $ctx->idShop), 'Pass id_cart (cart_search lists carts).');
            }
            $cart = $this->loadCart($idCart, $ctx);
        } else {
            throw new ToolException('Pass id_cart, or id_customer/email to use the customer\'s latest open cart.');
        }
        if ((int) $cart->id_customer && (!$customer || (int) $customer->id !== (int) $cart->id_customer)) {
            $customer = new \Customer((int) $cart->id_customer);
        }

        $orderId = $this->orderIdForCart((int) $cart->id);
        if ($orderId) {
            $this->fail('cart_already_ordered', 'checkout', sprintf('Order #%d was already placed with this cart.', $orderId), 'Payment fails with "Cart cannot be loaded or an order has already been placed using this cart"; the storefront drops the cart from the session.', 'Nothing to fix: the shopper must use a new cart (order_get shows order #' . $orderId . ').');
        }
        if (!(int) $cart->id_customer) {
            $this->warn('cart_customer', 'Cart has no customer yet (visitor did not pass the personal information step). Carriers/payments evaluated for the guest group.');
        }

        $sh = $this->buildShopper($ctx, $customer && \Validate::isLoadedObject($customer) ? $customer : null, $cart, 0);
        $this->shopChecks($sh, 'checkout');
        $this->customerChecks($sh);

        $rows = $this->withShopper($sh, function () use ($sh) {
            return $this->cartLines($sh['cart']);
        });
        if (!$rows) {
            $this->fail('cart_empty', 'checkout', 'The cart has no products.', 'The cart page shows "There are no more items in your cart" and checkout redirects to the cart.', null);
        }

        $lines = [];
        $linesOut = [];
        foreach ($rows as $r) {
            $this->item = sprintf('#%d-%d %s', (int) $r['id_product'], (int) $r['id_product_attribute'], $r['name']);
            $line = $this->withShopper($sh, function () use ($sh, $r) {
                return $this->productChecks($sh, (int) $r['id_product'], (int) $r['id_product_attribute'], (int) $r['cart_quantity'], 0, 'checkout', (int) $r['id_customization']);
            });
            $this->item = null;
            $lines[] = $line ?: $this->lineFromCartRow($r);
            $linesOut[] = [
                'id_product' => (int) $r['id_product'],
                'id_product_attribute' => (int) $r['id_product_attribute'],
                'name' => $r['name'],
                'combination' => isset($r['attributes_small']) ? $r['attributes_small'] : null,
                'quantity' => (int) $r['cart_quantity'],
                'stock' => $line ? $line['stock'] : null,
            ];
        }

        $this->addressChecks($sh);

        $selectedRef = null;
        if ((int) $cart->id_carrier) {
            $selectedRef = (int) $this->db()->getValue('SELECT `id_reference` FROM `' . _DB_PREFIX_ . 'carrier` WHERE `id_carrier` = ' . (int) $cart->id_carrier);
        }
        $result = $lines ? $this->checkoutChecks($sh, $lines, $selectedRef, 'checkout', true) : [];

        if ((int) $cart->id_carrier && isset($result['carriers']['available'])) {
            if (!isset($result['carriers']['available'][(int) $cart->id_carrier])) {
                $this->warn('selected_carrier', sprintf('The carrier stored on the cart (#%d) is not available any more; the shopper must choose another delivery option.', (int) $cart->id_carrier));
            } else {
                $this->pass('selected_carrier');
            }
        }

        $context = $this->contextBlock($sh);
        $context['cart'] = [
            'id_cart' => (int) $cart->id,
            'date_add' => $cart->date_add,
            'date_upd' => $cart->date_upd,
            'id_carrier' => (int) $cart->id_carrier,
            'id_address_delivery' => (int) $cart->id_address_delivery,
            'id_address_invoice' => (int) $cart->id_address_invoice,
            'lines' => $linesOut,
        ];
        if (!empty($result['products_total_tax_excl'])) {
            $context['cart']['products_total_tax_excl'] = $this->money((float) $result['products_total_tax_excl'], (int) $sh['id_currency']);
        }

        if (!isset($args['core_delivery_check']) || $args['core_delivery_check']) {
            $context['core_delivery_options'] = $this->coreDeliveryOptions($sh);
            if ($this->coreCarrierIds !== null && isset($result['carriers']['available']) && empty($result['carriers']['virtual'])) {
                $mine = $result['carriers']['available'];
                $missing = array_diff_key($mine, $this->coreCarrierIds);
                if (!$this->coreCarrierIds && $mine) {
                    $this->fail('carriers_core', 'checkout', 'Core (Cart::getDeliveryOptionList) offers no delivery option although carriers pass the zone/group/range checks: their modules refuse the cart (shipping cost = false), or a module/override changes the list.', 'Unfortunately, there are no carriers available for your delivery address.', 'Check the carrier modules\' settings (' . implode(', ', array_unique(array_filter(array_column($mine, 'module')))) . ') and module_list for overrides.');
                } elseif ($missing) {
                    $this->warn('carriers_core', 'Core does not offer ' . implode(', ', array_map(function ($a) {
                        return $a['name'] . ' #' . $a['id_carrier'];
                    }, $missing)) . ' for this cart: the carrier module refused it (shipping cost = false; e.g. no pickup point/address yet, API limits).');
                } else {
                    $this->pass('carriers_core');
                }
            }
        }
        $this->addCorePaymentCheck($context, $sh, $result, (int) $cart->id_carrier);

        $logs = $this->db()->executeS(
            'SELECT `id_log`, `severity`, `message`, `date_add` FROM `' . _DB_PREFIX_ . 'log`
             WHERE `object_type` = \'Cart\' AND `object_id` = ' . (int) $cart->id . ' ORDER BY `id_log` DESC LIMIT 20'
        ) ?: [];
        foreach ($logs as &$l) {
            $l['id_log'] = (int) $l['id_log'];
            $l['severity'] = (int) $l['severity'];
            $l['message'] = mb_substr($l['message'], 0, 400);
        }
        unset($l);
        $context['log_entries'] = $logs;
        if (!$logs) {
            $context['log_entries_note'] = 'No PrestaShop log entries for this cart (payment modules log validateOrder errors here).';
        }

        $verdict = $this->report['blocking'] ? 'blocked' : 'can_checkout';

        return $this->output($verdict, $context);
    }

    // ------------------------------------------------------------------
    // diagnose_product_visibility
    // ------------------------------------------------------------------

    public function productVisibility(array $args, ExecutionContext $ctx): array
    {
        $this->resetReport();
        $P = _DB_PREFIX_;
        $idProduct = (int) $args['id_product'];
        $idShop = (int) $ctx->idShop;
        $idLang = (int) $ctx->idLang;
        $customer = $this->resolveCustomer($args, $idShop);
        if ($customer) {
            $this->assertShopAllowed($ctx, (int) $customer->id_shop, sprintf('Customer #%d', (int) $customer->id));
        }
        $sh = $this->buildShopper($ctx, $customer, null, 0);
        $groups = $customer ? $sh['access_groups'] : [(int) $sh['current_group']];
        $groupLabel = $customer ? 'customer #' . (int) $customer->id . ' groups' : 'visitor group';
        if (!empty($args['id_group'])) {
            $groups = [(int) $args['id_group']];
            $groupLabel = 'group #' . (int) $args['id_group'];
        }

        $p = $this->db()->getRow('SELECT * FROM `' . $P . 'product` WHERE `id_product` = ' . $idProduct);
        if (!$p) {
            throw new ToolException(sprintf('Product #%d does not exist.', $idProduct), 'catalog_product_search finds products by name or reference.');
        }
        $ps = $this->db()->getRow('SELECT * FROM `' . $P . 'product_shop` WHERE `id_product` = ' . $idProduct . ' AND `id_shop` = ' . $idShop);
        $name = $this->productName($idProduct, $idLang, $idShop);
        $this->shopChecks($sh, 'visibility');

        $everywhere = 'everywhere (product page 404/redirect, not in categories, search, sitemap)';
        if (!$ps) {
            $shops = $this->db()->executeS('SELECT `id_shop` FROM `' . $P . 'product_shop` WHERE `id_product` = ' . $idProduct) ?: [];
            $this->fail('shop_association', $everywhere, sprintf('Product is not associated with shop #%d (associated shops: %s).', $idShop, implode(', ', array_column($shops, 'id_shop')) ?: 'none'), 'Product page: 404 "page not found".', 'Associate the product with this shop in the product page (multistore) or catalog_product_update with id_shop.');

            return $this->output('hidden', ['product' => ['id_product' => $idProduct, 'name' => $name]] + $this->contextBlock($sh));
        }
        $this->pass('shop_association');

        if (isset($p['state']) && (int) $p['state'] === 0) {
            $this->fail('product_state', $everywhere, 'product.state = 0: the product is a temporary draft that was never saved in the back office.', 'Not found.', 'Open and save the product in the back office.');
        }

        // Active / redirection
        if (!(int) $ps['active']) {
            $redirect = (string) $ps['redirect_type'];
            $effective = $redirect;
            if ($redirect === '' || $redirect === 'default') {
                $effective = (string) $this->cfg($sh, 'PS_PRODUCT_REDIRECTION_DEFAULT');
                if ($effective === '') {
                    $effective = '404';
                }
            }
            $target = (int) (isset($ps['id_type_redirected']) ? $ps['id_type_redirected'] : (isset($p['id_type_redirected']) ? $p['id_type_redirected'] : 0));
            $this->fail('active', $everywhere, sprintf('Product is disabled in shop #%d (product_shop.active = 0). redirect_type = "%s"%s → %s.', $idShop, $redirect, $effective !== $redirect ? ' (default: PS_PRODUCT_REDIRECTION_DEFAULT = "' . $effective . '")' : '', $this->describeRedirect($effective, $target)), $this->describeRedirect($effective, $target), 'Enable it with catalog_product_update (active=true), or set a redirect to a replacement product/category.');
        } else {
            $this->pass('active');
        }

        // Visibility
        $vis = (string) $ps['visibility'];
        $map = [
            'both' => 'category pages and search',
            'catalog' => 'category pages only (not in search results)',
            'search' => 'search only (not on category pages)',
            'none' => 'nowhere in listings; product page reachable only by direct URL',
        ];
        if ($vis === 'both') {
            $this->pass('visibility', 'both');
        } else {
            $this->warn('visibility', sprintf('visibility = "%s": shown in %s.', $vis, isset($map[$vis]) ? $map[$vis] : $vis), null, 'catalog_product_update visibility="both" to show it on category pages and in search.');
        }

        // Categories
        $cats = $this->db()->executeS(
            'SELECT c.`id_category`, c.`active`, c.`id_parent`, c.`nleft`, c.`nright`, c.`is_root_category`, cl.`name`,
                    (SELECT 1 FROM `' . $P . 'category_shop` cs WHERE cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . $idShop . ' LIMIT 1) AS in_shop,
                    (SELECT GROUP_CONCAT(cg.`id_group`) FROM `' . $P . 'category_group` cg WHERE cg.`id_category` = c.`id_category`) AS grps
             FROM `' . $P . 'category_product` cp
             JOIN `' . $P . 'category` c ON c.`id_category` = cp.`id_category`
             LEFT JOIN `' . $P . 'category_lang` cl ON cl.`id_category` = c.`id_category` AND cl.`id_lang` = ' . $idLang . ' AND cl.`id_shop` = ' . $idShop . '
             WHERE cp.`id_product` = ' . $idProduct . ' ORDER BY c.`nleft`'
        ) ?: [];
        $rootCat = (int) $this->db()->getValue('SELECT `id_category` FROM `' . $P . 'shop` WHERE `id_shop` = ' . $idShop);
        $rootRow = $this->db()->getRow('SELECT `nleft`, `nright` FROM `' . $P . 'category` WHERE `id_category` = ' . $rootCat);
        $catOut = [];
        $listable = 0;
        $groupOk = false;
        foreach ($cats as $c) {
            $problems = [];
            if (!(int) $c['active']) {
                $problems[] = 'inactive (category page 404)';
            }
            if (!$c['in_shop']) {
                $problems[] = 'not in shop';
            }
            if ($rootRow && ((int) $c['nleft'] < (int) $rootRow['nleft'] || (int) $c['nright'] > (int) $rootRow['nright'])) {
                $problems[] = 'outside this shop\'s root category #' . $rootCat;
            }
            $inactiveParents = $this->db()->executeS(
                'SELECT c.`id_category`, cl.`name` FROM `' . $P . 'category` c
                 LEFT JOIN `' . $P . 'category_lang` cl ON cl.`id_category` = c.`id_category` AND cl.`id_lang` = ' . $idLang . ' AND cl.`id_shop` = ' . $idShop . '
                 WHERE c.`nleft` < ' . (int) $c['nleft'] . ' AND c.`nright` > ' . (int) $c['nright'] . ' AND c.`active` = 0 AND c.`id_parent` > 0'
            ) ?: [];
            if ($inactiveParents) {
                $problems[] = 'inactive parent(s): ' . implode(', ', array_map(function ($r) {
                    return '#' . $r['id_category'] . ' ' . $r['name'];
                }, $inactiveParents)) . ' (not reachable from the menu)';
            }
            $cg = $c['grps'] !== null && $c['grps'] !== '' ? array_map('intval', explode(',', $c['grps'])) : [];
            $access = (bool) array_intersect($cg, $groups);
            if ($access) {
                $groupOk = true;
            } else {
                $problems[] = $groupLabel . ' [' . implode(',', $groups) . '] have no access (category groups: ' . ($cg ? implode(',', $cg) : 'none') . ')';
            }
            if (!$problems || (count($problems) === 1 && strpos($problems[0], 'inactive parent') === 0)) {
                ++$listable;
            }
            $catOut[] = ['id_category' => (int) $c['id_category'], 'name' => $c['name'], 'default' => (int) $c['id_category'] === (int) $ps['id_category_default'], 'problems' => $problems];
        }
        if (!$cats) {
            $this->fail('categories', 'category pages', 'Product is in no category.', 'Not listed on any category page.', 'Add it to a category with catalog_product_update (categories).');
        } elseif (!$listable) {
            $this->fail('categories', 'category pages', 'None of the product\'s categories can list it: ' . $this->compactCats($catOut), 'Not listed on any category page.', 'Activate a category / give the group access (Catalog > Categories > Group access) / add the product to an active category.');
        } else {
            $bad = array_filter($catOut, function ($c) {
                return (bool) $c['problems'];
            });
            if ($bad) {
                $this->warn('categories', sprintf('Listed in %d of %d categories. Not listed in: %s', $listable, count($catOut), $this->compactCats($bad)));
            } else {
                $this->pass('categories', count($catOut) . ' categories');
            }
        }
        $defaultOk = false;
        foreach ($catOut as $c) {
            if ($c['default']) {
                $defaultOk = true;
                if ($c['problems']) {
                    $this->warn('default_category', 'Default category #' . $c['id_category'] . ' has problems: ' . implode('; ', $c['problems']) . '. Breadcrumb and canonical URL use it.', null, 'catalog_product_update id_category_default to an active category.');
                }
            }
        }
        if (!$defaultOk && $cats) {
            $this->warn('default_category', 'Default category #' . (int) $ps['id_category_default'] . ' is not one of the product\'s categories.', null, 'catalog_product_update id_category_default.');
        }

        // Group access (product page)
        if (!\Group::isFeatureActive()) {
            $this->skip('group_access', 'customer groups feature disabled (PS_GROUP_FEATURE_ACTIVE=0)');
        } elseif (!$groupOk) {
            $this->fail('group_access', 'product page, listings, search', sprintf('No category of the product is open to %s [%s].', $groupLabel, implode(',', $groups)), 'Product page: HTTP 403 "You do not have access to this product."; hidden from listings and search for that group.', 'Give the group access to one of the product\'s categories (Catalog > Categories > Group access) or add the product to a category the group can see.');
        } else {
            $this->pass('group_access', $groupLabel);
        }

        // Search index
        $searchVisible = in_array($vis, ['both', 'search'], true);
        $words = (int) $this->db()->getValue(
            'SELECT COUNT(*) FROM `' . $P . 'search_index` si JOIN `' . $P . 'search_word` sw ON sw.`id_word` = si.`id_word`
             WHERE si.`id_product` = ' . $idProduct . ' AND sw.`id_shop` = ' . $idShop . ' AND sw.`id_lang` = ' . $idLang
        );
        if (!(int) $ps['active']) {
            $this->skip('search_index', 'product disabled (disabled products are never indexed)');
        } elseif (!$searchVisible) {
            $this->skip('search_index', 'visibility excludes search');
        } elseif (!(int) $ps['indexed'] || !$words) {
            $this->fail('search_index', 'search', sprintf('product_shop.indexed = %d, %d words indexed for this language; PS_SEARCH_INDEXATION = %s.', (int) $ps['indexed'], $words, $this->cfg($sh, 'PS_SEARCH_INDEXATION') ? '1 (indexed on save)' : '0 (not indexed automatically on save)'), 'Not found by the search box.', 'Rebuild the search index: Shop Parameters > Search > "Add missing products to the index" (or re-save the product).');
        } else {
            $this->pass('search_index', $words . ' words');
        }

        // Faceted search
        $faceted = $this->db()->getRow('SELECT m.`id_module`, m.`active`, (SELECT 1 FROM `' . $P . 'module_shop` ms WHERE ms.`id_module` = m.`id_module` AND ms.`id_shop` = ' . $idShop . ' LIMIT 1) AS in_shop FROM `' . $P . 'module` m WHERE m.`name` = \'ps_facetedsearch\'');
        if ($faceted && (int) $faceted['active'] && $faceted['in_shop'] && (int) $ps['active']) {
            if ($this->tableExists('layered_price_index')) {
                $priceRows = (int) $this->db()->getValue('SELECT COUNT(*) FROM `' . $P . 'layered_price_index` WHERE `id_product` = ' . $idProduct . ' AND `id_shop` = ' . $idShop);
                if (!$priceRows && (int) $ps['active']) {
                    $this->warn('faceted_index', 'ps_facetedsearch is active but the product has no row in layered_price_index: price filters/sorting may hide it from filtered category pages.', null, 'Rebuild the price index in Modules > ps_facetedsearch > "Rebuild price index"; clear its filter cache.');
                } else {
                    $this->pass('faceted_index');
                }
            }
        } else {
            $this->skip('faceted_index', (int) $ps['active'] ? 'ps_facetedsearch not active in this shop' : 'product disabled');
        }

        // Dates
        $today = date('Y-m-d');
        $availableDate = isset($ps['available_date']) ? (string) $ps['available_date'] : '';
        if ($availableDate && $availableDate !== '0000-00-00' && $availableDate > $today) {
            $this->warn('available_date', 'available_date = ' . $availableDate . ' is in the future: shown with the availability date; does not hide the product or block ordering.');
        }
        $nbDays = (int) $this->cfg($sh, 'PS_NB_DAYS_NEW_PRODUCT');
        $dateAdd = isset($ps['date_add']) ? (string) $ps['date_add'] : (string) $p['date_add'];
        $isNew = $nbDays > 0 && strtotime($dateAdd) > time() - $nbDays * 86400;

        if (!(int) $ps['available_for_order']) {
            $this->warn('available_for_order', 'available_for_order = 0: visible but cannot be ordered (add-to-cart button disabled).', null, 'catalog_product_update available_for_order=true.');
        }

        $verdict = $this->report['blocking'] ? 'partially_hidden' : 'visible';
        foreach ($this->report['blocking'] as $b) {
            if (strpos($b['blocks'], 'everywhere') === 0 || $b['check'] === 'group_access') {
                $verdict = 'hidden';
            }
        }

        $context = [
            'product' => [
                'id_product' => $idProduct,
                'name' => $name,
                'active' => (bool) $ps['active'],
                'visibility' => $vis,
                'id_category_default' => (int) $ps['id_category_default'],
                'categories' => $catOut,
                'date_add' => $dateAdd,
                'is_new' => $isNew,
                'new_period_days' => $nbDays,
                'available_date' => $availableDate,
                'url' => $this->productUrl($idProduct, $idLang, $idShop),
            ],
            'evaluated_groups' => $groups,
        ] + $this->contextBlock($sh);

        return $this->output($verdict, $context);
    }

    // ------------------------------------------------------------------
    // Shopper / context
    // ------------------------------------------------------------------

    /**
     * @throws ToolException
     */
    private function resolveCustomer(array $args, int $idShop): ?\Customer
    {
        if (!empty($args['id_customer'])) {
            $c = new \Customer((int) $args['id_customer']);
            if (!\Validate::isLoadedObject($c)) {
                throw new ToolException(sprintf('Customer #%d does not exist.', (int) $args['id_customer']), 'customer_search finds customers by name or e-mail.');
            }

            return $c;
        }
        if (!empty($args['email'])) {
            $email = trim((string) $args['email']);
            if (!\Validate::isEmail($email)) {
                throw new ToolException('email is not a valid e-mail address.');
            }
            $id = (int) $this->db()->getValue(
                'SELECT `id_customer` FROM `' . _DB_PREFIX_ . 'customer` WHERE `email` = \'' . pSQL($email) . '\'
                 ORDER BY (`id_shop` = ' . (int) $idShop . ') DESC, `deleted` ASC, `is_guest` ASC, `id_customer` DESC'
            );
            if (!$id) {
                throw new ToolException(sprintf('No customer with e-mail %s.', $email), 'customer_search finds customers; omit email to evaluate as a visitor.');
            }

            return new \Customer($id);
        }

        return null;
    }

    /**
     * @throws ToolException
     */
    private function loadCart(int $idCart, ExecutionContext $ctx): \Cart
    {
        $cart = new \Cart($idCart);
        if (!\Validate::isLoadedObject($cart)) {
            throw new ToolException(sprintf('Cart #%d does not exist.', $idCart), 'cart_search lists carts.');
        }
        if ((int) $cart->id_shop !== (int) $ctx->idShop) {
            throw new ToolException(sprintf('Cart #%d belongs to shop #%d.', $idCart, (int) $cart->id_shop), 'Call again with id_shop=' . (int) $cart->id_shop . '.');
        }

        return $cart;
    }

    private function orderIdForCart(int $idCart): int
    {
        return (int) $this->db()->getValue('SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_cart` = ' . $idCart);
    }

    /**
     * Everything that defines what the shopper sees: groups, country, zone, currency.
     */
    private function buildShopper(ExecutionContext $ctx, ?\Customer $customer, ?\Cart $cart, int $idCountryArg): array
    {
        $P = _DB_PREFIX_;
        $idShop = (int) $ctx->idShop;
        $shop = new \Shop($idShop);
        $sh = [
            'id_shop' => $idShop,
            'id_shop_group' => (int) $shop->id_shop_group,
            'id_lang' => (int) $ctx->idLang,
            'shop_active' => (bool) $shop->active,
            'customer' => $customer,
            'cart' => $cart,
            'notes' => [],
        ];
        $unident = (int) $this->cfg($sh, 'PS_UNIDENTIFIED_GROUP');
        $guestGroup = (int) $this->cfg($sh, 'PS_GUEST_GROUP');
        $customerGroup = (int) $this->cfg($sh, 'PS_CUSTOMER_GROUP');

        if ($customer) {
            $groups = array_map('intval', \Customer::getGroupsStatic((int) $customer->id));
            $default = (int) $customer->id_default_group;
            $sh['access_groups'] = $groups;
            $sh['checkout_groups'] = $groups;
            // Hook: logged customer → all groups; guest → PS_GUEST_GROUP
            $sh['hook_groups'] = (int) $customer->is_guest ? [$guestGroup] : $groups;
        } else {
            $default = $unident;
            $sh['access_groups'] = [$unident];
            // At checkout a visitor becomes a guest customer (or registers).
            $sh['checkout_groups'] = [$guestGroup];
            $sh['hook_groups'] = [$guestGroup];
            $sh['notes'][] = 'Visitor: catalog/cart checks use the unidentified group #' . $unident . '; carriers/payments use the guest group #' . $guestGroup . ' (a visitor becomes a guest at checkout; a registered account would use group #' . $customerGroup . ').';
        }
        $inShop = (bool) $this->db()->getValue('SELECT 1 FROM `' . $P . 'group_shop` WHERE `id_group` = ' . (int) $default . ' AND `id_shop` = ' . $idShop);
        $sh['default_group'] = $default;
        $sh['default_group_in_shop'] = $inShop;
        $sh['current_group'] = $inShop ? $default : $customerGroup;

        // Address / country / zone
        $address = null;
        if ($cart && (int) $cart->id_address_delivery) {
            $address = new \Address((int) $cart->id_address_delivery);
        } elseif ($customer) {
            $idAddress = (int) \Address::getFirstCustomerAddressId((int) $customer->id);
            if ($idAddress) {
                $address = new \Address($idAddress);
            }
        }
        if ($address && !\Validate::isLoadedObject($address)) {
            $address = null;
        }
        $sh['address'] = $address;
        if ($idCountryArg) {
            $idCountry = $idCountryArg;
            $sh['country_source'] = 'argument';
        } elseif ($address) {
            $idCountry = (int) $address->id_country;
            $sh['country_source'] = 'address #' . (int) $address->id;
        } else {
            $idCountry = (int) $this->cfg($sh, 'PS_COUNTRY_DEFAULT');
            $sh['country_source'] = 'shop default (PS_COUNTRY_DEFAULT)';
        }
        $country = new \Country($idCountry, (int) $ctx->idLang);
        if (!\Validate::isLoadedObject($country)) {
            throw new ToolException(sprintf('Country #%d does not exist.', $idCountry));
        }
        $sh['country'] = $country;
        $sh['id_country'] = $idCountry;
        if ($address && (int) $address->id_country === $idCountry) {
            $sh['id_zone'] = (int) \Address::getZoneById((int) $address->id);
        } else {
            $sh['id_zone'] = (int) $country->id_zone;
        }

        $idCurrency = $cart && (int) $cart->id_currency ? (int) $cart->id_currency : (int) $this->context()->currency->id;
        if (!$idCurrency) {
            $idCurrency = (int) $this->cfg($sh, 'PS_CURRENCY_DEFAULT');
        }
        $sh['id_currency'] = $idCurrency;

        return $sh;
    }

    /**
     * Run $fn with Context set to the shopper, as the storefront would have it.
     * The employee is removed so Hook works in front-office mode.
     *
     * @return mixed
     */
    private function withShopper(array $sh, callable $fn)
    {
        $c = $this->context();
        $saved = [$c->employee, $c->customer, $c->cart, $c->country, $c->currency];
        try {
            $c->employee = null;
            $c->customer = $sh['customer'] ?: new \Customer();
            if ($sh['cart']) {
                $c->cart = $sh['cart'];
            } else {
                $cart = new \Cart();
                $cart->id_shop = (int) $sh['id_shop'];
                $cart->id_shop_group = (int) $sh['id_shop_group'];
                $cart->id_lang = (int) $sh['id_lang'];
                $cart->id_currency = (int) $sh['id_currency'];
                $cart->id_customer = $sh['customer'] ? (int) $sh['customer']->id : 0;
                $cart->id_address_delivery = $sh['address'] ? (int) $sh['address']->id : 0;
                $cart->id_address_invoice = $cart->id_address_delivery;
                $c->cart = $cart;
            }
            $c->country = $sh['country'];
            $currency = new \Currency((int) $sh['id_currency']);
            if (\Validate::isLoadedObject($currency)) {
                $c->currency = $currency;
            }

            return $fn();
        } finally {
            list($c->employee, $c->customer, $c->cart, $c->country, $c->currency) = $saved;
        }
    }

    /**
     * @return array rows of Cart::getProducts (read-only)
     */
    private function cartLines(\Cart $cart): array
    {
        if (!(int) $cart->id) {
            return [];
        }
        $rows = $cart->getProducts(true);

        return is_array($rows) ? $rows : [];
    }

    private function resolveDefaultCombination(int $idProduct, int $idShop): int
    {
        $oos = (int) \StockAvailable::outOfStock($idProduct, $idShop);
        $min = $oos === 2 ? !\Configuration::get('PS_ORDER_OUT_OF_STOCK') : !$oos;

        return (int) \Product::getDefaultAttribute($idProduct, (int) $min);
    }

    // ------------------------------------------------------------------
    // Check groups
    // ------------------------------------------------------------------

    private function shopChecks(array $sh, string $stage): void
    {
        $P = _DB_PREFIX_;
        if (!$sh['shop_active']) {
            $this->fail('shop_active', $stage === 'visibility' ? 'everywhere (shop disabled)' : $stage, 'Shop #' . $sh['id_shop'] . ' is disabled (multistore).', 'Redirected to the default shop or "shop not found".', 'Enable the shop in Advanced Parameters > Multistore.');
        }
        if (!$this->cfg($sh, 'PS_SHOP_ENABLE')) {
            $ips = (string) $this->cfg($sh, 'PS_MAINTENANCE_IP');
            $this->fail('maintenance', $stage === 'visibility' ? 'everywhere (maintenance page)' : $stage, 'Maintenance mode is on (PS_SHOP_ENABLE=0). Only these IPs can shop: ' . ($ips ?: 'none') . (version_compare(_PS_VERSION_, '9.0.0', '>=') ? '; logged-in employees too when PS_MAINTENANCE_ALLOW_ADMINS=1.' : '.'), 'HTTP 503 maintenance page.', 'Shop Parameters > General > Maintenance: enable the shop.');
        } else {
            $this->pass('maintenance');
        }

        $catalog = (bool) $this->cfg($sh, 'PS_CATALOG_MODE');
        $showPrices = true;
        $groupInfo = '';
        if (\Group::isFeatureActive()) {
            $showPrices = (bool) $this->db()->getValue('SELECT `show_prices` FROM `' . $P . 'group` WHERE `id_group` = ' . (int) $sh['current_group']);
            $groupInfo = ' group #' . (int) $sh['current_group'] . ' show_prices=' . (int) $showPrices;
        }
        if (($catalog || !$showPrices) && $stage === 'visibility') {
            $this->warn('catalog_mode', ($catalog ? 'PS_CATALOG_MODE = 1' : 'group #' . (int) $sh['current_group'] . ' has "Show prices" disabled') . ': products are visible but cannot be added to the cart.');
        } elseif ($catalog || !$showPrices) {
            $why = $catalog ? 'PS_CATALOG_MODE = 1 (catalog mode for everyone)' : 'customer group #' . (int) $sh['current_group'] . ' has "Show prices" disabled, which puts that group in catalog mode';
            $this->fail('catalog_mode', 'cart_add', $why . '.', 'No add-to-cart button and no prices' . ($catalog && version_compare(_PS_VERSION_, '1.7.6.0', '>=') ? ' (prices shown if PS_CATALOG_MODE_WITH_PRICES=1)' : '') . '; a direct add returns "You already have the maximum quantity available for this product." and checkout redirects to the home page.', $catalog ? 'Shop Parameters > Product Settings: disable catalog mode.' : 'Customers > Groups > group #' . (int) $sh['current_group'] . ': enable "Show prices".');
        } else {
            $this->pass('catalog_mode', trim($groupInfo));
        }
        if (!$sh['default_group_in_shop']) {
            $this->warn('group_shop', sprintf('Default group #%d is not associated with shop #%d; core falls back to PS_CUSTOMER_GROUP #%d for prices and show_prices.', (int) $sh['default_group'], (int) $sh['id_shop'], (int) $sh['current_group']));
        }

        if ($this->cfg($sh, 'PS_GEOLOCATION_ENABLED')) {
            $allowed = array_filter(explode(';', (string) $this->cfg($sh, 'PS_ALLOWED_COUNTRIES')));
            $iso = (string) $sh['country']->iso_code;
            $behavior = (int) $this->cfg($sh, 'PS_GEOLOCATION_BEHAVIOR');
            $dbFile = defined('_PS_GEOIP_DIR_') && defined('_PS_GEOIP_CITY_FILE_') ? file_exists(_PS_GEOIP_DIR_ . _PS_GEOIP_CITY_FILE_) : false;
            if (!$dbFile) {
                $this->warn('geolocation', 'PS_GEOLOCATION_ENABLED=1 but the GeoLite database file is missing, so geolocation is inactive.');
            } elseif ($allowed && !in_array($iso, $allowed, true)) {
                $this->fail('geolocation', $behavior === 0 ? ($stage === 'visibility' ? 'everywhere (restricted country page)' : 'cart_add') : 'checkout', sprintf('Geolocation is on and %s is not in PS_ALLOWED_COUNTRIES (behaviour %d). Applies when the shopper\'s IP is located there (IP unknown to this tool; whitelist PS_GEOLOCATION_WHITELIST).', $iso, $behavior), $behavior === 0 ? '403 "restricted country" page.' : 'Catalog mode + "You cannot place a new order from your country (' . $sh['country']->name . ')."', 'International > Localization > Geolocation: allow the country or whitelist the IP.');
            } else {
                $this->pass('geolocation');
            }
        } else {
            $this->skip('geolocation', 'disabled');
        }
    }

    private function customerChecks(array $sh): void
    {
        $P = _DB_PREFIX_;
        $c = $sh['customer'];
        if (!$c) {
            if (!$this->cfg($sh, 'PS_GUEST_CHECKOUT_ENABLED')) {
                $this->warn('guest_checkout', 'Guest checkout is disabled (PS_GUEST_CHECKOUT_ENABLED=0): a visitor must create an account (with password) at the personal information step.');
            } else {
                $this->pass('customer', 'visitor');
            }

            return;
        }
        if ((int) $c->deleted || !(int) $c->active) {
            $this->fail('customer_account', 'checkout', sprintf('Customer #%d is %s (active=%d, deleted=%d). The storefront logs them out on every page; they can only shop as a visitor.', (int) $c->id, (int) $c->deleted ? 'deleted' : 'disabled', (int) $c->active, (int) $c->deleted), 'Login: "Your account isn\'t available at this time, please contact us"', 'Enable the customer (Customers > Customers) — customer_update with active=true if available.');
        } else {
            $this->pass('customer_account');
        }
        if ((int) $c->is_guest) {
            if (!$this->cfg($sh, 'PS_GUEST_CHECKOUT_ENABLED')) {
                $this->fail('guest_account', 'checkout', 'Customer is a guest account (no password) and guest checkout is disabled (PS_GUEST_CHECKOUT_ENABLED=0).', 'Cannot log in; must create a new account at checkout.', 'Enable guest checkout or have the customer register (guest→customer conversion in the order page).');
            } else {
                $this->warn('guest_account', 'Customer is a guest account (is_guest=1): cannot log in, orders again as guest with the same e-mail.');
            }
        }
        // Customer shop
        $customerShop = (int) $c->id_shop;
        if ($customerShop && $customerShop !== (int) $sh['id_shop']) {
            $share = (bool) $this->db()->getValue('SELECT sg.`share_customer` FROM `' . $P . 'shop_group` sg WHERE sg.`id_shop_group` = ' . (int) $sh['id_shop_group']);
            $sameGroup = (int) $this->db()->getValue('SELECT `id_shop_group` FROM `' . $P . 'shop` WHERE `id_shop` = ' . $customerShop) === (int) $sh['id_shop_group'];
            if (!$share || !$sameGroup) {
                $this->fail('customer_shop', 'checkout', sprintf('Customer account belongs to shop #%d and customers are not shared with shop #%d.', $customerShop, (int) $sh['id_shop']), 'Login fails ("Authentication failed") in this shop.', 'Share customers in the shop group (Advanced Parameters > Multistore) or let the customer register in this shop.');
            }
        }
    }

    /**
     * All conditions of adding/keeping one product line.
     *
     * @return array|null line data, null when the product cannot be evaluated further
     */
    private function productChecks(array $sh, int $idProduct, ?int $ipa, int $qty, int $inCart, string $stage, int $idCustomization)
    {
        $P = _DB_PREFIX_;
        $idShop = (int) $sh['id_shop'];
        $idLang = (int) $sh['id_lang'];
        $isCheckout = $stage === 'checkout' && $this->item !== null;
        $v177 = version_compare(_PS_VERSION_, '1.7.7.0', '>=');
        $v8 = version_compare(_PS_VERSION_, '8.0.0', '>=');

        $p = $this->db()->getRow('SELECT * FROM `' . $P . 'product` WHERE `id_product` = ' . $idProduct);
        if (!$p) {
            $this->fail('product_exists', $stage, sprintf('Product #%d does not exist.', $idProduct), 'Product not found', 'catalog_product_search to find the right product.');

            return null;
        }
        $name = $this->productName($idProduct, $idLang, $idShop);
        $unavailable = sprintf('This product (%s) is no longer available.', $name);
        $ps = $this->db()->getRow('SELECT * FROM `' . $P . 'product_shop` WHERE `id_product` = ' . $idProduct . ' AND `id_shop` = ' . $idShop);
        if (!$ps) {
            $this->fail('shop_association', $stage, sprintf('Product is not associated with shop #%d.', $idShop), $unavailable . ' (product page: 404)', 'Associate the product with this shop (product page in multistore) or catalog_product_update with id_shop.');

            return null;
        }
        $this->pass('shop_association');

        if (!(int) $ps['active']) {
            $this->fail('active', $stage, 'Product is disabled in this shop (product_shop.active = 0).', $unavailable . ' Product page: ' . $this->describeRedirect((string) $ps['redirect_type'], (int) (isset($ps['id_type_redirected']) ? $ps['id_type_redirected'] : (isset($p['id_type_redirected']) ? $p['id_type_redirected'] : 0))), 'catalog_product_update active=true.');
        } else {
            $this->pass('active');
        }
        if (isset($p['state']) && (int) $p['state'] === 0) {
            $this->warn('product_state', 'product.state = 0: unfinished draft created in the back office.');
        }

        // Group access (Product::checkAccessStatic)
        if (!\Group::isFeatureActive()) {
            $this->skip('group_access', 'PS_GROUP_FEATURE_ACTIVE=0');
        } else {
            if ($sh['customer']) {
                $ok = (bool) $this->db()->getValue(
                    'SELECT 1 FROM `' . $P . 'category_product` cp
                     JOIN `' . $P . 'category_group` ctg ON ctg.`id_category` = cp.`id_category`
                     JOIN `' . $P . 'customer_group` cg ON cg.`id_group` = ctg.`id_group`
                     WHERE cp.`id_product` = ' . $idProduct . ' AND cg.`id_customer` = ' . (int) $sh['customer']->id
                );
                $who = 'customer groups [' . implode(',', $sh['access_groups']) . ']';
            } else {
                $ok = (bool) $this->db()->getValue(
                    'SELECT 1 FROM `' . $P . 'category_product` cp
                     JOIN `' . $P . 'category_group` ctg ON ctg.`id_category` = cp.`id_category`
                     WHERE cp.`id_product` = ' . $idProduct . ' AND ctg.`id_group` = ' . (int) $sh['current_group']
                );
                $who = 'visitor group #' . (int) $sh['current_group'];
            }
            if (!$ok) {
                $cats = $this->db()->executeS(
                    'SELECT cp.`id_category`, (SELECT GROUP_CONCAT(ctg.`id_group`) FROM `' . $P . 'category_group` ctg WHERE ctg.`id_category` = cp.`id_category`) AS grps
                     FROM `' . $P . 'category_product` cp WHERE cp.`id_product` = ' . $idProduct
                ) ?: [];
                $desc = implode('; ', array_map(function ($r) {
                    return '#' . $r['id_category'] . ' → groups ' . ($r['grps'] ?: 'none');
                }, $cats));
                $this->fail('group_access', $stage, sprintf('No category of the product is open to %s. Categories: %s.', $who, $desc ?: 'none'), $unavailable . ' Product page: 403 "You do not have access to this product."', 'Give the group access to one of these categories (Catalog > Categories > Group access) or add the product to a category the group sees (catalog_product_update categories).');
            } else {
                $this->pass('group_access');
            }
        }

        // Combination
        $combos = (int) $this->db()->getValue(
            'SELECT COUNT(*) FROM `' . $P . 'product_attribute` pa
             JOIN `' . $P . 'product_attribute_shop` pas ON pas.`id_product_attribute` = pa.`id_product_attribute` AND pas.`id_shop` = ' . $idShop . '
             WHERE pa.`id_product` = ' . $idProduct
        );
        $pa = null;
        $resolved = (int) $ipa;
        if ($isCheckout && !$resolved && $combos) {
            if ($v177) {
                $this->fail('combination', $stage, 'The cart line has no combination but the product now has combinations.', sprintf('The item %s in your cart is now a product with attributes. Please delete it and choose one of its combinations to proceed with your order.', $name), 'The shopper must remove the line and add a combination (cart lines cannot be edited by ppmcp).');
            } else {
                $this->warn('combination', 'The cart line has no combination but the product now has combinations (not checked by core before 1.7.7).');
            }
        } elseif (!$isCheckout && !$resolved && $combos) {
            $resolved = $this->resolveDefaultCombination($idProduct, $idShop);
            if (!$resolved) {
                $this->fail('combination', $stage, sprintf('Product has %d combinations but none qualifies as default (out-of-stock orders denied and no combination in stock).', $combos), 'The add-to-cart request just redirects back to the product page, no message.', 'Restock a combination (catalog_stock_set) or allow out-of-stock orders.');
            } else {
                $this->pass('combination', 'default #' . $resolved);
            }
        } elseif ($resolved) {
            $pa = $this->db()->getRow(
                'SELECT pa.`id_product`, pa.`weight`, pas.`id_product_attribute` AS in_shop, pas.`minimal_quantity`, pas.`default_on`' .
                ($this->hasColumn('product_attribute_shop', 'available_date') ? ', pas.`available_date`' : '') . '
                 FROM `' . $P . 'product_attribute` pa
                 LEFT JOIN `' . $P . 'product_attribute_shop` pas ON pas.`id_product_attribute` = pa.`id_product_attribute` AND pas.`id_shop` = ' . $idShop . '
                 WHERE pa.`id_product_attribute` = ' . $resolved
            );
            if (!$pa) {
                $this->fail('combination', $stage, sprintf('Combination #%d does not exist.', $resolved), 'You already have the maximum quantity available for this product.', 'Use a combination of this product (catalog_product_get lists them).');
                $pa = null;
            } elseif ((int) $pa['id_product'] !== $idProduct) {
                $this->fail('combination', $stage, sprintf('Combination #%d belongs to product #%d, not #%d.', $resolved, (int) $pa['id_product'], $idProduct), 'You already have the maximum quantity available for this product.', 'Use a combination of this product (catalog_product_get lists them).');
                $pa = null;
            } elseif (!$pa['in_shop']) {
                $this->fail('combination', $stage, sprintf('Combination #%d is not associated with shop #%d.', $resolved, $idShop), 'You already have the maximum quantity available for this product.', 'Associate the combination with the shop in the product page.');
            } else {
                $this->pass('combination');
            }
        } else {
            $this->pass('combination', 'product without combinations');
        }
        $label = $resolved ? $this->combinationLabel($resolved, $idLang) : null;

        // available_for_order / show_price
        $buttonOff = 'Add-to-cart button disabled on the product page.';
        if (!(int) $ps['available_for_order']) {
            $this->fail('available_for_order', $stage, 'available_for_order = 0.', $isCheckout ? $unavailable : $buttonOff . ' Direct add: "You already have the maximum quantity available for this product."', 'catalog_product_update available_for_order=true.');
        } else {
            $this->pass('available_for_order');
        }
        if (!(int) $ps['show_price'] && !$isCheckout) {
            $this->fail('show_price', $stage, 'show_price = 0: price hidden, so the add-to-cart button is disabled (a direct add URL would still work).', $buttonOff, 'catalog_product_update show_price=true.');
        }

        // Customization
        if ((int) $p['customizable'] && \Customization::isFeatureActive()) {
            $required = $this->db()->executeS(
                'SELECT cf.`id_customization_field`, MAX(cfl.`name`) AS name FROM `' . $P . 'customization_field` cf
                 LEFT JOIN `' . $P . 'customization_field_lang` cfl ON cfl.`id_customization_field` = cf.`id_customization_field` AND cfl.`id_lang` = ' . $idLang . '
                 WHERE cf.`id_product` = ' . $idProduct . ' AND cf.`required` = 1' . ($this->hasColumn('customization_field', 'is_deleted') ? ' AND cf.`is_deleted` = 0' : '') . '
                 GROUP BY cf.`id_customization_field`'
            ) ?: [];
            if ($required) {
                $names = implode(', ', array_map(function ($r) {
                    return '"' . $r['name'] . '"';
                }, $required));
                if ($isCheckout && !$idCustomization) {
                    $this->warn('customization', 'Line has no customization although fields are required: ' . $names . '.');
                } elseif (!$isCheckout) {
                    $this->warn('customization', sprintf('%d required customization field(s): %s. The shopper must fill them in and click "Save customization" before the add-to-cart button works.', count($required), $names), 'Please fill in all of the required fields, and then save your customizations.', 'Make the fields optional in the product page (Options > Customization) if they should not be mandatory.');
                }
            } else {
                $this->pass('customization', 'optional');
            }
        }

        // Stock
        $qtyToCheck = $inCart + $qty;
        $oos = (int) \StockAvailable::outOfStock($idProduct, $idShop);
        $stockMgmt = (bool) \Configuration::get('PS_STOCK_MANAGEMENT');
        $allowOos = (bool) \Product::isAvailableWhenOutOfStock($oos);
        $oosRule = $oos === 2 ? 'default (PS_ORDER_OUT_OF_STOCK=' . (int) \Configuration::get('PS_ORDER_OUT_OF_STOCK') . ')' : ($oos === 1 ? 'allow' : 'deny');
        $oosRule .= $allowOos ? ' → orders allowed when out of stock' : ' → orders denied when out of stock';
        if ($resolved) {
            $stock = (int) \StockAvailable::getQuantityAvailableByProduct($idProduct, $resolved, $idShop);
        } else {
            $stock = (int) \Product::getQuantity($idProduct, 0);
        }
        $isPack = \Pack::isPack($idProduct);
        if (!$stockMgmt) {
            $this->pass('stock', 'stock management disabled');
        } elseif ($stock >= $qtyToCheck) {
            $this->pass('stock', $stock . ' available');
        } elseif ($allowOos) {
            $this->warn('stock', sprintf('Only %d in stock for %d wanted, but out-of-stock orders are allowed (%s): the order goes through as a back-order.', $stock, $qtyToCheck, $oosRule));
        } else {
            $detail = sprintf('Stock %d, already in cart %d, wanted %d in total; out_of_stock rule: %s.', $stock, $inCart, $qtyToCheck, $oosRule);
            if ($isPack) {
                $detail .= ' Pack: ' . $this->packDetail($idProduct, $idShop, $idLang);
            }
            if ($isCheckout) {
                $sees = $v8 ? sprintf('You can only buy %d "%s". Please adjust the quantity in your cart to continue.', $stock, $name) : sprintf('The item %s in your cart is no longer available in this quantity. You cannot proceed with your order until the quantity is adjusted.', $name);
            } elseif ($v8) {
                $sees = sprintf('You can only buy %d "%s". Please adjust the quantity in your cart to continue.', $stock, $name);
            } elseif ($v177) {
                $sees = 'The product is no longer available in this quantity.';
            } else {
                $sees = sprintf('The item %s in your cart is no longer available in this quantity. You cannot proceed with your order until the quantity is adjusted.', $name);
            }
            if ($stock <= 0 && !$isCheckout) {
                $sees .= ' Product page: "Out of stock" and the add-to-cart button disabled.';
            }
            $this->fail('stock', $stage, $detail, $sees, 'Increase stock with catalog_stock_set, or allow back-orders: catalog_product_update out_of_stock=1 (shop-wide: PS_ORDER_OUT_OF_STOCK).');
        }
        if (version_compare(_PS_VERSION_, '9.0.0', '>=')) {
            $this->skip('advanced_stock', 'not in PrestaShop 9');
        } elseif (\Configuration::get('PS_ADVANCED_STOCK_MANAGEMENT') && !empty($p['advanced_stock_management'])) {
            $this->warn('advanced_stock', 'Advanced stock management is on for this product: at checkout the warehouse stock reachable by the chosen carrier is used (warehouse_carrier).');
        }

        // Minimal quantity
        $minQty = $resolved && $pa && isset($pa['minimal_quantity']) ? (int) $pa['minimal_quantity'] : (int) $ps['minimal_quantity'];
        if ($minQty > 1 && $qtyToCheck < $minQty) {
            if ($isCheckout && !$v177) {
                $this->warn('minimal_quantity', sprintf('Cart quantity %d is below the minimal quantity %d (only enforced when adding before 1.7.7).', $qtyToCheck, $minQty));
            } else {
                $this->fail('minimal_quantity', $stage, sprintf('Quantity %d is below the minimal quantity %d%s.', $qtyToCheck, $minQty, $resolved ? ' of the combination' : ''), sprintf('The minimum purchase order quantity for the product %s is %d.', $name, $minQty), 'Order at least ' . $minQty . ', or lower minimal_quantity with catalog_product_update.');
            }
        } else {
            $this->pass('minimal_quantity');
        }

        $availableDate = isset($ps['available_date']) ? (string) $ps['available_date'] : '';
        if (!$isCheckout && $availableDate && $availableDate !== '0000-00-00' && $availableDate > date('Y-m-d')) {
            $this->warn('available_date', 'available_date ' . $availableDate . ' is in the future: shown as availability date only, does not block ordering.');
        }

        $price = null;
        try {
            $price = (float) \Product::getPriceStatic($idProduct, false, $resolved ?: null, 6, null, false, true, max(1, $qtyToCheck), false, $sh['customer'] ? (int) $sh['customer']->id : null, $sh['cart'] && (int) $sh['cart']->id ? (int) $sh['cart']->id : null);
        } catch (\Throwable $e) {
            $price = null;
        }

        $refs = $this->db()->executeS('SELECT DISTINCT `id_carrier_reference` FROM `' . $P . 'product_carrier` WHERE `id_product` = ' . $idProduct . ' AND `id_shop` = ' . $idShop) ?: [];

        return [
            'id_product' => $idProduct,
            'id_product_attribute' => $resolved,
            'name' => $name,
            'combination' => $label,
            'quantity' => $qtyToCheck,
            'in_cart' => $inCart,
            'stock' => $stockMgmt ? $stock : null,
            'oos_rule' => $oosRule,
            'minimal_quantity' => $minQty,
            'unit_price_tax_excl' => $price === null ? null : round($price, 4),
            'visibility' => (string) $ps['visibility'],
            'is_virtual' => (bool) $p['is_virtual'],
            'weight_base' => (float) $p['weight'],
            'weight' => (float) $p['weight'] + ($pa ? (float) $pa['weight'] : 0.0),
            'width' => (float) $p['width'],
            'height' => (float) $p['height'],
            'depth' => (float) $p['depth'],
            'carrier_refs' => array_map('intval', array_column($refs, 'id_carrier_reference')),
        ];
    }

    private function lineFromCartRow(array $r): array
    {
        $refs = $this->db()->executeS('SELECT DISTINCT `id_carrier_reference` FROM `' . _DB_PREFIX_ . 'product_carrier` WHERE `id_product` = ' . (int) $r['id_product'] . ' AND `id_shop` = ' . (int) $this->context()->shop->id) ?: [];
        $weight = isset($r['weight_attribute']) && (float) $r['weight_attribute'] > 0 ? (float) $r['weight_attribute'] : (float) $r['weight'];

        return [
            'id_product' => (int) $r['id_product'],
            'id_product_attribute' => (int) $r['id_product_attribute'],
            'name' => $r['name'],
            'quantity' => (int) $r['cart_quantity'],
            'unit_price_tax_excl' => isset($r['price']) ? (float) $r['price'] : null,
            'is_virtual' => (bool) $r['is_virtual'],
            'weight_base' => (float) $r['weight'],
            'weight' => $weight,
            'width' => (float) $r['width'],
            'height' => (float) $r['height'],
            'depth' => (float) $r['depth'],
            'carrier_refs' => array_map('intval', array_column($refs, 'id_carrier_reference')),
        ];
    }

    private function addressChecks(array $sh): void
    {
        $cart = $sh['cart'];
        if (!$cart) {
            return;
        }
        $isVirtual = (bool) $this->withShopper($sh, function () use ($cart) {
            return $cart->isVirtualCart();
        });
        foreach (['delivery' => (int) $cart->id_address_delivery, 'invoice' => (int) $cart->id_address_invoice] as $type => $idAddress) {
            $check = $type . '_address';
            if (!$idAddress) {
                if ($type === 'delivery' && !$isVirtual) {
                    $this->warn($check, 'No delivery address on the cart yet (shopper has not reached the address step). Country used: ' . $sh['country_source'] . '.');
                }
                continue;
            }
            $a = new \Address($idAddress);
            if (!\Validate::isLoadedObject($a) || (int) $a->deleted) {
                $this->fail($check, 'checkout', sprintf('Address #%d is deleted.', $idAddress), 'The shopper must choose or create another address.', null);
                continue;
            }
            if ((int) $cart->id_customer && (int) $a->id_customer !== (int) $cart->id_customer) {
                $this->fail($check, 'checkout', sprintf('Address #%d belongs to customer #%d, not to the cart\'s customer #%d.', $idAddress, (int) $a->id_customer, (int) $cart->id_customer), null, null);
                continue;
            }
            $country = new \Country((int) $a->id_country, (int) $sh['id_lang']);
            $problems = [];
            if (!(int) $country->active) {
                $problems[] = 'country ' . $country->name . ' is inactive';
            }
            if ((int) $country->need_zip_code && $country->zip_code_format && !$country->checkZipCode($a->postcode)) {
                $problems[] = 'postcode "' . $a->postcode . '" does not match format ' . $country->zip_code_format;
            }
            if ((int) $country->need_identification_number && !trim((string) $a->dni)) {
                $problems[] = 'identification number (DNI) required but empty';
            }
            if ((int) $a->id_state && !(int) $this->db()->getValue('SELECT `active` FROM `' . _DB_PREFIX_ . 'state` WHERE `id_state` = ' . (int) $a->id_state)) {
                $problems[] = 'state #' . (int) $a->id_state . ' is inactive';
            }
            if ($problems) {
                $this->fail($check, 'checkout', sprintf('Address #%d (%s): %s.', $idAddress, $country->iso_code, implode('; ', $problems)), !(int) $country->active ? 'Carriers: "Unfortunately, there are no carriers available for your delivery address."; order validation: "The order address country is not active."' : 'Your address is incomplete, please update it.', !(int) $country->active ? 'Activate the country (International > Locations > Countries).' : 'The customer must correct the address in My account > Addresses, or edit it in the back office (Customers > Addresses).');
            } else {
                $this->pass($check);
            }
        }
    }

    /**
     * Currency, country/zone, carriers, payments, minimum purchase, cart rules.
     */
    private function checkoutChecks(array $sh, array $lines, ?int $selectedRef, string $stage, bool $useCartTotal = false): array
    {
        $P = _DB_PREFIX_;
        $out = [];

        // Currency
        $cur = new \Currency((int) $sh['id_currency']);
        $curShop = (bool) $this->db()->getValue('SELECT 1 FROM `' . $P . 'currency_shop` WHERE `id_currency` = ' . (int) $cur->id . ' AND `id_shop` = ' . (int) $sh['id_shop']);
        if (!\Validate::isLoadedObject($cur) || !(int) $cur->active || (int) $cur->deleted || !$curShop) {
            $this->warn('currency', sprintf('Currency #%d (%s) is %s; the storefront silently switches to the default currency.', (int) $sh['id_currency'], $cur->iso_code, !$curShop ? 'not associated with the shop' : 'inactive or deleted'));
        } else {
            $this->pass('currency', $cur->iso_code);
        }

        // Country / zone
        $country = $sh['country'];
        $countryShop = (bool) $this->db()->getValue('SELECT 1 FROM `' . $P . 'country_shop` WHERE `id_country` = ' . (int) $country->id . ' AND `id_shop` = ' . (int) $sh['id_shop']);
        $zone = $this->db()->getRow('SELECT `id_zone`, `name`, `active` FROM `' . $P . 'zone` WHERE `id_zone` = ' . (int) $sh['id_zone']);
        $sh['zone_name'] = $zone ? $zone['name'] : '#' . (int) $sh['id_zone'];
        if (!(int) $country->active || !$countryShop) {
            $this->fail('country', $stage, sprintf('Delivery country %s (#%d, from %s) is %s.', $country->name, (int) $country->id, $sh['country_source'], !(int) $country->active ? 'inactive' : 'not associated with the shop'), 'The country is not selectable in the address form; with an existing address: "Unfortunately, there are no carriers available for your delivery address."', 'Activate the country in International > Locations > Countries (and associate it with the shop).');
        } else {
            $this->pass('country', $country->iso_code);
        }
        if (!$zone || !(int) $zone['active']) {
            $this->fail('zone', $stage, sprintf('Zone %s of the delivery address is inactive.', $sh['zone_name']), 'Unfortunately, there are no carriers available for your delivery address.', 'Activate the zone in International > Locations > Zones.');
        }

        // Carriers
        $total = 0.0;
        $totalKnown = true;
        foreach ($lines as $l) {
            if ($l['unit_price_tax_excl'] === null) {
                $totalKnown = false;
                continue;
            }
            $total += (float) $l['unit_price_tax_excl'] * (int) $l['quantity'];
        }
        if ($useCartTotal && $sh['cart'] && (int) $sh['cart']->id) {
            $cart = $sh['cart'];
            $t = $this->withShopper($sh, function () use ($cart) {
                return (float) $cart->getOrderTotal(false, \Cart::ONLY_PRODUCTS);
            });
            $total = $t;
            $totalKnown = true;
        }
        $out['products_total_tax_excl'] = $totalKnown ? $total : null;
        $carriers = $this->evaluateCarriers($sh, $lines, $total, $stage);
        $out['carriers'] = $carriers;

        // Payments: the stored carrier only counts if it is still offered.
        if ($selectedRef && !empty($carriers['available'])) {
            $refs = array_map(function ($a) {
                return (int) $a['id_reference'];
            }, $carriers['available']);
            if (!in_array((int) $selectedRef, $refs, true)) {
                $selectedRef = null;
            }
        }
        $this->evaluatePayments($sh, $carriers, $selectedRef, $stage);

        // Minimum purchase
        $min = (float) $this->cfg($sh, 'PS_PURCHASE_MINIMUM');
        if ($min > 0) {
            $minConv = (float) \Tools::convertPrice($min, $cur);
            if (!$totalKnown) {
                $this->warn('minimum_purchase', 'Minimum purchase ' . $minConv . ' ' . $cur->iso_code . ' (tax excl.) could not be compared: product price unknown.');
            } elseif ($total < $minConv) {
                $fmtMin = $this->money($minConv, (int) $cur->id)['formatted'];
                $fmtTot = $this->money($total, (int) $cur->id)['formatted'];
                $this->fail('minimum_purchase', 'checkout', sprintf('Products total %s (tax excl., before vouchers) is below PS_PURCHASE_MINIMUM %s.%s', $fmtTot, $fmtMin, $this->hookModules('overrideMinimalPurchasePrice', (int) $sh['id_shop']) ? ' Modules on overrideMinimalPurchasePrice may change the minimum: ' . implode(', ', $this->hookModules('overrideMinimalPurchasePrice', (int) $sh['id_shop'])) . '.' : ''), sprintf('A minimum shopping cart total of %s (tax excl.) is required to validate your order. Current cart total is %s (tax excl.).', $fmtMin, $fmtTot), 'Add products, or lower the minimum in Shop Parameters > Order Settings (PS_PURCHASE_MINIMUM).');
            } else {
                $this->pass('minimum_purchase');
            }
        } else {
            $this->pass('minimum_purchase', 'none');
        }

        // Cart rules
        if ($sh['cart'] && (int) $sh['cart']->id) {
            $this->cartRuleChecks($sh);
        }

        return $out;
    }

    private function cartRuleChecks(array $sh): void
    {
        if (!\CartRule::isFeatureActive()) {
            return;
        }
        $cart = $sh['cart'];
        $ids = $this->db()->executeS('SELECT `id_cart_rule` FROM `' . _DB_PREFIX_ . 'cart_cart_rule` WHERE `id_cart` = ' . (int) $cart->id) ?: [];
        if (!$ids) {
            return;
        }
        $this->withShopper($sh, function () use ($ids, $sh) {
            foreach ($ids as $r) {
                $rule = new \CartRule((int) $r['id_cart_rule'], (int) $sh['id_lang']);
                if (!\Validate::isLoadedObject($rule)) {
                    $this->warn('cart_rule', sprintf('Cart rule #%d in the cart no longer exists.', (int) $r['id_cart_rule']));
                    continue;
                }
                try {
                    $res = $rule->checkValidity($this->context(), true, true, true);
                } catch (\Throwable $e) {
                    $res = 'could not be evaluated: ' . $e->getMessage();
                }
                if ($res !== true && $res !== null) {
                    $this->warn('cart_rule', sprintf('Cart rule "%s" (#%d, code %s) is not valid: %s', $rule->name, (int) $rule->id, $rule->code ?: '-', is_string($res) ? $res : 'invalid'), sprintf('The cart rule named "%s" (ID %d) used in this cart is not valid and has been withdrawn from cart', $rule->name, (int) $rule->id), 'Check its conditions in Catalog > Discounts (dates, quantity, minimum amount, customer/group/country/carrier restrictions). Invalid vouchers do not block the order; they are removed.');
                } elseif (!(int) $rule->active || (int) $rule->quantity < 1 || strtotime($rule->date_to) < time()) {
                    $this->warn('cart_rule', sprintf('Cart rule "%s" (#%d) is %s, but core keeps a voucher that is already in the cart (it would be refused if entered again).', $rule->name, (int) $rule->id, !(int) $rule->active ? 'disabled' : ((int) $rule->quantity < 1 ? 'used up' : 'expired since ' . $rule->date_to)));
                } else {
                    $this->pass('cart_rule', '#' . (int) $rule->id);
                }
            }
        });
    }

    /**
     * Carrier::getAvailableCarrierList + getCarriersForOrder replayed with reasons.
     */
    private function evaluateCarriers(array $sh, array $lines, float $productsTotal, string $stage): array
    {
        $P = _DB_PREFIX_;
        $idShop = (int) $sh['id_shop'];
        $idZone = (int) $sh['id_zone'];
        $physical = array_values(array_filter($lines, function ($l) {
            return !$l['is_virtual'];
        }));
        if (!$physical) {
            $this->pass('carriers', 'virtual products only, no delivery');

            return ['virtual' => true, 'available' => []];
        }
        $groups = $sh['checkout_groups'];
        $cartWeight = 0.0;
        foreach ($lines as $l) {
            $cartWeight += $l['weight'] * $l['quantity'];
        }
        $rows = $this->db()->executeS(
            'SELECT c.`id_carrier`, c.`id_reference`, c.`name`, c.`active`, c.`is_module`, c.`need_range`, c.`external_module_name`,
                    c.`range_behavior`, c.`max_width`, c.`max_height`, c.`max_depth`, c.`max_weight`, c.`is_free`,
                    (SELECT 1 FROM `' . $P . 'carrier_shop` cs WHERE cs.`id_carrier` = c.`id_carrier` AND cs.`id_shop` = ' . $idShop . ' LIMIT 1) AS in_shop,
                    (SELECT 1 FROM `' . $P . 'carrier_zone` cz WHERE cz.`id_carrier` = c.`id_carrier` AND cz.`id_zone` = ' . $idZone . ' LIMIT 1) AS in_zone,
                    (SELECT GROUP_CONCAT(cg.`id_group`) FROM `' . $P . 'carrier_group` cg WHERE cg.`id_carrier` = c.`id_carrier`) AS grps
             FROM `' . $P . 'carrier` c WHERE c.`deleted` = 0 ORDER BY c.`position`'
        ) ?: [];
        $sorted = version_compare(_PS_VERSION_, '1.7.8.0', '<');
        $total = $productsTotal;
        $available = [];
        $excluded = [];
        $inactive = [];
        $perLine = [];
        foreach ($physical as $i => $l) {
            $perLine[$i] = [];
        }
        $cur = new \Currency((int) $sh['id_currency']);
        foreach ($rows as $c) {
            $id = (int) $c['id_carrier'];
            $label = trim($c['name']) . ' #' . $id;
            if (!(int) $c['active']) {
                $inactive[] = $label;
                continue;
            }
            $reasons = [];
            if (!$c['in_shop']) {
                $reasons[] = 'not associated with shop #' . $idShop;
            }
            if (!$c['in_zone']) {
                $reasons[] = 'does not deliver to zone ' . (isset($sh['zone_name']) ? $sh['zone_name'] : '#' . $idZone) . ' (carrier_zone)';
            }
            $cg = $c['grps'] !== null && $c['grps'] !== '' ? array_map('intval', explode(',', $c['grps'])) : [];
            if (!array_intersect($cg, $groups)) {
                $reasons[] = 'not allowed for groups [' . implode(',', $groups) . '] (carrier groups: ' . ($cg ? implode(',', $cg) : 'none') . ')';
            }
            if ((int) $c['is_module']) {
                if (!(int) $c['need_range']) {
                    $reasons[] = 'module carrier with need_range=0 (ignored by Carrier::getCarriersForOrder)';
                }
                $mod = (string) $c['external_module_name'];
                if ($mod !== '' && !$this->moduleActiveInShop($mod, $idShop)) {
                    $reasons[] = 'carrier module "' . $mod . '" is not installed/enabled in this shop';
                }
            }
            if ($c['in_zone']) {
                $carrier = new \Carrier($id);
                $method = (int) $carrier->getShippingMethod();
                if ($method === \Carrier::SHIPPING_METHOD_WEIGHT) {
                    if ($carrier->getMaxDeliveryPriceByWeight($idZone) === false) {
                        $reasons[] = 'no weight range priced for this zone';
                    } elseif ((int) $c['range_behavior'] && \Carrier::checkDeliveryPriceByWeight($id, $cartWeight, $idZone) === false) {
                        $reasons[] = sprintf('cart weight %s kg outside its weight ranges %s and out-of-range behaviour = disable', round($cartWeight, 3), $this->rangeSpan('range_weight', $id));
                    }
                } elseif ($method === \Carrier::SHIPPING_METHOD_PRICE) {
                    if ($carrier->getMaxDeliveryPriceByPrice($idZone) === false) {
                        $reasons[] = 'no price range priced for this zone';
                    } elseif ((int) $c['range_behavior'] && \Carrier::checkDeliveryPriceByPrice($id, $total, $idZone, (int) $cur->id) === false) {
                        $reasons[] = sprintf('cart total %s outside its price ranges %s and out-of-range behaviour = disable', round($total, 2), $this->rangeSpan('range_price', $id));
                    }
                }
            }
            $lineReasons = [];
            foreach ($physical as $i => $l) {
                $lr = [];
                if ($l['carrier_refs'] && !in_array((int) $c['id_reference'], $l['carrier_refs'], true)) {
                    $lr[] = 'product restricted to other carriers (product_carrier)';
                }
                $cs = [(int) $c['max_width'], (int) $c['max_height'], (int) $c['max_depth']];
                $psz = [(int) $l['width'], (int) $l['height'], (int) $l['depth']];
                if ($sorted) {
                    rsort($cs, SORT_NUMERIC);
                    rsort($psz, SORT_NUMERIC);
                }
                for ($k = 0; $k < 3; ++$k) {
                    if ($cs[$k] > 0 && $cs[$k] < $psz[$k]) {
                        $lr[] = sprintf('too large (product %dx%dx%d, carrier max %dx%dx%d)', (int) $l['width'], (int) $l['height'], (int) $l['depth'], (int) $c['max_width'], (int) $c['max_height'], (int) $c['max_depth']);
                        break;
                    }
                }
                if ((float) $c['max_weight'] > 0 && ((float) $c['max_weight'] < $l['weight_base'] * $l['quantity'] || (float) $c['max_weight'] < $cartWeight)) {
                    $lr[] = sprintf('too heavy (%s kg for this product, %s kg cart; carrier max %s kg)', round($l['weight_base'] * $l['quantity'], 3), round($cartWeight, 3), (float) $c['max_weight']);
                }
                if ($lr) {
                    $lineReasons[] = $l['name'] . ': ' . implode(', ', $lr);
                } elseif (!$reasons) {
                    $perLine[$i][] = $id;
                }
            }
            if (!$reasons && !$lineReasons) {
                $available[$id] = ['id_carrier' => $id, 'id_reference' => (int) $c['id_reference'], 'name' => trim($c['name']), 'module' => (int) $c['is_module'] ? $c['external_module_name'] : null];
            } else {
                $excluded[] = ['id_carrier' => $id, 'name' => trim($c['name']), 'reasons' => array_merge($reasons, $lineReasons)];
            }
        }

        $noCarrier = [];
        foreach ($physical as $i => $l) {
            if (!$perLine[$i]) {
                $noCarrier[] = $l['name'];
            }
        }
        $result = ['available' => $available, 'excluded' => $excluded, 'inactive' => $inactive, 'zone' => isset($sh['zone_name']) ? $sh['zone_name'] : $idZone, 'groups' => $groups, 'cart_weight_kg' => round($cartWeight, 3)];
        $summary = ['zone' => $result['zone'], 'groups' => $groups, 'available' => array_values(array_map(function ($a) {
            return $a['name'] . ' #' . $a['id_carrier'];
        }, $available)), 'excluded' => $excluded];
        if ($inactive) {
            $summary['inactive_count'] = count($inactive);
        }
        if ($noCarrier) {
            $this->fail('carriers', $stage, 'No carrier can deliver ' . implode(', ', $noCarrier) . ' to ' . $sh['country']->name . ' (zone ' . $result['zone'] . '). ' . $this->compactExcluded($excluded), 'Unfortunately, there are no carriers available for your delivery address.', 'Fix the reasons listed for the carrier that should deliver (Shipping > Carriers: zones, groups, ranges, size/weight limits; product page Shipping tab for product carrier restrictions).');
            $this->report['carriers'] = $summary;
        } elseif (!$available) {
            $this->warn('carriers', 'No single carrier takes the whole cart: the order is split into several shipments. ' . $this->compactExcluded($excluded));
            $this->report['carriers'] = $summary;
        } else {
            $mods = array_filter(array_map(function ($a) {
                return $a['module'];
            }, $available));
            $this->pass('carriers', implode(', ', $summary['available']) . ($mods ? '; module carriers may still refuse via their shipping cost calculation' : ''));
            $this->report['carriers'] = $summary;
        }

        return $result;
    }

    /**
     * Hook::getHookModuleExecList('paymentOptions') in front-office mode, with reasons.
     */
    private function evaluatePayments(array $sh, array $carriers, ?int $selectedRef, string $stage): void
    {
        $P = _DB_PREFIX_;
        $idShop = (int) $sh['id_shop'];
        $idHook = (int) \Hook::getIdByName('paymentOptions');
        $pre9 = version_compare(_PS_VERSION_, '9.0.0', '<');
        $mods = $this->db()->executeS(
            'SELECT m.`id_module`, m.`name`, m.`active`, ms.`id_module` AS in_shop' . ($this->hasColumn('module_shop', 'enable_device') ? ', ms.`enable_device`' : ', 7 AS enable_device') . '
             FROM `' . $P . 'hook_module` hm
             JOIN `' . $P . 'module` m ON m.`id_module` = hm.`id_module`
             LEFT JOIN `' . $P . 'module_shop` ms ON ms.`id_module` = m.`id_module` AND ms.`id_shop` = ' . $idShop . '
             WHERE hm.`id_hook` = ' . $idHook . ' AND hm.`id_shop` = ' . $idShop . '
             GROUP BY m.`id_module` ORDER BY MIN(hm.`position`)'
        ) ?: [];
        $country = $sh['country'];
        $cur = new \Currency((int) $sh['id_currency']);
        $groups = $sh['hook_groups'];
        $isVirtual = !empty($carriers['virtual']);
        $availableRefs = [];
        if (!$isVirtual && !empty($carriers['available'])) {
            foreach ($carriers['available'] as $a) {
                $availableRefs[$a['id_reference']] = $a['name'];
            }
        }
        $ok = [];
        $excluded = [];
        foreach ($mods as $m) {
            $idm = (int) $m['id_module'];
            $r = [];
            if (!(int) $m['active']) {
                $r[] = 'module disabled';
            }
            if (!$m['in_shop']) {
                $r[] = 'module not enabled in shop #' . $idShop;
            }
            if (!is_file(_PS_MODULE_DIR_ . $m['name'] . '/' . $m['name'] . '.php')) {
                $r[] = 'module files missing';
            }
            if (!$this->db()->getValue('SELECT 1 FROM `' . $P . 'module_country` WHERE `id_module` = ' . $idm . ' AND `id_shop` = ' . $idShop . ' AND `id_country` = ' . (int) $country->id)) {
                $r[] = 'not allowed for country ' . $country->iso_code . ' (Payment > Preferences, module_country)';
            }
            if (!$this->db()->getValue('SELECT 1 FROM `' . $P . 'module_currency` WHERE `id_module` = ' . $idm . ' AND `id_currency` IN (' . (int) $cur->id . ', -1, -2)')) {
                $r[] = 'not allowed for currency ' . $cur->iso_code . ' (module_currency)';
            }
            if (\Group::isFeatureActive() && !$this->db()->getValue('SELECT 1 FROM `' . $P . 'module_group` WHERE `id_module` = ' . $idm . ' AND `id_shop` = ' . $idShop . ' AND `id_group` IN (' . implode(',', array_map('intval', $groups)) . ')')) {
                $r[] = 'not allowed for groups [' . implode(',', $groups) . '] (module_group)';
            }
            $note = null;
            if (!$isVirtual) {
                $refs = array_map('intval', array_column($this->db()->executeS('SELECT `id_reference` FROM `' . $P . 'module_carrier` WHERE `id_module` = ' . $idm . ' AND `id_shop` = ' . $idShop) ?: [], 'id_reference'));
                if ($selectedRef) {
                    if (!in_array($selectedRef, $refs, true)) {
                        $r[] = 'not allowed for the selected carrier (reference ' . $selectedRef . ', module_carrier)';
                    }
                } elseif ($availableRefs) {
                    $allowed = array_intersect_key($availableRefs, array_flip($refs));
                    if (!$allowed) {
                        $r[] = 'not allowed for any available carrier (module_carrier)';
                    } elseif (count($allowed) < count($availableRefs)) {
                        $note = 'only with carriers: ' . implode(', ', $allowed);
                    }
                }
            }
            if ($idHook && $this->db()->getValue('SELECT 1 FROM `' . $P . 'hook_module_exceptions` WHERE `id_module` = ' . $idm . ' AND `id_hook` = ' . $idHook . ' AND `id_shop` = ' . $idShop . ' AND `file_name` = \'order\'')) {
                $r[] = 'hook exception for the "order" page';
            }
            if ($pre9 && !$r && ((int) $m['enable_device'] & 7) !== 7) {
                $dev = [];
                foreach ([1 => 'desktop', 2 => 'tablet', 4 => 'mobile'] as $bit => $n) {
                    if ((int) $m['enable_device'] & $bit) {
                        $dev[] = $n;
                    }
                }
                $note = trim(($note ? $note . '; ' : '') . 'only on devices: ' . ($dev ? implode(', ', $dev) : 'none'));
            }
            if ($r) {
                $excluded[] = ['module' => $m['name'], 'reasons' => $r];
            } else {
                $ok[] = $m['name'] . ($note ? ' (' . $note . ')' : '');
            }
        }
        if (!$pre9) {
            $this->skip('payment_device_filter', 'module_shop.enable_device not used by PrestaShop 9');
        }
        $this->report['payments'] = ['country' => $country->iso_code, 'currency' => $cur->iso_code, 'groups' => $groups, 'available' => $ok, 'excluded' => $excluded];
        if (!$ok) {
            $this->fail('payment_methods', $stage, $mods ? 'No payment module passes the restrictions for ' . $country->iso_code . '/' . $cur->iso_code . '/groups [' . implode(',', $groups) . ']: ' . implode('; ', array_map(function ($e) {
                return $e['module'] . ': ' . implode(', ', $e['reasons']);
            }, $excluded)) : 'No module is hooked on paymentOptions in this shop.', 'Unfortunately, there are no payment method available.', 'Payment > Preferences: allow the country/currency/group/carrier for a payment module (module_tools may also show them).');
        } else {
            $this->pass('payment_methods', implode(', ', $ok) . '; each module can still hide itself in hookPaymentOptions (amount limits, own settings)');
        }
    }

    /**
     * Ask core which payment modules Hook::getHookModuleExecList('paymentOptions')
     * returns for the shopper, with an in-memory copy of the cart carrying $idCarrier.
     * Only possible for visitors/guests: a registered customer needs a live session
     * (Customer::isLogged) for core to use their groups.
     *
     * @return array|string
     */
    private function corePaymentModules(array $sh, int $idCarrier)
    {
        $c = $sh['customer'];
        if ($c && !(int) $c->is_guest) {
            return 'not run: core uses a registered customer\'s groups only with a live login session';
        }
        try {
            return $this->withShopper($sh, function () use ($idCarrier, $c) {
                $ctx = $this->context();
                if (!$c) {
                    $guest = new \Customer();
                    $guest->is_guest = 1;
                    $ctx->customer = $guest;
                }
                $cart = clone $ctx->cart;
                $cart->id_carrier = $idCarrier;
                if (!(int) $cart->id) {
                    // Hook applies module_carrier only to a loaded cart; this copy is never saved.
                    $cart->id = 2147483647;
                }
                $ctx->cart = $cart;
                $list = \Hook::getHookModuleExecList('paymentOptions');
                $names = [];
                foreach ((array) $list as $row) {
                    if (isset($row['module'])) {
                        $names[] = $row['module'];
                    }
                }

                return ['id_carrier' => $idCarrier, 'modules' => $names];
            });
        } catch (\Throwable $e) {
            return 'error: ' . $e->getMessage();
        }
    }

    private function addCorePaymentCheck(array &$context, array $sh, array $result, int $idCarrier): void
    {
        if (!empty($result['carriers']['virtual'])) {
            $idCarrier = 0;
        } elseif (!$idCarrier || empty($result['carriers']['available'][$idCarrier])) {
            if (empty($result['carriers']['available'])) {
                return;
            }
            $first = reset($result['carriers']['available']);
            $idCarrier = (int) $first['id_carrier'];
        }
        $context['core_payment_modules'] = $this->corePaymentModules($sh, $idCarrier);
    }

    private function coreDeliveryOptions(array $sh): array
    {
        $cart = $sh['cart'];
        $this->coreCarrierIds = [];
        try {
            return $this->withShopper($sh, function () use ($cart) {
                \Cache::clean('Carrier::*');
                $list = $cart->getDeliveryOptionList(null, true);
                $out = [];
                foreach ((array) $list as $idAddress => $options) {
                    foreach ((array) $options as $key => $opt) {
                        $names = [];
                        if (isset($opt['carrier_list'])) {
                            foreach ($opt['carrier_list'] as $idCarrier => $cl) {
                                $names[] = (isset($cl['instance']) && is_object($cl['instance']) ? trim($cl['instance']->name) : 'carrier') . ' #' . (int) $idCarrier;
                                $this->coreCarrierIds[(int) $idCarrier] = true;
                            }
                        }
                        $out[] = ['id_address' => (int) $idAddress, 'carriers' => implode(' + ', $names), 'price_tax_incl' => isset($opt['total_price_with_tax']) ? round((float) $opt['total_price_with_tax'], 2) : null];
                    }
                }

                return $out ?: ['none: core offers no delivery option for this cart'];
            });
        } catch (\Throwable $e) {
            $this->coreCarrierIds = null;

            return ['error: ' . $e->getMessage()];
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function resetReport(): void
    {
        $this->report = ['blocking' => [], 'warnings' => [], 'passed' => [], 'skipped' => []];
        $this->item = null;
    }

    private function entry(string $check, string $status, string $detail, ?string $sees, ?string $fix): array
    {
        $e = ['check' => $check, 'status' => $status];
        if ($this->item !== null) {
            $e['item'] = $this->item;
        }
        $e['detail'] = $detail;
        if ($sees !== null && $sees !== '') {
            $e['customer_sees'] = $sees;
        }
        if ($fix !== null && $fix !== '') {
            $e['fix'] = $fix;
        }

        return $e;
    }

    private function fail(string $check, string $blocks, string $detail, ?string $sees = null, ?string $fix = null): void
    {
        $e = $this->entry($check, 'fail', $detail, $sees, $fix);
        $e['blocks'] = $blocks;
        $this->report['blocking'][] = $e;
    }

    private function warn(string $check, string $detail, ?string $sees = null, ?string $fix = null): void
    {
        $this->report['warnings'][] = $this->entry($check, 'warn', $detail, $sees, $fix);
    }

    private function pass(string $check, ?string $note = null): void
    {
        if ($this->item !== null) {
            $check .= '[' . strtok($this->item, ' ') . ']';
        }
        $this->report['passed'][] = $note ? $check . ' (' . $note . ')' : $check;
    }

    private function skip(string $check, string $why): void
    {
        $this->report['skipped'][$check] = $why;
    }

    private function output(string $verdict, array $context): array
    {
        $out = ['verdict' => $verdict, 'blocking' => $this->report['blocking'], 'warnings' => $this->report['warnings'], 'passed' => $this->report['passed']];
        if ($this->report['skipped']) {
            $out['skipped'] = $this->report['skipped'];
        }
        if (isset($this->report['carriers'])) {
            $out['carriers'] = $this->report['carriers'];
        }
        if (isset($this->report['payments'])) {
            $out['payments'] = $this->report['payments'];
        }
        $out['context'] = $context;

        return $out;
    }

    private function contextBlock(array $sh): array
    {
        $c = $sh['customer'];
        $out = [
            'prestashop_version' => _PS_VERSION_,
            'id_shop' => $sh['id_shop'],
            'customer' => $c ? ['id_customer' => (int) $c->id, 'email' => $c->email, 'name' => trim($c->firstname . ' ' . $c->lastname), 'is_guest' => (bool) $c->is_guest, 'active' => (bool) $c->active] : 'visitor (not logged in)',
            'groups' => ['default' => $sh['default_group'], 'effective' => $sh['current_group'], 'access' => $sh['access_groups'], 'checkout' => $sh['checkout_groups'], 'payment' => $sh['hook_groups']],
            'country' => ['id_country' => (int) $sh['id_country'], 'iso' => $sh['country']->iso_code, 'name' => $sh['country']->name, 'source' => $sh['country_source'], 'id_zone' => (int) $sh['id_zone']],
            'currency' => (string) (new \Currency((int) $sh['id_currency']))->iso_code,
        ];
        $hooked = [];
        foreach (self::CART_HOOKS as $h) {
            $m = $this->hookModules($h, (int) $sh['id_shop']);
            if ($m) {
                $hooked[$h] = $m;
            }
        }
        if ($hooked) {
            $out['modules_on_cart_hooks'] = $hooked;
        }
        $ov = $this->overrides();
        if ($ov) {
            $out['core_overrides'] = $ov;
        }
        if ($sh['notes']) {
            $out['notes'] = $sh['notes'];
        }

        return $out;
    }

    /**
     * @return mixed
     */
    private function cfg(array $sh, string $key)
    {
        return \Configuration::get($key, null, (int) $sh['id_shop_group'], (int) $sh['id_shop']);
    }

    private function productName(int $idProduct, int $idLang, int $idShop): string
    {
        $name = $this->db()->getValue('SELECT `name` FROM `' . _DB_PREFIX_ . 'product_lang` WHERE `id_product` = ' . $idProduct . ' AND `id_lang` = ' . $idLang . ' AND `id_shop` = ' . $idShop);
        if ($name === false || $name === null) {
            $name = $this->db()->getValue('SELECT `name` FROM `' . _DB_PREFIX_ . 'product_lang` WHERE `id_product` = ' . $idProduct);
        }

        return (string) $name;
    }

    private function productUrl(int $idProduct, int $idLang, int $idShop): ?string
    {
        try {
            $link = $this->context()->link ?: new \Link();

            return $link->getProductLink($idProduct, null, null, null, $idLang, $idShop);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function combinationLabel(int $ipa, int $idLang): string
    {
        $rows = $this->db()->executeS(
            'SELECT agl.`name` AS grp, al.`name` AS val FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
             JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.`id_attribute` = pac.`id_attribute`
             LEFT JOIN `' . _DB_PREFIX_ . 'attribute_lang` al ON al.`id_attribute` = a.`id_attribute` AND al.`id_lang` = ' . $idLang . '
             LEFT JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl ON agl.`id_attribute_group` = a.`id_attribute_group` AND agl.`id_lang` = ' . $idLang . '
             WHERE pac.`id_product_attribute` = ' . $ipa . ' ORDER BY a.`id_attribute_group`'
        ) ?: [];

        return implode(', ', array_map(function ($r) {
            return $r['grp'] . ': ' . $r['val'];
        }, $rows)) ?: '#' . $ipa;
    }

    private function packDetail(int $idProduct, int $idShop, int $idLang): string
    {
        $type = (int) $this->db()->getValue('SELECT `pack_stock_type` FROM `' . _DB_PREFIX_ . 'product` WHERE `id_product` = ' . $idProduct);
        if ($type === 3) {
            $type = (int) \Configuration::get('PS_PACK_STOCK_TYPE');
        }
        $types = [0 => 'pack quantity only', 1 => 'items\' quantity only', 2 => 'both pack and items'];
        $items = $this->db()->executeS('SELECT `id_product_item`, `id_product_attribute_item`, `quantity` FROM `' . _DB_PREFIX_ . 'pack` WHERE `id_product_pack` = ' . $idProduct) ?: [];
        $parts = [];
        foreach ($items as $it) {
            $s = (int) \StockAvailable::getQuantityAvailableByProduct((int) $it['id_product_item'], (int) $it['id_product_attribute_item'], $idShop);
            $parts[] = sprintf('#%d %s: stock %d / %d per pack', (int) $it['id_product_item'], $this->productName((int) $it['id_product_item'], $idLang, $idShop), $s, (int) $it['quantity']);
        }

        return 'stock type: ' . (isset($types[$type]) ? $types[$type] : $type) . '; items: ' . implode('; ', $parts);
    }

    private function describeRedirect(string $type, int $target): string
    {
        $t = $target ? ' #' . $target : ' (no target → 404)';
        switch ($type) {
            case '301-product':
            case '302-product':
                return 'HTTP ' . substr($type, 0, 3) . ' redirect to product' . $t;
            case '301-category':
            case '302-category':
                return 'HTTP ' . substr($type, 0, 3) . ' redirect to category' . ($target ? ' #' . $target : ' (default category)');
            case '410':
                return 'HTTP 410 Gone page';
            case '200-displayed':
                return 'page still displayed (HTTP 200) with the product shown as unavailable';
            case '404-displayed':
                return 'page displayed with HTTP 404';
            case '410-displayed':
                return 'page displayed with HTTP 410';
            case 'default':
            case '':
                return 'shop default redirection (PS_PRODUCT_REDIRECTION_DEFAULT, else 404)';
            default:
                return 'HTTP 404 page not found';
        }
    }

    private function compactCats(array $cats): string
    {
        return implode('; ', array_map(function ($c) {
            return '#' . $c['id_category'] . ' ' . $c['name'] . ($c['problems'] ? ': ' . implode(', ', $c['problems']) : '');
        }, $cats));
    }

    private function compactExcluded(array $excluded): string
    {
        if (!$excluded) {
            return 'No active carrier exists.';
        }
        $parts = [];
        foreach (array_slice($excluded, 0, 8) as $e) {
            $parts[] = $e['name'] . ' #' . $e['id_carrier'] . ': ' . implode('; ', $e['reasons']);
        }

        return 'Excluded: ' . implode(' | ', $parts) . (count($excluded) > 8 ? ' | …' : '');
    }

    private function rangeSpan(string $table, int $idCarrier): string
    {
        $r = $this->db()->getRow('SELECT MIN(`delimiter1`) AS a, MAX(`delimiter2`) AS b FROM `' . _DB_PREFIX_ . bqSQL($table) . '` WHERE `id_carrier` = ' . $idCarrier);

        return $r && $r['a'] !== null ? '[' . (float) $r['a'] . ' – ' . (float) $r['b'] . ')' : '(none)';
    }

    private function moduleActiveInShop(string $name, int $idShop): bool
    {
        return (bool) $this->db()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'module` m JOIN `' . _DB_PREFIX_ . 'module_shop` ms ON ms.`id_module` = m.`id_module` AND ms.`id_shop` = ' . $idShop . '
             WHERE m.`name` = \'' . pSQL($name) . '\' AND m.`active` = 1'
        );
    }

    /**
     * @return string[] module names hooked on $hook in the shop
     */
    private function hookModules(string $hook, int $idShop): array
    {
        static $cache = [];
        $k = $hook . '|' . $idShop;
        if (!isset($cache[$k])) {
            $rows = $this->db()->executeS(
                'SELECT DISTINCT m.`name` FROM `' . _DB_PREFIX_ . 'hook_module` hm
                 JOIN `' . _DB_PREFIX_ . 'hook` h ON h.`id_hook` = hm.`id_hook`
                 JOIN `' . _DB_PREFIX_ . 'module` m ON m.`id_module` = hm.`id_module` AND m.`active` = 1
                 WHERE h.`name` = \'' . pSQL($hook) . '\' AND hm.`id_shop` = ' . $idShop
            ) ?: [];
            $cache[$k] = array_column($rows, 'name');
        }

        return $cache[$k];
    }

    /**
     * Override files that change cart/checkout behaviour.
     */
    private function overrides(): array
    {
        if (\Configuration::get('PS_DISABLE_OVERRIDES')) {
            return [];
        }
        $out = [];
        foreach (['classes/Cart.php', 'classes/Product.php', 'classes/Carrier.php', 'classes/CartRule.php', 'classes/Hook.php', 'classes/stock/StockAvailable.php', 'controllers/front/CartController.php', 'controllers/front/OrderController.php', 'controllers/front/ProductController.php'] as $f) {
            if (is_file(_PS_ROOT_DIR_ . '/override/' . $f)) {
                $out[] = 'override/' . $f;
            }
        }

        return $out;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $k = $table . '.' . $column;
        if (!isset($this->columnCache[$k])) {
            $this->columnCache[$k] = (bool) $this->db()->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . bqSQL($table) . '` LIKE \'' . pSQL($column) . '\'');
        }

        return $this->columnCache[$k];
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->db()->executeS('SHOW TABLES LIKE \'' . pSQL(_DB_PREFIX_ . $table) . '\'');
    }
}
