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
 * Orders: search, details, statuses, tracking numbers, notes/messages and
 * sales statistics.
 */
class OrderTools extends AbstractToolGroup
{
    public function owner(): string
    {
        return 'order';
    }

    public function tools(): array
    {
        return [
            [
                'name' => 'order_search',
                'title' => 'Search orders',
                'description' => 'Finds orders of the shop, newest first. Filters: free text (reference, id, customer email or name), reference, id_order, customer (id, email, name), current status (one id or a list, see order_status_list), payment module, carrier, date range, total paid range, ordered product. Returns compact rows (id, reference, date, customer, total paid tax incl, status, payment, carrier). Use order_get for the full order.',
                'params' => array_merge([
                    'query' => ['type' => 'string', 'description' => 'Free text: order reference, order id, customer email or name.'],
                    'reference' => ['type' => 'string', 'description' => 'Exact order reference (e.g. "XKBKNABJK").'],
                    'id_order' => ['type' => 'integer', 'minimum' => 1],
                    'id_customer' => ['type' => 'integer', 'minimum' => 1],
                    'customer_email' => ['type' => 'string', 'description' => 'Customer email (partial match).'],
                    'customer_name' => ['type' => 'string', 'description' => 'Customer first/last name (partial match).'],
                    'status' => ['type' => ['integer', 'array'], 'items' => ['type' => 'integer'], 'description' => 'Current order status id, or a list of ids.'],
                    'payment_module' => ['type' => 'string', 'description' => 'Payment module technical name (e.g. "ps_wirepayment") or payment label (partial match).'],
                    'id_carrier' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Carrier id (any version of the carrier with the same reference).'],
                    'date_from' => ['type' => 'string', 'description' => 'Order date from, YYYY-MM-DD[ HH:MM:SS].'],
                    'date_to' => ['type' => 'string', 'description' => 'Order date to (inclusive), YYYY-MM-DD[ HH:MM:SS].'],
                    'total_min' => ['type' => 'number', 'description' => 'Minimum total paid tax incl (order currency).'],
                    'total_max' => ['type' => 'number', 'description' => 'Maximum total paid tax incl (order currency).'],
                    'id_product' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Only orders containing this product.'],
                    'valid_only' => self::boolParam('Only valid orders (status counted as validated/paid).', false),
                    'order_by' => ['type' => 'string', 'enum' => ['date_add', 'id_order', 'total_paid'], 'default' => 'date_add'],
                    'order_way' => ['type' => 'string', 'enum' => ['desc', 'asc'], 'default' => 'desc'],
                ], self::paginationParams(20, 100)),
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminOrders', 'view'],
                'keywords' => ['purchase', 'sale', 'find order', 'reference'],
                'handler' => 'orderSearch',
            ],
            [
                'name' => 'order_get',
                'title' => 'Get order details',
                'description' => 'Full order: customer, delivery/invoice addresses, products (name, reference, quantity, unit prices tax incl/excl, refunded/returned quantity), totals (products, shipping, discounts, wrapping, paid), applied cart rules, payments, carrier and tracking number, status history with employee, messages (customer thread + private), private notes, invoice and credit slip numbers, cart id and shop. Find the id with order_search.',
                'params' => [
                    'id_order' => self::idParam('Order ID (order_search finds it). Or pass reference instead.', false),
                    'reference' => ['type' => 'string', 'description' => 'Order reference; if several orders share it (split order) all ids are listed in "same_reference_orders".'],
                ],
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminOrders', 'view'],
                'keywords' => ['order detail', 'invoice', 'tracking', 'payment', 'history'],
                'handler' => 'orderGet',
            ],
            [
                'name' => 'order_status_list',
                'title' => 'List order statuses',
                'description' => 'All order statuses (Orders > Statuses) with id, name in the requested language and flags: paid, shipped, delivered, logable (counted as valid sale), invoice, send_email + template, hidden, color, module. Use the ids for order_search "status" and order_status_set.',
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminOrders', 'view'],
                'keywords' => ['order state', 'statuses'],
                'handler' => 'statusList',
            ],
            [
                'name' => 'order_status_set',
                'title' => 'Change order status',
                'description' => 'Changes the current status of an order exactly like the back office (adds an order history row, runs stock/payment/invoice side effects and hooks of the new status, optionally sends the status email). Refuses to set the status the order already has. Run with dry_run=true first to see before/after. A status change cannot be undone, only followed by another change.',
                'params' => [
                    'id_order' => self::idParam('Order ID.'),
                    'id_order_state' => self::idParam('New status id (order_status_list).'),
                    'send_email' => self::boolParam('true = send the customer email if the new status is configured to send one; false = never email.', true),
                ],
                'write' => true,
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminOrders', 'edit'],
                'keywords' => ['order state', 'mark as shipped', 'mark as paid', 'cancel order'],
                'handler' => 'statusSet',
            ],
            [
                'name' => 'order_tracking_set',
                'title' => 'Set shipment tracking number',
                'description' => 'Sets (or clears with "") the tracking number of the order shipment (order carrier, as in the order page "Shipping" tab). With notify_customer=true the customer gets the "in transit" email with the tracking link. The actionAdminOrdersTrackingNumberUpdate hook runs when the number changes, as in the back office.',
                'params' => [
                    'id_order' => self::idParam('Order ID.'),
                    'tracking_number' => ['type' => 'string', 'maxLength' => 64, 'description' => 'Tracking number; empty string clears it.', 'required' => true],
                    'notify_customer' => self::boolParam('Send the "in transit" email to the customer.', false),
                ],
                'write' => true,
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminOrders', 'edit'],
                'keywords' => ['shipping number', 'parcel', 'tracking'],
                'handler' => 'trackingSet',
            ],
            [
                'name' => 'order_note_add',
                'title' => 'Add order note or message',
                'description' => 'visibility "private": adds an employee-only note (appended to the order "note" field on PS 1.7.8+/8/9; on older versions a private order message). visibility "customer": adds a message the customer sees in their order history, and emails it to them unless send_email=false.',
                'params' => [
                    'id_order' => self::idParam('Order ID.'),
                    'message' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1600, 'description' => 'Plain text.', 'required' => true],
                    'visibility' => ['type' => 'string', 'enum' => ['private', 'customer'], 'required' => true],
                    'send_email' => self::boolParam('visibility=customer only: email the message to the customer.', true),
                ],
                'write' => true,
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminOrders', 'edit'],
                'keywords' => ['comment', 'message customer', 'internal note'],
                'handler' => 'noteAdd',
            ],
            [
                'name' => 'order_stats_summary',
                'title' => 'Sales summary',
                'description' => 'Sales figures of the shop for a date range (default: last 30 days): number of valid orders, revenue tax excl/incl (valid orders only, converted to the default currency), average basket, orders per current status, top products by quantity and by revenue, new vs returning customers. Read-only.',
                'params' => [
                    'date_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD (default: 30 days ago).'],
                    'date_to' => ['type' => 'string', 'description' => 'YYYY-MM-DD inclusive (default: today).'],
                    'top' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10, 'description' => 'Number of top products.'],
                ],
                'shop' => true,
                'lang' => true,
                'permissions' => [['AdminOrders', 'view'], ['AdminStats', 'view']],
                'keywords' => ['revenue', 'turnover', 'sales', 'statistics', 'kpi', 'best sellers'],
                'handler' => 'statsSummary',
            ],
        ];
    }

    // ------------------------------------------------------------------ read

    public function orderSearch(array $args, ExecutionContext $ctx): array
    {
        $p = _DB_PREFIX_;
        $idLang = (int) $ctx->idLang;
        $where = ['o.`id_shop` = ' . (int) $ctx->idShop];

        if (!empty($args['query'])) {
            $q = self::likeValue((string) $args['query']);
            $or = [
                'o.`reference` LIKE \'%' . $q . '%\'',
                'cu.`email` LIKE \'%' . $q . '%\'',
                'CONCAT(cu.`firstname`, \' \', cu.`lastname`) LIKE \'%' . $q . '%\'',
                'CONCAT(cu.`lastname`, \' \', cu.`firstname`) LIKE \'%' . $q . '%\'',
            ];
            if (ctype_digit(trim($args['query']))) {
                $or[] = 'o.`id_order` = ' . (int) $args['query'];
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        if (!empty($args['reference'])) {
            $where[] = 'o.`reference` = \'' . pSQL(trim($args['reference'])) . '\'';
        }
        if (!empty($args['id_order'])) {
            $where[] = 'o.`id_order` = ' . (int) $args['id_order'];
        }
        if (!empty($args['id_customer'])) {
            $where[] = 'o.`id_customer` = ' . (int) $args['id_customer'];
        }
        if (!empty($args['customer_email'])) {
            $where[] = 'cu.`email` LIKE \'%' . self::likeValue((string) $args['customer_email']) . '%\'';
        }
        if (!empty($args['customer_name'])) {
            $n = self::likeValue((string) $args['customer_name']);
            $where[] = '(CONCAT(cu.`firstname`, \' \', cu.`lastname`) LIKE \'%' . $n . '%\' OR CONCAT(cu.`lastname`, \' \', cu.`firstname`) LIKE \'%' . $n . '%\')';
        }
        if (isset($args['status'])) {
            $ids = array_filter(array_map('intval', (array) $args['status']));
            if ($ids) {
                $where[] = 'o.`current_state` IN (' . implode(',', $ids) . ')';
            }
        }
        if (!empty($args['payment_module'])) {
            $pm = pSQL(trim($args['payment_module']));
            $where[] = '(o.`module` = \'' . $pm . '\' OR o.`payment` LIKE \'%' . self::likeValue((string) $args['payment_module']) . '%\')';
        }
        if (!empty($args['id_carrier'])) {
            $where[] = 'o.`id_carrier` IN (SELECT c2.`id_carrier` FROM `' . $p . 'carrier` c1 JOIN `' . $p . 'carrier` c2 ON c2.`id_reference` = c1.`id_reference` WHERE c1.`id_carrier` = ' . (int) $args['id_carrier'] . ')';
        }
        if (!empty($args['date_from'])) {
            $where[] = 'o.`date_add` >= \'' . pSQL(self::parseDate($args['date_from'], false, 'date_from')) . '\'';
        }
        if (!empty($args['date_to'])) {
            $where[] = 'o.`date_add` <= \'' . pSQL(self::parseDate($args['date_to'], true, 'date_to')) . '\'';
        }
        if (isset($args['total_min'])) {
            $where[] = 'o.`total_paid_tax_incl` >= ' . (float) $args['total_min'];
        }
        if (isset($args['total_max'])) {
            $where[] = 'o.`total_paid_tax_incl` <= ' . (float) $args['total_max'];
        }
        if (!empty($args['id_product'])) {
            $where[] = 'EXISTS (SELECT 1 FROM `' . $p . 'order_detail` od WHERE od.`id_order` = o.`id_order` AND od.`product_id` = ' . (int) $args['id_product'] . ')';
        }
        if (!empty($args['valid_only'])) {
            $where[] = 'o.`valid` = 1';
        }
        $w = implode(' AND ', $where);
        $from = '`' . $p . 'orders` o LEFT JOIN `' . $p . 'customer` cu ON cu.`id_customer` = o.`id_customer`';

        $total = (int) $this->db()->getValue('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $w);
        $orderBy = ['date_add' => 'o.`date_add`', 'id_order' => 'o.`id_order`', 'total_paid' => 'o.`total_paid_tax_incl`'];
        $by = isset($orderBy[$args['order_by']]) ? $orderBy[$args['order_by']] : 'o.`date_add`';
        $way = $args['order_way'] === 'asc' ? 'ASC' : 'DESC';

        $rows = $this->db()->executeS(
            'SELECT o.`id_order`, o.`reference`, o.`date_add`, o.`id_customer`, cu.`firstname`, cu.`lastname`, cu.`email`,
                o.`total_paid_tax_incl`, o.`id_currency`, o.`current_state`, osl.`name` AS status_name, o.`payment`, o.`module`,
                o.`id_carrier`, ca.`name` AS carrier_name, o.`valid`, o.`id_shop`
             FROM ' . $from . '
             LEFT JOIN `' . $p . 'order_state_lang` osl ON osl.`id_order_state` = o.`current_state` AND osl.`id_lang` = ' . $idLang . '
             LEFT JOIN `' . $p . 'carrier` ca ON ca.`id_carrier` = o.`id_carrier`
             WHERE ' . $w . ' ORDER BY ' . $by . ' ' . $way . ', o.`id_order` ' . $way . '
             LIMIT ' . (int) $args['offset'] . ', ' . (int) $args['limit']
        ) ?: [];

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id_order' => (int) $r['id_order'],
                'reference' => $r['reference'],
                'date_add' => $r['date_add'],
                'customer' => ['id_customer' => (int) $r['id_customer'], 'name' => trim($r['firstname'] . ' ' . $r['lastname']), 'email' => $r['email']],
                'total_paid_tax_incl' => $this->money((float) $r['total_paid_tax_incl'], (int) $r['id_currency']),
                'status' => ['id' => (int) $r['current_state'], 'name' => $r['status_name']],
                'valid' => (bool) $r['valid'],
                'payment' => $r['payment'],
                'payment_module' => $r['module'],
                'carrier' => $r['carrier_name'] === '0' ? \Configuration::get('PS_SHOP_NAME') : $r['carrier_name'],
                'id_shop' => (int) $r['id_shop'],
            ];
        }

        return $this->paged($items, $total, $args, 'orders');
    }

    public function orderGet(array $args, ExecutionContext $ctx): array
    {
        $order = $this->resolveOrder($args, $ctx);
        $p = _DB_PREFIX_;
        $idLang = (int) $ctx->idLang;
        $id = (int) $order->id;
        $cur = (int) $order->id_currency;
        $db = $this->db();

        $customer = $db->getRow(
            'SELECT `id_customer`, `firstname`, `lastname`, `email`, `company`, `is_guest`, `id_default_group`, `note`, `date_add`
             FROM `' . $p . 'customer` WHERE `id_customer` = ' . (int) $order->id_customer
        );
        $customerOut = null;
        if ($customer) {
            $customerOut = [
                'id_customer' => (int) $customer['id_customer'],
                'name' => trim($customer['firstname'] . ' ' . $customer['lastname']),
                'email' => $customer['email'],
                'company' => $customer['company'],
                'is_guest' => (bool) $customer['is_guest'],
                'id_default_group' => (int) $customer['id_default_group'],
                'customer_since' => $customer['date_add'],
                'valid_orders' => (int) $db->getValue('SELECT COUNT(*) FROM `' . $p . 'orders` WHERE `valid` = 1 AND `id_customer` = ' . (int) $customer['id_customer']),
            ];
        }

        // Products
        $products = [];
        $detailRows = $db->executeS(
            'SELECT `id_order_detail`, `product_id`, `product_attribute_id`, `product_name`, `product_reference`, `product_ean13`,
                `product_quantity`, `product_quantity_refunded`, `product_quantity_return`, `product_quantity_reinjected`,
                `unit_price_tax_incl`, `unit_price_tax_excl`, `total_price_tax_incl`, `total_price_tax_excl`, `id_order_invoice`
             FROM `' . $p . 'order_detail` WHERE `id_order` = ' . $id . ' ORDER BY `id_order_detail`'
        ) ?: [];
        foreach ($detailRows as $d) {
            $products[] = [
                'id_order_detail' => (int) $d['id_order_detail'],
                'id_product' => (int) $d['product_id'],
                'id_product_attribute' => (int) $d['product_attribute_id'],
                'name' => $d['product_name'],
                'reference' => $d['product_reference'],
                'ean13' => $d['product_ean13'],
                'quantity' => (int) $d['product_quantity'],
                'quantity_refunded' => (int) $d['product_quantity_refunded'],
                'quantity_returned' => (int) $d['product_quantity_return'],
                'quantity_reinjected' => (int) $d['product_quantity_reinjected'],
                'unit_price_tax_excl' => round((float) $d['unit_price_tax_excl'], 6),
                'unit_price_tax_incl' => round((float) $d['unit_price_tax_incl'], 6),
                'total_tax_excl' => round((float) $d['total_price_tax_excl'], 6),
                'total_tax_incl' => round((float) $d['total_price_tax_incl'], 6),
            ];
        }

        $cartRules = [];
        foreach ($db->executeS('SELECT `id_cart_rule`, `name`, `value`, `value_tax_excl`, `free_shipping` FROM `' . $p . 'order_cart_rule` WHERE `id_order` = ' . $id) ?: [] as $cr) {
            $cartRules[] = [
                'id_cart_rule' => (int) $cr['id_cart_rule'],
                'name' => $cr['name'],
                'value_tax_incl' => (float) $cr['value'],
                'value_tax_excl' => (float) $cr['value_tax_excl'],
                'free_shipping' => (bool) $cr['free_shipping'],
            ];
        }

        $payments = [];
        foreach ($db->executeS(
            'SELECT `id_order_payment`, `id_currency`, `amount`, `payment_method`, `transaction_id`, `card_brand`, `date_add`
             FROM `' . $p . 'order_payment` WHERE `order_reference` = \'' . pSQL($order->reference) . '\' ORDER BY `date_add`'
        ) ?: [] as $pay) {
            $payments[] = [
                'id_order_payment' => (int) $pay['id_order_payment'],
                'date' => $pay['date_add'],
                'method' => $pay['payment_method'],
                'amount' => $this->money((float) $pay['amount'], (int) $pay['id_currency']),
                'transaction_id' => $pay['transaction_id'],
                'card_brand' => $pay['card_brand'],
            ];
        }

        // Shipping
        $shipping = [];
        foreach ($db->executeS(
            'SELECT oc.`id_order_carrier`, oc.`id_carrier`, ca.`name`, ca.`url`, oc.`weight`, oc.`shipping_cost_tax_excl`, oc.`shipping_cost_tax_incl`, oc.`tracking_number`, oc.`date_add`
             FROM `' . $p . 'order_carrier` oc LEFT JOIN `' . $p . 'carrier` ca ON ca.`id_carrier` = oc.`id_carrier`
             WHERE oc.`id_order` = ' . $id . ' ORDER BY oc.`id_order_carrier`'
        ) ?: [] as $oc) {
            $shipping[] = [
                'id_order_carrier' => (int) $oc['id_order_carrier'],
                'id_carrier' => (int) $oc['id_carrier'],
                'carrier' => $oc['name'] === '0' ? \Configuration::get('PS_SHOP_NAME') : $oc['name'],
                'weight' => (float) $oc['weight'],
                'shipping_cost_tax_excl' => (float) $oc['shipping_cost_tax_excl'],
                'shipping_cost_tax_incl' => (float) $oc['shipping_cost_tax_incl'],
                'tracking_number' => $oc['tracking_number'],
                'tracking_url' => ($oc['tracking_number'] && $oc['url']) ? str_replace('@', $oc['tracking_number'], $oc['url']) : null,
                'date_add' => $oc['date_add'],
            ];
        }

        // History
        $history = [];
        foreach ($db->executeS(
            'SELECT oh.`id_order_history`, oh.`id_order_state`, osl.`name`, oh.`id_employee`, e.`firstname`, e.`lastname`, oh.`date_add`
             FROM `' . $p . 'order_history` oh
             LEFT JOIN `' . $p . 'order_state_lang` osl ON osl.`id_order_state` = oh.`id_order_state` AND osl.`id_lang` = ' . $idLang . '
             LEFT JOIN `' . $p . 'employee` e ON e.`id_employee` = oh.`id_employee`
             WHERE oh.`id_order` = ' . $id . ' ORDER BY oh.`date_add` DESC, oh.`id_order_history` DESC'
        ) ?: [] as $h) {
            $history[] = [
                'date' => $h['date_add'],
                'id_order_state' => (int) $h['id_order_state'],
                'status' => $h['name'],
                'employee' => (int) $h['id_employee'] ? trim($h['firstname'] . ' ' . $h['lastname']) . ' (#' . (int) $h['id_employee'] . ')' : null,
            ];
        }

        // Messages: customer service thread of the order + legacy message table
        $messages = [];
        foreach ($db->executeS(
            'SELECT cm.`id_customer_message`, cm.`id_employee`, e.`firstname`, e.`lastname`, cm.`message`, cm.`private`, cm.`date_add`
             FROM `' . $p . 'customer_thread` ct
             JOIN `' . $p . 'customer_message` cm ON cm.`id_customer_thread` = ct.`id_customer_thread`
             LEFT JOIN `' . $p . 'employee` e ON e.`id_employee` = cm.`id_employee`
             WHERE ct.`id_order` = ' . $id . ' ORDER BY cm.`date_add`, cm.`id_customer_message`'
        ) ?: [] as $m) {
            $messages[] = [
                'source' => 'customer_thread',
                'date' => $m['date_add'],
                'from' => (int) $m['id_employee'] ? 'employee ' . trim($m['firstname'] . ' ' . $m['lastname']) : 'customer',
                'private' => (bool) $m['private'],
                'message' => self::cut($m['message'], 1000),
            ];
        }
        foreach ($db->executeS(
            'SELECT m.`id_message`, m.`id_employee`, e.`firstname`, e.`lastname`, m.`message`, m.`private`, m.`date_add`
             FROM `' . $p . 'message` m LEFT JOIN `' . $p . 'employee` e ON e.`id_employee` = m.`id_employee`
             WHERE m.`id_order` = ' . $id . ' ORDER BY m.`date_add`'
        ) ?: [] as $m) {
            $messages[] = [
                'source' => 'order_message',
                'date' => $m['date_add'],
                'from' => (int) $m['id_employee'] ? 'employee ' . trim($m['firstname'] . ' ' . $m['lastname']) : 'customer',
                'private' => (bool) $m['private'],
                'message' => self::cut($m['message'], 1000),
            ];
        }

        // Documents
        $invoices = [];
        foreach ($db->executeS('SELECT `id_order_invoice`, `number`, `delivery_number`, `date_add`, `delivery_date`, `total_paid_tax_incl` FROM `' . $p . 'order_invoice` WHERE `id_order` = ' . $id . ' ORDER BY `id_order_invoice`') ?: [] as $inv) {
            $formatted = null;
            if ((int) $inv['number']) {
                $oi = new \OrderInvoice((int) $inv['id_order_invoice']);
                $formatted = $oi->getInvoiceNumberFormatted($idLang, (int) $order->id_shop);
            }
            $invoices[] = [
                'id_order_invoice' => (int) $inv['id_order_invoice'],
                'number' => $formatted,
                'delivery_number' => (int) $inv['delivery_number'] ?: null,
                'date' => $inv['date_add'],
                'total_paid_tax_incl' => (float) $inv['total_paid_tax_incl'],
            ];
        }
        $slips = [];
        $slipPrefix = \Configuration::get('PS_CREDIT_SLIP_PREFIX', $idLang, null, (int) $order->id_shop);
        foreach ($db->executeS('SELECT `id_order_slip`, `amount`, `shipping_cost_amount`, `total_products_tax_incl`, `total_shipping_tax_incl`, `date_add` FROM `' . $p . 'order_slip` WHERE `id_order` = ' . $id . ' ORDER BY `id_order_slip`') ?: [] as $s) {
            $slips[] = [
                'id_order_slip' => (int) $s['id_order_slip'],
                'number' => $slipPrefix . sprintf('%06d', (int) $s['id_order_slip']),
                'date' => $s['date_add'],
                'total_products_tax_incl' => (float) $s['total_products_tax_incl'],
                'total_shipping_tax_incl' => (float) $s['total_shipping_tax_incl'],
            ];
        }

        $state = $db->getRow(
            'SELECT os.`id_order_state`, osl.`name`, os.`paid`, os.`shipped`, os.`delivery`, os.`logable`
             FROM `' . $p . 'order_state` os LEFT JOIN `' . $p . 'order_state_lang` osl ON osl.`id_order_state` = os.`id_order_state` AND osl.`id_lang` = ' . $idLang . '
             WHERE os.`id_order_state` = ' . (int) $order->current_state
        );
        $currency = new \Currency($cur);
        $shopName = $db->getValue('SELECT `name` FROM `' . $p . 'shop` WHERE `id_shop` = ' . (int) $order->id_shop);

        $out = [
            'id_order' => $id,
            'reference' => $order->reference,
            'date_add' => $order->date_add,
            'date_upd' => $order->date_upd,
            'shop' => ['id_shop' => (int) $order->id_shop, 'name' => $shopName],
            'id_cart' => (int) $order->id_cart,
            'id_lang' => (int) $order->id_lang,
            'currency' => $currency->iso_code,
            'conversion_rate' => (float) $order->conversion_rate,
            'status' => $state ? [
                'id' => (int) $state['id_order_state'], 'name' => $state['name'], 'paid' => (bool) $state['paid'],
                'shipped' => (bool) $state['shipped'], 'delivered' => (bool) $state['delivery'], 'logable' => (bool) $state['logable'],
            ] : ['id' => (int) $order->current_state],
            'valid' => (bool) $order->valid,
            'payment' => $order->payment,
            'payment_module' => $order->module,
            'customer' => $customerOut,
            'address_delivery' => CustomerTools::addressRow((int) $order->id_address_delivery, $idLang),
            'address_invoice' => (int) $order->id_address_invoice === (int) $order->id_address_delivery ? 'same as delivery' : CustomerTools::addressRow((int) $order->id_address_invoice, $idLang),
            'products' => $products,
            'totals' => [
                'products_tax_excl' => (float) $order->total_products,
                'products_tax_incl' => (float) $order->total_products_wt,
                'shipping_tax_excl' => (float) $order->total_shipping_tax_excl,
                'shipping_tax_incl' => (float) $order->total_shipping_tax_incl,
                'discounts_tax_excl' => (float) $order->total_discounts_tax_excl,
                'discounts_tax_incl' => (float) $order->total_discounts_tax_incl,
                'wrapping_tax_excl' => (float) $order->total_wrapping_tax_excl,
                'wrapping_tax_incl' => (float) $order->total_wrapping_tax_incl,
                'paid_tax_excl' => (float) $order->total_paid_tax_excl,
                'paid_tax_incl' => $this->money((float) $order->total_paid_tax_incl, $cur),
                'paid_real' => (float) $order->total_paid_real,
            ],
            'cart_rules' => $cartRules,
            'payments' => $payments,
            'shipping' => $shipping,
            'gift' => (bool) $order->gift,
            'gift_message' => $order->gift ? $order->gift_message : null,
            'recyclable' => (bool) $order->recyclable,
            'history' => $history,
            'messages' => $messages,
            'private_note_order' => property_exists($order, 'note') ? (string) $order->note : null,
            'private_note_customer' => $customer ? (string) $customer['note'] : null,
            'invoices' => $invoices,
            'credit_slips' => $slips,
        ];
        $same = $db->executeS('SELECT `id_order` FROM `' . $p . 'orders` WHERE `reference` = \'' . pSQL($order->reference) . '\' AND `id_order` <> ' . $id) ?: [];
        if ($same) {
            $out['same_reference_orders'] = array_map('intval', array_column($same, 'id_order'));
        }
        if (!property_exists($order, 'note')) {
            unset($out['private_note_order']);
        }

        return $out;
    }

    public function statusList(array $args, ExecutionContext $ctx): array
    {
        $p = _DB_PREFIX_;
        $rows = $this->db()->executeS(
            'SELECT os.*, osl.`name`, osl.`template`
             FROM `' . $p . 'order_state` os
             LEFT JOIN `' . $p . 'order_state_lang` osl ON osl.`id_order_state` = os.`id_order_state` AND osl.`id_lang` = ' . (int) $ctx->idLang . '
             WHERE os.`deleted` = 0 ORDER BY os.`id_order_state`'
        ) ?: [];
        $counts = [];
        foreach ($this->db()->executeS('SELECT `current_state`, COUNT(*) n FROM `' . $p . 'orders` WHERE `id_shop` = ' . (int) $ctx->idShop . ' GROUP BY `current_state`') ?: [] as $c) {
            $counts[(int) $c['current_state']] = (int) $c['n'];
        }
        $special = [];
        foreach (['PS_OS_CHEQUE', 'PS_OS_PAYMENT', 'PS_OS_PREPARATION', 'PS_OS_SHIPPING', 'PS_OS_DELIVERED', 'PS_OS_CANCELED', 'PS_OS_REFUND', 'PS_OS_ERROR', 'PS_OS_OUTOFSTOCK', 'PS_OS_BANKWIRE', 'PS_OS_WS_PAYMENT', 'PS_OS_OUTOFSTOCK_PAID', 'PS_OS_OUTOFSTOCK_UNPAID', 'PS_OS_COD_VALIDATION'] as $k) {
            $v = (int) \Configuration::get($k);
            if ($v) {
                $special[$v][] = $k;
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id_order_state'];
            $out[] = [
                'id_order_state' => $id,
                'name' => $r['name'],
                'paid' => (bool) $r['paid'],
                'shipped' => (bool) $r['shipped'],
                'delivered' => (bool) $r['delivery'],
                'logable' => (bool) $r['logable'],
                'invoice' => (bool) $r['invoice'],
                'send_email' => (bool) $r['send_email'],
                'template' => $r['send_email'] ? $r['template'] : null,
                'hidden' => (bool) $r['hidden'],
                'color' => $r['color'],
                'module_name' => $r['module_name'] ?: null,
                'system_role' => isset($special[$id]) ? $special[$id] : null,
                'orders_currently' => isset($counts[$id]) ? $counts[$id] : 0,
            ];
        }

        return ['id_shop' => (int) $ctx->idShop, 'statuses' => $out];
    }

    public function statsSummary(array $args, ExecutionContext $ctx): array
    {
        $p = _DB_PREFIX_;
        $db = $this->db();
        $from = !empty($args['date_from']) ? self::parseDate($args['date_from'], false, 'date_from') : date('Y-m-d 00:00:00', strtotime('-30 days'));
        $to = !empty($args['date_to']) ? self::parseDate($args['date_to'], true, 'date_to') : date('Y-m-d 23:59:59');
        if ($from > $to) {
            throw new ToolException('date_from is after date_to.');
        }
        $top = (int) $args['top'];
        $range = 'o.`id_shop` = ' . (int) $ctx->idShop . ' AND o.`date_add` BETWEEN \'' . pSQL($from) . '\' AND \'' . pSQL($to) . '\'';
        $rate = 'IF(o.`conversion_rate` > 0, o.`conversion_rate`, 1)';

        $agg = $db->getRow(
            'SELECT COUNT(*) AS orders, SUM(o.`total_paid_tax_excl` / ' . $rate . ') AS rev_excl, SUM(o.`total_paid_tax_incl` / ' . $rate . ') AS rev_incl,
                SUM(o.`total_products` / ' . $rate . ') AS products_excl, SUM(o.`total_shipping_tax_excl` / ' . $rate . ') AS shipping_excl,
                SUM(o.`total_discounts_tax_excl` / ' . $rate . ') AS discounts_excl, COUNT(DISTINCT o.`id_customer`) AS customers
             FROM `' . $p . 'orders` o WHERE o.`valid` = 1 AND ' . $range
        );
        $allOrders = (int) $db->getValue('SELECT COUNT(*) FROM `' . $p . 'orders` o WHERE ' . $range);
        $n = (int) $agg['orders'];

        $perStatus = [];
        foreach ($db->executeS(
            'SELECT o.`current_state`, osl.`name`, COUNT(*) AS n, SUM(o.`total_paid_tax_incl` / ' . $rate . ') AS amount
             FROM `' . $p . 'orders` o
             LEFT JOIN `' . $p . 'order_state_lang` osl ON osl.`id_order_state` = o.`current_state` AND osl.`id_lang` = ' . (int) $ctx->idLang . '
             WHERE ' . $range . ' GROUP BY o.`current_state`, osl.`name` ORDER BY n DESC'
        ) ?: [] as $r) {
            $perStatus[] = ['id_order_state' => (int) $r['current_state'], 'name' => $r['name'], 'orders' => (int) $r['n'], 'total_tax_incl' => round((float) $r['amount'], 2)];
        }

        $topSql = 'SELECT od.`product_id`, MAX(od.`product_name`) AS name, MAX(od.`product_reference`) AS reference,
                SUM(od.`product_quantity` - od.`product_quantity_refunded`) AS qty, SUM(od.`total_price_tax_excl` / ' . $rate . ') AS revenue
             FROM `' . $p . 'order_detail` od JOIN `' . $p . 'orders` o ON o.`id_order` = od.`id_order`
             WHERE o.`valid` = 1 AND ' . $range . ' GROUP BY od.`product_id`';
        $map = function ($r) {
            return ['id_product' => (int) $r['product_id'], 'name' => $r['name'], 'reference' => $r['reference'], 'quantity' => (int) $r['qty'], 'revenue_tax_excl' => round((float) $r['revenue'], 2)];
        };
        $topQty = array_map($map, $db->executeS($topSql . ' ORDER BY qty DESC LIMIT ' . $top) ?: []);
        $topRev = array_map($map, $db->executeS($topSql . ' ORDER BY revenue DESC LIMIT ' . $top) ?: []);

        // New customer = first valid order ever falls inside the range.
        $cust = $db->getRow(
            'SELECT SUM(t.`first_order` >= \'' . pSQL($from) . '\') AS new_customers, SUM(t.`first_order` < \'' . pSQL($from) . '\') AS returning_customers
             FROM (
                SELECT o.`id_customer`, (SELECT MIN(o2.`date_add`) FROM `' . $p . 'orders` o2 WHERE o2.`id_customer` = o.`id_customer` AND o2.`valid` = 1) AS first_order
                FROM `' . $p . 'orders` o WHERE o.`valid` = 1 AND ' . $range . ' GROUP BY o.`id_customer`
             ) t'
        );

        $shop = new \Shop((int) $ctx->idShop);
        $idCurrency = (int) \Configuration::get('PS_CURRENCY_DEFAULT', null, (int) $shop->id_shop_group, (int) $ctx->idShop);
        $revExcl = (float) $agg['rev_excl'];
        $revIncl = (float) $agg['rev_incl'];

        return [
            'id_shop' => (int) $ctx->idShop,
            'period' => ['from' => $from, 'to' => $to],
            'currency' => (new \Currency($idCurrency))->iso_code,
            'orders_all_statuses' => $allOrders,
            'valid_orders' => $n,
            'revenue_tax_excl' => $this->money($revExcl, $idCurrency),
            'revenue_tax_incl' => $this->money($revIncl, $idCurrency),
            'products_tax_excl' => round((float) $agg['products_excl'], 2),
            'shipping_tax_excl' => round((float) $agg['shipping_excl'], 2),
            'discounts_tax_excl' => round((float) $agg['discounts_excl'], 2),
            'average_basket_tax_incl' => $n ? round($revIncl / $n, 2) : 0,
            'average_basket_tax_excl' => $n ? round($revExcl / $n, 2) : 0,
            'customers' => [
                'distinct' => (int) $agg['customers'],
                'new' => (int) $cust['new_customers'],
                'returning' => (int) $cust['returning_customers'],
            ],
            'orders_per_status' => $perStatus,
            'top_products_by_quantity' => $topQty,
            'top_products_by_revenue' => $topRev,
            'notes' => 'Revenue, basket, customers and top products count valid orders only (status flagged "consider as validated"); amounts converted to the shop default currency; top product quantity excludes refunded units.',
        ];
    }

    // ----------------------------------------------------------------- write

    public function statusSet(array $args, ExecutionContext $ctx): array
    {
        $order = $this->loadOrder((int) $args['id_order'], $ctx);
        $idLang = (int) $ctx->idLang;
        $newState = new \OrderState((int) $args['id_order_state'], $idLang);
        if (!\Validate::isLoadedObject($newState) || $newState->deleted) {
            throw new ToolException(sprintf('Order status #%d does not exist.', (int) $args['id_order_state']), 'Call order_status_list.');
        }
        $current = $order->getCurrentOrderState();
        $currentId = $current ? (int) $current->id : (int) $order->current_state;
        if ($currentId === (int) $newState->id) {
            throw new ToolException(sprintf('Order #%d already has status #%d (%s).', (int) $order->id, $currentId, $newState->name), 'Pick a different status.');
        }
        $currentState = $currentId ? new \OrderState($currentId, $idLang) : null;
        $currentName = $currentState && \Validate::isLoadedObject($currentState) ? $currentState->name : null;
        $sendEmail = !empty($args['send_email']);
        if ($newState->send_email && !$sendEmail) {
            $ctx->addWarning('The new status normally emails the customer; send_email=false suppresses it.');
        }
        if (!$order->hasInvoice() && $newState->invoice) {
            $ctx->addWarning('The new status generates an invoice for this order.');
        }
        if ($current && $current->paid && !$newState->paid && (int) $newState->id !== (int) \Configuration::get('PS_OS_REFUND')) {
            $ctx->addWarning('Order moves from a paid status to an unpaid one.');
        }
        $ctx->recordChange('Order', (int) $order->id, 'current_state', $currentId, (int) $newState->id, (int) $order->id_shop);

        $result = [
            'id_order' => (int) $order->id,
            'reference' => $order->reference,
            'before' => ['id_order_state' => $currentId, 'name' => $currentName],
            'after' => [
                'id_order_state' => (int) $newState->id,
                'name' => $newState->name,
                'paid' => (bool) $newState->paid,
                'shipped' => (bool) $newState->shipped,
                'logable' => (bool) $newState->logable,
                'invoice' => (bool) $newState->invoice,
            ],
            'email' => $sendEmail && $newState->send_email ? 'customer will be emailed (template ' . $newState->template . ')' : 'no email',
        ];
        if ($ctx->dryRun) {
            return $result;
        }

        $history = new \OrderHistory();
        $history->id_order = (int) $order->id;
        $history->id_employee = (int) $ctx->employee->id;
        $useExistingPayments = !$order->hasInvoice();
        $history->changeIdOrderState((int) $newState->id, $order, $useExistingPayments);

        $templateVars = [];
        $tracking = $this->currentTrackingNumber($order);
        if ((int) $history->id_order_state === (int) \Configuration::get('PS_OS_SHIPPING') && $tracking) {
            $carrier = new \Carrier((int) $order->id_carrier, (int) $order->id_lang);
            $templateVars = ['{followup}' => str_replace('@', $tracking, (string) $carrier->url)];
        }
        $ok = $sendEmail ? $history->addWithemail(true, $templateVars) : $history->add(true);
        if (!$ok) {
            throw new ToolException('PrestaShop failed to save the status change or to send the email.', 'Check shop_log_list for details.');
        }
        $fresh = new \Order((int) $order->id);
        $result['saved_state'] = (int) $fresh->current_state;
        $result['id_order_history'] = (int) $history->id;

        return $result;
    }

    public function trackingSet(array $args, ExecutionContext $ctx): array
    {
        $order = $this->loadOrder((int) $args['id_order'], $ctx);
        $tracking = trim((string) $args['tracking_number']);
        if ($tracking !== '' && !\Validate::isTrackingNumber($tracking)) {
            throw new ToolException('The tracking number contains invalid characters.', 'Use letters, digits, spaces, "-", "_" or "/".');
        }
        $idOrderCarrier = (int) $order->getIdOrderCarrier();
        $hasLegacyField = property_exists($order, 'shipping_number');
        if (!$idOrderCarrier && !$hasLegacyField) {
            throw new ToolException(sprintf('Order #%d has no carrier (virtual order?), a tracking number cannot be set.', (int) $order->id));
        }
        $orderCarrier = $idOrderCarrier ? new \OrderCarrier($idOrderCarrier) : null;
        $old = $orderCarrier ? (string) $orderCarrier->tracking_number : (string) $order->shipping_number;
        if ($old === $tracking) {
            return ['id_order' => (int) $order->id, 'tracking_number' => $tracking, 'note' => 'Tracking number already set to this value.'];
        }
        if ($orderCarrier) {
            $ctx->recordChange('OrderCarrier', (int) $orderCarrier->id, 'tracking_number', $old, $tracking, (int) $order->id_shop);
        }
        if ($hasLegacyField) {
            $ctx->recordChange('Order', (int) $order->id, 'shipping_number', (string) $order->shipping_number, $tracking, (int) $order->id_shop);
        }
        $notify = !empty($args['notify_customer']) && $tracking !== '';
        $result = [
            'id_order' => (int) $order->id,
            'reference' => $order->reference,
            'before' => $old,
            'after' => $tracking,
            'notify_customer' => $notify,
        ];
        if ($ctx->dryRun) {
            return $result;
        }

        if ($hasLegacyField) {
            // PS 1.7: orders.shipping_number is still read by some modules/templates.
            $this->dbWrite()->update('orders', ['shipping_number' => pSQL($tracking)], '`id_order` = ' . (int) $order->id);
            $order->shipping_number = $tracking;
        }
        if ($orderCarrier) {
            $orderCarrier->tracking_number = pSQL($tracking);
            if (!$orderCarrier->update()) {
                throw new ToolException('The order carrier could not be updated.');
            }
        }
        if ($tracking !== '') {
            if ($notify && $orderCarrier) {
                $result['email_sent'] = (bool) $orderCarrier->sendInTransitEmail($order);
                if (!$result['email_sent']) {
                    $ctx->addWarning('The tracking number was saved but the "in transit" email could not be sent.');
                }
            }
            $customer = new \Customer((int) $order->id_customer);
            $carrier = new \Carrier((int) $order->id_carrier, (int) $order->id_lang);
            \Hook::exec('actionAdminOrdersTrackingNumberUpdate', [
                'order' => $order,
                'customer' => $customer,
                'carrier' => $carrier,
            ], null, false, true, false, (int) $order->id_shop);
        }

        return $result;
    }

    public function noteAdd(array $args, ExecutionContext $ctx): array
    {
        $order = $this->loadOrder((int) $args['id_order'], $ctx);
        $message = trim((string) $args['message']);
        if (!\Validate::isCleanHtml($message)) {
            throw new ToolException('The message contains forbidden HTML/script content.');
        }
        $private = $args['visibility'] === 'private';

        if ($private && property_exists($order, 'note')) {
            $old = (string) $order->note;
            $new = $old === '' ? $message : rtrim($old) . "\n" . $message;
            $ctx->recordChange('Order', (int) $order->id, 'note', $old, $new, (int) $order->id_shop);
            if (!$ctx->dryRun) {
                $this->dbWrite()->update('orders', ['note' => pSQL($new, true), 'date_upd' => date('Y-m-d H:i:s')], '`id_order` = ' . (int) $order->id);
            }

            return ['id_order' => (int) $order->id, 'stored_in' => 'order note (private, back office only)', 'note' => $new];
        }

        $customer = new \Customer((int) $order->id_customer);
        if (!\Validate::isLoadedObject($customer)) {
            throw new ToolException(sprintf('The customer of order #%d no longer exists; a message cannot be attached.', (int) $order->id));
        }
        $idThread = (int) \CustomerThread::getIdCustomerThreadByEmailAndIdOrder($customer->email, (int) $order->id);
        $sendEmail = !$private && !empty($args['send_email']);
        $ctx->recordChange('CustomerMessage', 'new', 'message', null, $message, (int) $order->id_shop);
        $ctx->recordChange('CustomerMessage', 'new', 'private', null, $private ? 1 : 0, (int) $order->id_shop);
        $result = [
            'id_order' => (int) $order->id,
            'stored_in' => $private ? 'private order message (back office only)' : 'order message visible to the customer',
            'thread' => $idThread ? 'existing #' . $idThread : 'new thread',
            'send_email' => $sendEmail,
        ];
        if ($ctx->dryRun) {
            return $result;
        }

        if (!$idThread) {
            $thread = new \CustomerThread();
            $thread->id_contact = 0;
            $thread->id_customer = (int) $order->id_customer;
            $thread->id_shop = (int) $order->id_shop;
            $thread->id_order = (int) $order->id;
            $thread->id_lang = (int) $order->id_lang;
            $thread->email = $customer->email;
            $thread->status = 'open';
            $thread->token = \Tools::passwdGen(12);
            if (!$thread->add()) {
                throw new ToolException('Could not create the customer thread.');
            }
            $idThread = (int) $thread->id;
        }
        $cm = new \CustomerMessage();
        $cm->id_customer_thread = $idThread;
        $cm->id_employee = (int) $ctx->employee->id;
        $cm->message = $message;
        $cm->private = $private ? 1 : 0;
        if (!$cm->add()) {
            throw new ToolException('Could not save the message.');
        }
        $result['id_customer_thread'] = $idThread;
        $result['id_customer_message'] = (int) $cm->id;
        $ctx->resolveCreatedId('CustomerMessage', (int) $cm->id);

        if ($sendEmail) {
            $body = $message;
            if (\Configuration::get('PS_MAIL_TYPE', null, null, (int) $order->id_shop) != \Mail::TYPE_TEXT) {
                $body = \Tools::nl2br(\Tools::htmlentitiesUTF8($message));
            }
            $idLang = (int) $order->id_lang;
            $sent = \Mail::Send(
                $idLang,
                'order_merchant_comment',
                \Mail::l('New message regarding your order', $idLang),
                [
                    '{lastname}' => $customer->lastname,
                    '{firstname}' => $customer->firstname,
                    '{id_order}' => (int) $order->id,
                    '{order_name}' => $order->getUniqReference(),
                    '{message}' => $body,
                ],
                $customer->email,
                $customer->firstname . ' ' . $customer->lastname,
                null,
                null,
                null,
                null,
                _PS_MAIL_DIR_,
                false,
                (int) $order->id_shop
            );
            $result['email_sent'] = (bool) $sent;
            if (!$sent) {
                $ctx->addWarning('The message was saved but the email to the customer could not be sent.');
            }
        }

        return $result;
    }

    // --------------------------------------------------------------- helpers

    /**
     * @return \Order
     *
     * @throws ToolException
     */
    private function resolveOrder(array $args, ExecutionContext $ctx)
    {
        if (!empty($args['id_order'])) {
            return $this->loadOrder((int) $args['id_order'], $ctx, false);
        }
        if (!empty($args['reference'])) {
            $id = (int) $this->db()->getValue('SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `reference` = \'' . pSQL(trim($args['reference'])) . '\' ORDER BY `id_order`');
            if (!$id) {
                throw new ToolException(sprintf('No order with reference "%s".', $args['reference']), 'Use order_search with query.');
            }

            return $this->loadOrder($id, $ctx, false);
        }
        throw new ToolException('Pass id_order or reference.');
    }

    /**
     * Loads an order and checks it belongs to the shop of the call (writes) or
     * at least to a shop the key may access (reads).
     *
     * @return \Order
     *
     * @throws ToolException
     */
    private function loadOrder(int $id, ExecutionContext $ctx, bool $strictShop = true)
    {
        /** @var \Order $order */
        $order = $this->load('Order', $id, null, null, 'Use order_search to find the order id.');
        $idShop = (int) $order->id_shop;
        if ($idShop !== (int) $ctx->idShop) {
            if (!$ctx->apiKey->allowsShop($idShop)) {
                throw new ToolException(sprintf('Order #%d belongs to shop #%d, which this API key may not access.', $id, $idShop));
            }
            if ($strictShop) {
                throw new ToolException(sprintf('Order #%d belongs to shop #%d.', $id, $idShop), sprintf('Call again with id_shop=%d.', $idShop));
            }
            $ctx->addWarning(sprintf('Order #%d belongs to shop #%d (not the current shop #%d).', $id, $idShop, (int) $ctx->idShop));
        }

        return $order;
    }

    private function currentTrackingNumber(\Order $order): string
    {
        $id = (int) $order->getIdOrderCarrier();
        if ($id) {
            $oc = new \OrderCarrier($id);
            if ($oc->tracking_number) {
                return (string) $oc->tracking_number;
            }
        }

        return property_exists($order, 'shipping_number') ? (string) $order->shipping_number : '';
    }

    private static function cut($text, int $max): string
    {
        $text = (string) $text;

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }

    /**
     * @throws ToolException
     */
    public static function parseDate($value, bool $endOfDay, string $field): string
    {
        $value = trim((string) $value);
        $ts = strtotime($value);
        if ($value === '' || $ts === false) {
            throw new ToolException(sprintf('%s is not a valid date: "%s".', $field, $value), 'Use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.');
        }
        if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return date('Y-m-d 23:59:59', $ts);
        }

        return date('Y-m-d H:i:s', $ts);
    }
}
