<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Tool;

use PrestaPro\PpMcp\Context\ExecutionContext;

/**
 * Base for core tool groups. A group owns one domain prefix (catalog, order…)
 * and returns tool definitions in the same array format third-party modules
 * use via PpMcpToolProviderTrait; 'handler' is a public method of the group:
 *
 *   public function productGet(array $args, ExecutionContext $ctx)
 */
abstract class AbstractToolGroup
{
    /** @var \Module */
    protected $module;

    public function __construct(\Module $module)
    {
        $this->module = $module;
    }

    /**
     * Domain prefix every tool name of this group starts with.
     */
    abstract public function owner(): string;

    /**
     * @return array[] tool definitions
     */
    abstract public function tools(): array;

    protected function db(): \Db
    {
        return \Db::getInstance(_PS_USE_SQL_SLAVE_);
    }

    protected function dbWrite(): \Db
    {
        return \Db::getInstance();
    }

    protected function context(): \Context
    {
        return \Context::getContext();
    }

    /**
     * Limit/offset arguments shared by every list/search tool.
     */
    protected static function paginationParams(int $defaultLimit = 20, int $maxLimit = 100): array
    {
        return [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => $maxLimit, 'default' => $defaultLimit, 'description' => 'Max rows to return (1-' . $maxLimit . ').'],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Rows to skip, for paging.'],
        ];
    }

    /**
     * Standard paged response envelope.
     */
    protected function paged(array $items, int $total, array $args, string $key = 'items'): array
    {
        $offset = isset($args['offset']) ? (int) $args['offset'] : 0;
        $out = [
            'total' => $total,
            'offset' => $offset,
            'count' => count($items),
            $key => $items,
        ];
        if ($offset + count($items) < $total) {
            $out['next_offset'] = $offset + count($items);
        }

        return $out;
    }

    /**
     * Load an ObjectModel or fail with a model-friendly message.
     *
     * @return \ObjectModel
     *
     * @throws ToolException
     */
    protected function load(string $class, int $id, ?int $idLang = null, ?int $idShop = null, string $hint = '')
    {
        if ($id <= 0) {
            throw new ToolException(sprintf('%s id must be a positive integer.', $class));
        }
        if ($class === 'Product') {
            // $full = false on purpose: with true, Product::__construct replaces ->price
            // by the computed final price, and a later save() would corrupt the base price.
            $obj = new \Product($id, false, $idLang, $idShop);
        } else {
            $obj = new $class($id, $idLang, $idShop);
        }
        if (!\Validate::isLoadedObject($obj)) {
            throw new ToolException(sprintf('%s #%d does not exist.', $class, $id), $hint ?: null);
        }

        return $obj;
    }

    /**
     * Languages a multilang value can be written in, keyed by ISO code.
     *
     * @return array iso => id_lang
     */
    protected function languageMap(): array
    {
        $map = [];
        foreach (\Language::getLanguages(false) as $l) {
            $map[strtolower($l['iso_code'])] = (int) $l['id_lang'];
        }

        return $map;
    }

    /**
     * Normalise a multilang input to [id_lang => value].
     * Accepts a plain string (applied to $ctx->idLang) or {"en": "…", "lt": "…"}
     * keyed by ISO code or id_lang.
     *
     * @param mixed $value
     *
     * @throws ToolException
     */
    protected function multilangInput($value, ExecutionContext $ctx, string $field): array
    {
        if (!is_array($value)) {
            return [(int) $ctx->idLang => (string) $value];
        }
        $map = $this->languageMap();
        $out = [];
        foreach ($value as $key => $v) {
            if (is_numeric($key) && in_array((int) $key, $map, true)) {
                $out[(int) $key] = (string) $v;
            } elseif (isset($map[strtolower((string) $key)])) {
                $out[$map[strtolower((string) $key)]] = (string) $v;
            } else {
                throw new ToolException(
                    sprintf('%s: unknown language "%s".', $field, $key),
                    'Use ISO codes or id_lang: ' . implode(', ', array_keys($map)) . ' (shop_context_get lists languages per shop).'
                );
            }
        }

        return $out;
    }

    /**
     * Shop restriction of the API key for records found by id (which may live in
     * any shop). Keys without a shop list may access everything.
     *
     * @throws PermissionDeniedException
     */
    protected function assertShopAllowed(ExecutionContext $ctx, int $idShop, string $what): void
    {
        if ($idShop > 0 && !$ctx->apiKey->allowsShop($idShop)) {
            throw new PermissionDeniedException(sprintf('%s belongs to shop #%d, which this API key may not access.', $what, $idShop));
        }
    }

    /**
     * Data stored for "all shops" (id_shop = 0) can only be written by keys
     * that are not restricted to a list of shops.
     *
     * @throws PermissionDeniedException
     */
    protected function assertAllShopsAllowed(ExecutionContext $ctx, string $what): void
    {
        if ($ctx->apiKey->getShopIds()) {
            throw new PermissionDeniedException(
                sprintf('%s applies to all shops; this API key is restricted to shops %s.', $what, implode(', ', $ctx->apiKey->getShopIds())),
                'Use a key without shop restriction, or work on one shop only.'
            );
        }
    }

    /**
     * A cart rule is accessible when it is not shop-restricted or is linked to
     * a shop the key may access.
     *
     * @throws PermissionDeniedException
     */
    protected function assertCartRuleAllowed(ExecutionContext $ctx, int $idCartRule): void
    {
        $allowed = $ctx->apiKey->getShopIds();
        if (!$allowed) {
            return;
        }
        $restricted = (int) $this->db()->getValue('SELECT `shop_restriction` FROM `' . _DB_PREFIX_ . 'cart_rule` WHERE `id_cart_rule` = ' . (int) $idCartRule);
        if (!$restricted) {
            return;
        }
        $hit = (int) $this->db()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cart_rule_shop` WHERE `id_cart_rule` = ' . (int) $idCartRule
            . ' AND `id_shop` IN (' . implode(',', array_map('intval', $allowed)) . ')'
        );
        if (!$hit) {
            throw new PermissionDeniedException(sprintf('Cart rule #%d is only valid in shops this API key may not access.', $idCartRule));
        }
    }

    protected function isoForLang(int $idLang): string
    {
        $iso = \Language::getIsoById($idLang);

        return $iso ? (string) $iso : (string) $idLang;
    }

    /**
     * Price formatting that works on PS 1.7 (Tools::displayPrice) and 8/9
     * (Locale), returned next to the raw float.
     */
    protected function money(float $amount, ?int $idCurrency = null): array
    {
        $currency = $idCurrency ? new \Currency($idCurrency) : $this->context()->currency;
        if (!\Validate::isLoadedObject($currency)) {
            $currency = new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT'));
        }
        $formatted = null;
        try {
            $ctx = $this->context();
            if (method_exists($ctx, 'getCurrentLocale') && $ctx->getCurrentLocale()) {
                $formatted = $ctx->getCurrentLocale()->formatPrice($amount, $currency->iso_code);
            }
        } catch (\Throwable $e) {
            $formatted = null;
        }
        if ($formatted === null) {
            $formatted = number_format($amount, 2, '.', ' ') . ' ' . $currency->iso_code;
        }

        return ['amount' => round($amount, 6), 'currency' => $currency->iso_code, 'formatted' => $formatted];
    }

    /**
     * Value for a LIKE pattern: wildcards escaped first, then SQL-escaped, so
     * "_" or "%" typed by the model match literally.
     */
    protected static function likeValue(string $s): string
    {
        return pSQL(addcslashes(trim($s), '%_\\'));
    }

    protected static function boolParam(string $description, ?bool $default = null): array
    {
        $p = ['type' => 'boolean', 'description' => $description];
        if ($default !== null) {
            $p['default'] = $default;
        }

        return $p;
    }

    protected static function idParam(string $description, bool $required = true): array
    {
        return ['type' => 'integer', 'minimum' => 1, 'description' => $description, 'required' => $required];
    }
}
