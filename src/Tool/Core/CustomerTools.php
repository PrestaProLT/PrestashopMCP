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
 * Customers and customer groups. Never returns password hashes, secure keys
 * or reset tokens.
 */
class CustomerTools extends AbstractToolGroup
{
    /** Customer columns safe to return (no passwd, secure_key, reset tokens). */
    const SAFE_COLUMNS = '`id_customer`, `id_shop`, `id_shop_group`, `id_gender`, `id_default_group`, `id_lang`, `id_risk`, `company`, `siret`, `ape`,
        `firstname`, `lastname`, `email`, `birthday`, `newsletter`, `newsletter_date_add`, `ip_registration_newsletter`, `optin`, `website`,
        `outstanding_allow_amount`, `show_public_prices`, `max_payment_days`, `note`, `active`, `is_guest`, `deleted`, `date_add`, `date_upd`';

    public function owner(): string
    {
        return 'customer';
    }

    public function tools(): array
    {
        return [
            [
                'name' => 'customer_search',
                'title' => 'Search customers',
                'description' => 'Finds customers (newest first) by free text (email, name, company, id), or by email, name, company, phone (searched in their addresses), group, newsletter, active and guest flags. Returns compact rows with valid order count. Use customer_get for the full profile.',
                'params' => array_merge([
                    'query' => ['type' => 'string', 'description' => 'Free text: email, first/last name, company or id.'],
                    'email' => ['type' => 'string', 'description' => 'Partial email.'],
                    'name' => ['type' => 'string', 'description' => 'Partial first/last name.'],
                    'company' => ['type' => 'string'],
                    'phone' => ['type' => 'string', 'description' => 'Phone or mobile number in any of the customer addresses (digits are enough).'],
                    'id_customer' => ['type' => 'integer', 'minimum' => 1],
                    'id_group' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Member of this group (customer_group_list).'],
                    'newsletter' => self::boolParam('Newsletter subscription.'),
                    'active' => self::boolParam('Account enabled.'),
                    'is_guest' => self::boolParam('Guest (no password) accounts.'),
                    'include_deleted' => self::boolParam('Include deleted customers.', false),
                ], self::paginationParams(20, 100)),
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminCustomers', 'view'],
                'keywords' => ['client', 'buyer', 'user', 'account', 'find customer'],
                'handler' => 'customerSearch',
            ],
            [
                'name' => 'customer_get',
                'title' => 'Get customer profile',
                'description' => 'Customer profile as in the back office: identity, groups (+ default), addresses, stats (valid orders, total spent), last 5 orders, last carts without order, newsletter/opt-in, active, guest, registration date, last visit, private note, shop. Find the id with customer_search.',
                'params' => [
                    'id_customer' => self::idParam('Customer ID (customer_search finds it). Or pass email.', false),
                    'email' => ['type' => 'string', 'description' => 'Exact email (if several accounts share it, all ids are listed).'],
                ],
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminCustomers', 'view'],
                'keywords' => ['client', 'account', 'addresses', 'customer orders'],
                'handler' => 'customerGet',
            ],
            [
                'name' => 'customer_group_list',
                'title' => 'List customer groups',
                'description' => 'Customer groups of the shop: id, name, discount %, price display (tax incl/excl), show prices, member count, and which groups are the visitor/guest/customer defaults.',
                'shop' => true,
                'lang' => true,
                'permissions' => [['AdminGroups', 'view'], ['AdminCustomers', 'view']],
                'keywords' => ['group', 'b2b', 'wholesale', 'price display'],
                'handler' => 'groupList',
            ],
            [
                'name' => 'customer_update',
                'title' => 'Update customer flags and groups',
                'description' => 'Limited customer update: active, newsletter, optin (partner offers), default group, groups to add/remove, private note (back office only). Identity, email and password cannot be changed here. Every changed field is recorded.',
                'params' => [
                    'id_customer' => self::idParam('Customer ID.'),
                    'active' => self::boolParam('Enable/disable the account.'),
                    'newsletter' => self::boolParam('Newsletter subscription.'),
                    'optin' => self::boolParam('Partner offers opt-in.'),
                    'id_default_group' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Default group (added to the groups if missing).'],
                    'add_groups' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 50, 'description' => 'Group ids to add.'],
                    'remove_groups' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 50, 'description' => 'Group ids to remove (not the default group).'],
                    'note' => ['type' => 'string', 'maxLength' => 65000, 'description' => 'Private note (replaces the current one; "" clears it).'],
                ],
                'write' => true,
                'shop' => true,
                'lang' => true,
                'permission' => ['AdminCustomers', 'edit'],
                'keywords' => ['disable customer', 'unsubscribe', 'customer group', 'note'],
                'handler' => 'customerUpdate',
            ],
        ];
    }

    // ------------------------------------------------------------------ read

    public function customerSearch(array $args, ExecutionContext $ctx): array
    {
        $p = _DB_PREFIX_;
        $where = ['1' . \Shop::addSqlRestriction(\Shop::SHARE_CUSTOMER, 'c')];
        if (empty($args['include_deleted'])) {
            $where[] = 'c.`deleted` = 0';
        }
        if (!empty($args['query'])) {
            $q = self::likeValue((string) $args['query']);
            $or = [
                'c.`email` LIKE \'%' . $q . '%\'',
                'c.`company` LIKE \'%' . $q . '%\'',
                'CONCAT(c.`firstname`, \' \', c.`lastname`) LIKE \'%' . $q . '%\'',
                'CONCAT(c.`lastname`, \' \', c.`firstname`) LIKE \'%' . $q . '%\'',
            ];
            if (ctype_digit(trim($args['query']))) {
                $or[] = 'c.`id_customer` = ' . (int) $args['query'];
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        if (!empty($args['email'])) {
            $where[] = 'c.`email` LIKE \'%' . self::likeValue((string) $args['email']) . '%\'';
        }
        if (!empty($args['name'])) {
            $n = self::likeValue((string) $args['name']);
            $where[] = '(CONCAT(c.`firstname`, \' \', c.`lastname`) LIKE \'%' . $n . '%\' OR CONCAT(c.`lastname`, \' \', c.`firstname`) LIKE \'%' . $n . '%\')';
        }
        if (!empty($args['company'])) {
            $where[] = 'c.`company` LIKE \'%' . self::likeValue((string) $args['company']) . '%\'';
        }
        if (!empty($args['phone'])) {
            $digits = preg_replace('/\D+/', '', (string) $args['phone']);
            if (strlen($digits) < 4) {
                throw new ToolException('phone needs at least 4 digits.');
            }
            // Compare digits only: strip common separators in SQL.
            $col = function ($c) {
                return 'REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(a.`' . $c . '`, \' \', \'\'), \'-\', \'\'), \'+\', \'\'), \'(\', \'\'), \')\', \'\')';
            };
            $where[] = 'EXISTS (SELECT 1 FROM `' . $p . 'address` a WHERE a.`id_customer` = c.`id_customer` AND a.`deleted` = 0 AND ('
                . $col('phone') . ' LIKE \'%' . self::likeValue((string) $digits) . '%\' OR ' . $col('phone_mobile') . ' LIKE \'%' . self::likeValue((string) $digits) . '%\'))';
        }
        if (!empty($args['id_customer'])) {
            $where[] = 'c.`id_customer` = ' . (int) $args['id_customer'];
        }
        if (!empty($args['id_group'])) {
            $where[] = 'EXISTS (SELECT 1 FROM `' . $p . 'customer_group` cg WHERE cg.`id_customer` = c.`id_customer` AND cg.`id_group` = ' . (int) $args['id_group'] . ')';
        }
        foreach (['newsletter', 'active', 'is_guest'] as $flag) {
            if (isset($args[$flag])) {
                $where[] = 'c.`' . $flag . '` = ' . ($args[$flag] ? 1 : 0);
            }
        }
        $w = implode(' AND ', $where);
        $total = (int) $this->db()->getValue('SELECT COUNT(*) FROM `' . $p . 'customer` c WHERE ' . $w);
        $rows = $this->db()->executeS(
            'SELECT c.`id_customer`, c.`firstname`, c.`lastname`, c.`email`, c.`company`, c.`active`, c.`newsletter`, c.`optin`, c.`is_guest`, c.`deleted`,
                c.`id_default_group`, gl.`name` AS group_name, c.`id_shop`, c.`date_add`,
                (SELECT COUNT(*) FROM `' . $p . 'orders` o WHERE o.`id_customer` = c.`id_customer` AND o.`valid` = 1) AS valid_orders
             FROM `' . $p . 'customer` c
             LEFT JOIN `' . $p . 'group_lang` gl ON gl.`id_group` = c.`id_default_group` AND gl.`id_lang` = ' . (int) $ctx->idLang . '
             WHERE ' . $w . ' ORDER BY c.`id_customer` DESC LIMIT ' . (int) $args['offset'] . ', ' . (int) $args['limit']
        ) ?: [];
        $items = [];
        foreach ($rows as $r) {
            $item = [
                'id_customer' => (int) $r['id_customer'],
                'name' => trim($r['firstname'] . ' ' . $r['lastname']),
                'email' => $r['email'],
                'company' => $r['company'] ?: null,
                'active' => (bool) $r['active'],
                'newsletter' => (bool) $r['newsletter'],
                'optin' => (bool) $r['optin'],
                'is_guest' => (bool) $r['is_guest'],
                'default_group' => ['id' => (int) $r['id_default_group'], 'name' => $r['group_name']],
                'valid_orders' => (int) $r['valid_orders'],
                'date_add' => $r['date_add'],
                'id_shop' => (int) $r['id_shop'],
            ];
            if ($r['deleted']) {
                $item['deleted'] = true;
            }
            $items[] = $item;
        }

        return $this->paged($items, $total, $args, 'customers');
    }

    public function customerGet(array $args, ExecutionContext $ctx): array
    {
        $p = _DB_PREFIX_;
        $db = $this->db();
        $idLang = (int) $ctx->idLang;
        $others = [];
        if (!empty($args['id_customer'])) {
            $id = (int) $args['id_customer'];
        } elseif (!empty($args['email'])) {
            $ids = $db->executeS('SELECT `id_customer` FROM `' . $p . 'customer` WHERE `email` = \'' . pSQL(trim($args['email'])) . '\' AND `deleted` = 0 ORDER BY `is_guest` ASC, `id_customer` DESC') ?: [];
            if (!$ids) {
                throw new ToolException(sprintf('No customer with email "%s".', $args['email']), 'Use customer_search with a partial email.');
            }
            $id = (int) $ids[0]['id_customer'];
            foreach (array_slice($ids, 1) as $o) {
                $others[] = (int) $o['id_customer'];
            }
        } else {
            throw new ToolException('Pass id_customer or email.');
        }
        $c = $db->getRow('SELECT ' . self::SAFE_COLUMNS . ' FROM `' . $p . 'customer` WHERE `id_customer` = ' . $id);
        if (!$c) {
            throw new ToolException(sprintf('Customer #%d does not exist.', $id), 'Use customer_search.');
        }
        if (!$ctx->apiKey->allowsShop((int) $c['id_shop'])) {
            throw new ToolException(sprintf('Customer #%d belongs to shop #%d, which this API key may not access.', $id, (int) $c['id_shop']));
        }

        $groups = [];
        foreach ($db->executeS(
            'SELECT cg.`id_group`, gl.`name` FROM `' . $p . 'customer_group` cg
             LEFT JOIN `' . $p . 'group_lang` gl ON gl.`id_group` = cg.`id_group` AND gl.`id_lang` = ' . $idLang . '
             WHERE cg.`id_customer` = ' . $id . ' ORDER BY cg.`id_group`'
        ) ?: [] as $g) {
            $groups[] = ['id_group' => (int) $g['id_group'], 'name' => $g['name'], 'default' => (int) $g['id_group'] === (int) $c['id_default_group']];
        }

        $addresses = [];
        foreach ($db->executeS('SELECT `id_address` FROM `' . $p . 'address` WHERE `id_customer` = ' . $id . ' AND `deleted` = 0 ORDER BY `id_address`') ?: [] as $a) {
            $addresses[] = self::addressRow((int) $a['id_address'], $idLang);
        }

        $rate = 'IF(`conversion_rate` > 0, `conversion_rate`, 1)';
        $stats = $db->getRow(
            'SELECT COUNT(*) AS all_orders, SUM(`valid` = 1) AS valid_orders, SUM(IF(`valid` = 1, `total_paid_tax_incl` / ' . $rate . ', 0)) AS spent_incl,
                SUM(IF(`valid` = 1, `total_paid_tax_excl` / ' . $rate . ', 0)) AS spent_excl, MIN(IF(`valid` = 1, `date_add`, NULL)) AS first_order, MAX(`date_add`) AS last_order
             FROM `' . $p . 'orders` WHERE `id_customer` = ' . $id
        );
        $shop = new \Shop((int) $ctx->idShop);
        $idCurrency = (int) \Configuration::get('PS_CURRENCY_DEFAULT', null, (int) $shop->id_shop_group, (int) $ctx->idShop);

        $lastOrders = [];
        foreach ($db->executeS(
            'SELECT o.`id_order`, o.`reference`, o.`date_add`, o.`total_paid_tax_incl`, o.`id_currency`, o.`current_state`, osl.`name`, o.`valid`, o.`payment`, o.`id_shop`
             FROM `' . $p . 'orders` o
             LEFT JOIN `' . $p . 'order_state_lang` osl ON osl.`id_order_state` = o.`current_state` AND osl.`id_lang` = ' . $idLang . '
             WHERE o.`id_customer` = ' . $id . ' ORDER BY o.`date_add` DESC LIMIT 5'
        ) ?: [] as $o) {
            $lastOrders[] = [
                'id_order' => (int) $o['id_order'],
                'reference' => $o['reference'],
                'date_add' => $o['date_add'],
                'total_paid_tax_incl' => $this->money((float) $o['total_paid_tax_incl'], (int) $o['id_currency']),
                'status' => $o['name'],
                'valid' => (bool) $o['valid'],
                'payment' => $o['payment'],
            ];
        }

        $carts = [];
        foreach ($db->executeS(
            'SELECT c.`id_cart`, c.`date_upd`, c.`id_currency`,
                (SELECT SUM(cp.`quantity`) FROM `' . $p . 'cart_product` cp WHERE cp.`id_cart` = c.`id_cart`) AS items
             FROM `' . $p . 'cart` c
             WHERE c.`id_customer` = ' . $id . ' AND NOT EXISTS (SELECT 1 FROM `' . $p . 'orders` o WHERE o.`id_cart` = c.`id_cart`)
             ORDER BY c.`date_upd` DESC LIMIT 5'
        ) ?: [] as $ct) {
            $carts[] = ['id_cart' => (int) $ct['id_cart'], 'date_upd' => $ct['date_upd'], 'items' => (int) $ct['items']];
        }

        $lastVisit = $db->getValue(
            'SELECT MAX(co.`date_add`) FROM `' . $p . 'guest` g JOIN `' . $p . 'connections` co ON co.`id_guest` = g.`id_guest` WHERE g.`id_customer` = ' . $id
        );
        $genderName = null;
        if ((int) $c['id_gender']) {
            $genderName = $db->getValue('SELECT `name` FROM `' . $p . 'gender_lang` WHERE `id_gender` = ' . (int) $c['id_gender'] . ' AND `id_lang` = ' . $idLang);
        }

        $out = [
            'id_customer' => $id,
            'shop' => ['id_shop' => (int) $c['id_shop'], 'id_shop_group' => (int) $c['id_shop_group']],
            'identity' => [
                'gender' => $genderName ?: null,
                'firstname' => $c['firstname'],
                'lastname' => $c['lastname'],
                'email' => $c['email'],
                'birthday' => ($c['birthday'] && $c['birthday'] !== '0000-00-00') ? $c['birthday'] : null,
                'company' => $c['company'] ?: null,
                'siret' => $c['siret'] ?: null,
                'ape' => $c['ape'] ?: null,
                'website' => $c['website'] ?: null,
                'id_lang' => (int) $c['id_lang'],
                'language' => (int) $c['id_lang'] ? $this->isoForLang((int) $c['id_lang']) : null,
            ],
            'active' => (bool) $c['active'],
            'is_guest' => (bool) $c['is_guest'],
            'deleted' => (bool) $c['deleted'],
            'newsletter' => (bool) $c['newsletter'],
            'newsletter_date_add' => $c['newsletter'] ? $c['newsletter_date_add'] : null,
            'optin' => (bool) $c['optin'],
            'date_add' => $c['date_add'],
            'date_upd' => $c['date_upd'],
            'last_visit' => $lastVisit ?: null,
            'default_group' => (int) $c['id_default_group'],
            'groups' => $groups,
            'b2b' => [
                'id_risk' => (int) $c['id_risk'],
                'outstanding_allow_amount' => (float) $c['outstanding_allow_amount'],
                'max_payment_days' => (int) $c['max_payment_days'],
                'show_public_prices' => (bool) $c['show_public_prices'],
            ],
            'private_note' => (string) $c['note'],
            'addresses' => $addresses,
            'stats' => [
                'orders_all_statuses' => (int) $stats['all_orders'],
                'valid_orders' => (int) $stats['valid_orders'],
                'total_spent_tax_incl' => $this->money((float) $stats['spent_incl'], $idCurrency),
                'total_spent_tax_excl' => round((float) $stats['spent_excl'], 2),
                'first_valid_order' => $stats['first_order'],
                'last_order' => $stats['last_order'],
            ],
            'last_orders' => $lastOrders,
            'carts_without_order' => $carts,
        ];
        if ($others) {
            $out['other_accounts_same_email'] = $others;
        }

        return $out;
    }

    public function groupList(array $args, ExecutionContext $ctx): array
    {
        $p = _DB_PREFIX_;
        $rows = $this->db()->executeS(
            'SELECT g.`id_group`, gl.`name`, g.`reduction`, g.`price_display_method`, g.`show_prices`, g.`date_add`,
                (SELECT COUNT(*) FROM `' . $p . 'customer_group` cg WHERE cg.`id_group` = g.`id_group`) AS members,
                (SELECT COUNT(*) FROM `' . $p . 'customer` c WHERE c.`id_default_group` = g.`id_group` AND c.`deleted` = 0) AS default_for
             FROM `' . $p . 'group` g
             JOIN `' . $p . 'group_shop` gs ON gs.`id_group` = g.`id_group` AND gs.`id_shop` = ' . (int) $ctx->idShop . '
             LEFT JOIN `' . $p . 'group_lang` gl ON gl.`id_group` = g.`id_group` AND gl.`id_lang` = ' . (int) $ctx->idLang . '
             ORDER BY g.`id_group`'
        ) ?: [];
        $shop = new \Shop((int) $ctx->idShop);
        $roles = [];
        foreach (['PS_UNIDENTIFIED_GROUP' => 'visitor', 'PS_GUEST_GROUP' => 'guest', 'PS_CUSTOMER_GROUP' => 'customer'] as $k => $role) {
            $roles[(int) \Configuration::get($k, null, (int) $shop->id_shop_group, (int) $ctx->idShop)] = $role;
        }
        $categoryReductions = [];
        foreach ($this->db()->executeS('SELECT `id_group`, COUNT(*) n FROM `' . $p . 'group_reduction` GROUP BY `id_group`') ?: [] as $gr) {
            $categoryReductions[(int) $gr['id_group']] = (int) $gr['n'];
        }
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id_group'];
            $out[] = [
                'id_group' => $id,
                'name' => $r['name'],
                'reduction_percent' => (float) $r['reduction'],
                'category_reductions' => isset($categoryReductions[$id]) ? $categoryReductions[$id] : 0,
                'price_display' => (int) $r['price_display_method'] === 1 ? 'tax_excl' : 'tax_incl',
                'show_prices' => (bool) $r['show_prices'],
                'members' => (int) $r['members'],
                'default_group_of' => (int) $r['default_for'],
                'system_role' => isset($roles[$id]) ? $roles[$id] : null,
            ];
        }

        return ['id_shop' => (int) $ctx->idShop, 'groups' => $out];
    }

    // ----------------------------------------------------------------- write

    public function customerUpdate(array $args, ExecutionContext $ctx): array
    {
        /** @var \Customer $customer */
        $customer = $this->load('Customer', (int) $args['id_customer'], null, null, 'Use customer_search.');
        $id = (int) $customer->id;
        if (!$ctx->apiKey->allowsShop((int) $customer->id_shop)) {
            throw new ToolException(sprintf('Customer #%d belongs to shop #%d, which this API key may not access.', $id, (int) $customer->id_shop));
        }
        if ($customer->deleted) {
            throw new ToolException(sprintf('Customer #%d is deleted.', $id));
        }

        $fieldChanges = [];
        foreach (['active', 'newsletter', 'optin'] as $f) {
            if (isset($args[$f]) && (bool) $customer->$f !== (bool) $args[$f]) {
                $fieldChanges[$f] = [(int) (bool) $customer->$f, (int) (bool) $args[$f]];
            }
        }
        if (isset($args['note']) && (string) $customer->note !== (string) $args['note']) {
            if (!\Validate::isCleanHtml($args['note'])) {
                throw new ToolException('note contains forbidden HTML/script content.');
            }
            $fieldChanges['note'] = [(string) $customer->note, (string) $args['note']];
        }

        $currentGroups = array_map('intval', $customer->getGroups());
        sort($currentGroups);
        $groups = $currentGroups;
        $defaultGroup = (int) $customer->id_default_group;
        $validGroups = array_map('intval', array_column(
            $this->db()->executeS('SELECT `id_group` FROM `' . _DB_PREFIX_ . 'group`') ?: [],
            'id_group'
        ));
        $check = function ($ids, $label) use ($validGroups) {
            foreach ($ids as $g) {
                if (!in_array((int) $g, $validGroups, true)) {
                    throw new ToolException(sprintf('%s: group #%d does not exist.', $label, (int) $g), 'Call customer_group_list.');
                }
            }
        };
        if (!empty($args['id_default_group'])) {
            $check([(int) $args['id_default_group']], 'id_default_group');
            $defaultGroup = (int) $args['id_default_group'];
        }
        if (!empty($args['add_groups'])) {
            $check($args['add_groups'], 'add_groups');
            $groups = array_merge($groups, array_map('intval', $args['add_groups']));
        }
        if (!empty($args['remove_groups'])) {
            if (in_array($defaultGroup, array_map('intval', $args['remove_groups']), true)) {
                throw new ToolException(sprintf('Group #%d is the default group and cannot be removed.', $defaultGroup), 'Set another id_default_group first or in the same call.');
            }
            $groups = array_diff($groups, array_map('intval', $args['remove_groups']));
        }
        if (!in_array($defaultGroup, $groups, true)) {
            $groups[] = $defaultGroup;
        }
        $groups = array_values(array_unique(array_map('intval', $groups)));
        sort($groups);
        if ($defaultGroup !== (int) $customer->id_default_group) {
            $fieldChanges['id_default_group'] = [(int) $customer->id_default_group, $defaultGroup];
        }
        $groupsChanged = $groups !== $currentGroups;

        $idShop = (int) $customer->id_shop;
        foreach ($fieldChanges as $f => $ba) {
            $ctx->recordChange('Customer', $id, $f, $ba[0], $ba[1], $idShop);
        }
        if ($groupsChanged) {
            $ctx->recordChange('Customer', $id, 'groups', $currentGroups, $groups, $idShop);
        }
        if (isset($fieldChanges['active']) && !$fieldChanges['active'][1]) {
            $ctx->addWarning('A disabled customer can no longer log in.');
        }

        $result = ['id_customer' => $id, 'email' => $customer->email, 'groups' => $groups, 'id_default_group' => $defaultGroup];
        if ($ctx->dryRun || (!$fieldChanges && !$groupsChanged)) {
            return $result;
        }

        if ($fieldChanges) {
            foreach ($fieldChanges as $f => $ba) {
                $customer->$f = $f === 'note' ? $ba[1] : ($f === 'id_default_group' ? (int) $ba[1] : (bool) $ba[1]);
            }
            try {
                $ok = $customer->update();
            } catch (\PrestaShopException $e) {
                throw new ToolException('PrestaShop refused to save the customer: ' . $e->getMessage(), 'The stored customer data may be invalid (e.g. name with digits); fix it in the back office.');
            }
            if (!$ok) {
                throw new ToolException('PrestaShop refused to save the customer (validation failed or another registered account uses the same email).');
            }
        }
        if ($groupsChanged) {
            $customer->updateGroup($groups);
        }

        return $result;
    }

    // --------------------------------------------------------------- helpers

    /**
     * One address as a compact array (shared with order and cart tools).
     */
    public static function addressRow(int $idAddress, int $idLang): ?array
    {
        if ($idAddress <= 0) {
            return null;
        }
        $p = _DB_PREFIX_;
        $a = \Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow(
            'SELECT a.`id_address`, a.`alias`, a.`company`, a.`firstname`, a.`lastname`, a.`address1`, a.`address2`, a.`postcode`, a.`city`,
                a.`other`, a.`phone`, a.`phone_mobile`, a.`vat_number`, a.`dni`, a.`id_country`, cl.`name` AS country, co.`iso_code` AS country_iso,
                a.`id_state`, s.`name` AS state, a.`deleted`
             FROM `' . $p . 'address` a
             LEFT JOIN `' . $p . 'country` co ON co.`id_country` = a.`id_country`
             LEFT JOIN `' . $p . 'country_lang` cl ON cl.`id_country` = a.`id_country` AND cl.`id_lang` = ' . (int) $idLang . '
             LEFT JOIN `' . $p . 'state` s ON s.`id_state` = a.`id_state`
             WHERE a.`id_address` = ' . (int) $idAddress
        );
        if (!$a) {
            return ['id_address' => $idAddress, 'error' => 'address not found'];
        }
        $out = [
            'id_address' => (int) $a['id_address'],
            'alias' => $a['alias'],
            'name' => trim($a['firstname'] . ' ' . $a['lastname']),
            'company' => $a['company'] ?: null,
            'address1' => $a['address1'],
            'address2' => $a['address2'] ?: null,
            'postcode' => $a['postcode'],
            'city' => $a['city'],
            'state' => $a['state'] ?: null,
            'country' => $a['country'],
            'country_iso' => $a['country_iso'],
            'phone' => $a['phone'] ?: null,
            'phone_mobile' => $a['phone_mobile'] ?: null,
            'vat_number' => $a['vat_number'] ?: null,
            'dni' => $a['dni'] ?: null,
            'other' => $a['other'] ?: null,
        ];
        if ($a['deleted']) {
            $out['deleted'] = true;
        }

        return $out;
    }
}
