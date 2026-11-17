<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Tool\Core;

use PrestaPro\PpMcp\Context\ExecutionContext;
use PrestaPro\PpMcp\Log\ActionLogger;
use PrestaPro\PpMcp\Tool\AbstractToolGroup;
use PrestaPro\PpMcp\Tool\ToolException;

/**
 * Shop-wide context: versions, shops, languages, currencies, key settings,
 * configuration values and the PrestaShop system log.
 */
class ShopTools extends AbstractToolGroup
{
    /** Settings that most often explain storefront behaviour. */
    const KEY_SETTINGS = [
        'PS_SHOP_ENABLE', 'PS_MAINTENANCE_IP', 'PS_CATALOG_MODE', 'PS_CATALOG_MODE_WITH_PRICES',
        'PS_STOCK_MANAGEMENT', 'PS_ORDER_OUT_OF_STOCK', 'PS_DISPLAY_QTIES', 'PS_PACK_STOCK_TYPE',
        'PS_GUEST_CHECKOUT_ENABLED', 'PS_PURCHASE_MINIMUM', 'PS_TAX', 'PS_TAX_DISPLAY', 'PS_ROUND_TYPE',
        'PS_PRICE_ROUND_MODE', 'PS_SSL_ENABLED', 'PS_REWRITING_SETTINGS', 'PS_GEOLOCATION_ENABLED',
        'PS_CUSTOMER_GROUP', 'PS_UNIDENTIFIED_GROUP', 'PS_GUEST_GROUP', 'PS_ORDER_PROCESS_TYPE',
        'PS_CONDITIONS', 'PS_ALLOW_MULTISHIPPING', 'PS_SHIPPING_FREE_PRICE', 'PS_SHIPPING_FREE_WEIGHT',
        'PS_CARRIER_DEFAULT', 'PS_COUNTRY_DEFAULT', 'PS_CURRENCY_DEFAULT', 'PS_LANG_DEFAULT', 'PS_TIMEZONE',
    ];

    public function owner(): string
    {
        return 'shop';
    }

    public function tools(): array
    {
        return [
            [
                'name' => 'shop_context_get',
                'title' => 'Shop overview and context',
                'description' => 'Start here. Returns PrestaShop/PHP versions, whether multistore is active, every shop (id, group, domains, default language/currency), languages and currencies per shop, and the key storefront settings (catalog mode, stock management, out-of-stock ordering, guest checkout, minimum purchase, tax display, maintenance). Use the ids it returns for id_shop and lang arguments.',
                'shop' => true,
                'keywords' => ['multistore', 'languages', 'currencies', 'version', 'settings'],
                'handler' => 'contextGet',
            ],
            [
                'name' => 'shop_config_get',
                'title' => 'Read configuration values',
                'description' => 'Reads PrestaShop Configuration values for the shop (and language for multilang keys), by exact keys or by prefix (e.g. "PS_STOCK", or a module prefix like "PPSLIDER_"). Secret-looking values (passwords, API keys, tokens) are masked. Read-only.',
                'params' => [
                    'keys' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 100, 'description' => 'Exact configuration names.'],
                    'prefix' => ['type' => 'string', 'minLength' => 3, 'description' => 'Return every key starting with this prefix (max 200).'],
                ],
                'shop' => true,
                'lang' => true,
                'permissions' => [['AdminPreferences', 'view'], ['AdminModulesSf', 'view'], ['AdminModules', 'view']],
                'keywords' => ['setting', 'option', 'parameter'],
                'handler' => 'configGet',
            ],
            [
                'name' => 'shop_log_list',
                'title' => 'PrestaShop system log',
                'description' => 'Reads the PrestaShop log (Advanced Parameters > Logs): errors, warnings and info messages from core and modules, newest first. Filter by severity (1 info, 2 warning, 3 error, 4 major), text, object type/id (e.g. object_type "Cart" + object_id shows why a cart/payment failed) and date. Use it when debugging failures.',
                'params' => array_merge([
                    'severity_min' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4, 'description' => 'Minimum severity.'],
                    'search' => ['type' => 'string', 'description' => 'Text contained in the message.'],
                    'object_type' => ['type' => 'string', 'description' => 'e.g. Cart, Order, Product, Customer, or a module name.'],
                    'object_id' => ['type' => 'integer'],
                    'date_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                ], self::paginationParams(30, 200)),
                'permission' => ['AdminLogs', 'view'],
                'keywords' => ['error', 'debug', 'exception', 'warning'],
                'handler' => 'logList',
            ],
        ];
    }

    public function contextGet(array $args, ExecutionContext $ctx): array
    {
        $shops = [];
        foreach (\Shop::getShops(false) as $s) {
            $id = (int) $s['id_shop'];
            if (!$ctx->apiKey->allowsShop($id)) {
                continue;
            }
            $langs = [];
            foreach (\Language::getLanguages(false, $id) as $l) {
                $langs[] = ['id_lang' => (int) $l['id_lang'], 'iso_code' => $l['iso_code'], 'name' => $l['name'], 'active' => (bool) $l['active']];
            }
            $currencies = [];
            foreach (\Currency::getCurrenciesByIdShop($id) as $c) {
                $currencies[] = ['id_currency' => (int) $c['id_currency'], 'iso_code' => $c['iso_code'], 'active' => (bool) $c['active'], 'conversion_rate' => (float) $c['conversion_rate']];
            }
            $group = (int) $s['id_shop_group'];
            $shops[] = [
                'id_shop' => $id,
                'name' => $s['name'],
                'id_shop_group' => $group,
                'group_name' => isset($s['group_name']) ? $s['group_name'] : null,
                'active' => (bool) $s['active'],
                'domain' => $s['domain'],
                'domain_ssl' => $s['domain_ssl'],
                'uri' => $s['uri'],
                'default_lang' => (int) \Configuration::get('PS_LANG_DEFAULT', null, $group, $id),
                'default_currency' => (int) \Configuration::get('PS_CURRENCY_DEFAULT', null, $group, $id),
                'languages' => $langs,
                'currencies' => $currencies,
                'is_current' => $id === (int) $ctx->idShop,
            ];
        }
        $shop = new \Shop((int) $ctx->idShop);
        $settings = [];
        foreach (self::KEY_SETTINGS as $k) {
            $settings[$k] = \Configuration::get($k, null, (int) $shop->id_shop_group, (int) $ctx->idShop);
        }

        return [
            'prestashop_version' => _PS_VERSION_,
            'php_version' => PHP_VERSION,
            'multistore_active' => (bool) \Shop::isFeatureActive(),
            'current_shop' => (int) $ctx->idShop,
            'current_lang' => ['id_lang' => (int) $ctx->idLang, 'iso_code' => $this->isoForLang((int) $ctx->idLang)],
            'shops' => $shops,
            'settings' => $settings,
            'settings_legend' => [
                'PS_ORDER_OUT_OF_STOCK' => '1 = products out of stock can be ordered by default (product out_of_stock=2 follows this)',
                'PS_CATALOG_MODE' => '1 = nobody can add to cart',
                'PS_TAX_DISPLAY' => 'show tax in cart',
            ],
        ];
    }

    public function configGet(array $args, ExecutionContext $ctx): array
    {
        $keys = isset($args['keys']) ? $args['keys'] : [];
        if (!empty($args['prefix'])) {
            $rows = $this->db()->executeS(
                'SELECT DISTINCT `name` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` LIKE \'' . self::likeValue(strtoupper((string) $args['prefix'])) . '%\' ORDER BY `name` LIMIT 200'
            ) ?: [];
            foreach ($rows as $r) {
                $keys[] = $r['name'];
            }
        }
        if (!$keys) {
            throw new ToolException('Pass "keys" or "prefix".');
        }
        $shop = new \Shop((int) $ctx->idShop);
        $out = [];
        foreach (array_unique($keys) as $k) {
            $k = (string) $k;
            if (!\Validate::isConfigName($k)) {
                $out[$k] = ['error' => 'invalid key name'];
                continue;
            }
            $value = \Configuration::get($k, null, (int) $shop->id_shop_group, (int) $ctx->idShop);
            if ($value === false && \Configuration::isLangKey($k)) {
                $value = \Configuration::get($k, (int) $ctx->idLang, (int) $shop->id_shop_group, (int) $ctx->idShop);
            }
            if (preg_match(ActionLogger::SECRET_KEYS, $k) && $value !== false && $value !== '') {
                $value = '***masked (' . strlen((string) $value) . ' chars)***';
            }
            $out[$k] = $value === false ? null : $value;
        }

        return ['id_shop' => (int) $ctx->idShop, 'values' => $out, 'note' => 'null = key not set'];
    }

    public function logList(array $args, ExecutionContext $ctx): array
    {
        $where = ['1'];
        if (!empty($args['severity_min'])) {
            $where[] = '`severity` >= ' . (int) $args['severity_min'];
        }
        if (!empty($args['search'])) {
            $where[] = '`message` LIKE \'%' . self::likeValue((string) $args['search']) . '%\'';
        }
        if (!empty($args['object_type'])) {
            $where[] = '`object_type` = \'' . pSQL($args['object_type']) . '\'';
        }
        if (!empty($args['object_id'])) {
            $where[] = '`object_id` = ' . (int) $args['object_id'];
        }
        if (!empty($args['date_from'])) {
            $ts = strtotime($args['date_from']);
            if ($ts === false) {
                throw new ToolException('date_from is not a valid date.');
            }
            $where[] = '`date_add` >= \'' . pSQL(date('Y-m-d H:i:s', $ts)) . '\'';
        }
        $w = implode(' AND ', $where);
        $total = (int) $this->db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'log` WHERE ' . $w);
        $rows = $this->db()->executeS(
            'SELECT `id_log`, `severity`, `error_code`, `message`, `object_type`, `object_id`, `id_employee`, `date_add`
             FROM `' . _DB_PREFIX_ . 'log` WHERE ' . $w . ' ORDER BY `id_log` DESC LIMIT ' . (int) $args['offset'] . ', ' . (int) $args['limit']
        ) ?: [];
        foreach ($rows as &$r) {
            $r['id_log'] = (int) $r['id_log'];
            $r['severity'] = (int) $r['severity'];
            $r['object_id'] = (int) $r['object_id'];
            $r['message'] = mb_substr($r['message'], 0, 2000);
        }

        return $this->paged($rows, $total, $args, 'entries');
    }
}
