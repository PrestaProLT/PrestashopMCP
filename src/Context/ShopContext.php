<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Context;

use PrestaPro\PpMcp\Tool\ToolException;

/**
 * Switches Context shop / language for one tool call and restores it after.
 * Multistore: Shop::setContext(CONTEXT_SHOP) so ObjectModel saves and
 * Configuration reads target exactly that shop.
 */
class ShopContext
{
    /** @var array|null */
    private $saved;

    public function __construct()
    {
        $c = \Context::getContext();
        $this->saved = [
            'shop' => $c->shop,
            'language' => $c->language,
            'currency' => $c->currency,
            'shop_context' => \Shop::getContext(),
            'shop_context_id' => \Shop::getContext() === \Shop::CONTEXT_SHOP ? (int) \Shop::getContextShopID() : (\Shop::getContext() === \Shop::CONTEXT_GROUP ? (int) \Shop::getContextShopGroupID() : null),
        ];
    }

    /**
     * @throws ToolException
     */
    public function switchShop(int $idShop): int
    {
        $shop = new \Shop($idShop);
        if (!\Validate::isLoadedObject($shop)) {
            throw new ToolException(sprintf('Shop #%d does not exist.', $idShop), 'Call shop_context_get to list shops.');
        }
        $c = \Context::getContext();
        if ((int) $c->shop->id !== $idShop || \Shop::getContext() !== \Shop::CONTEXT_SHOP) {
            // Both are needed: SQL shop joins read the static context,
            // Product::getPriceStatic/Cart/Hook read Context->shop.
            $c->shop = $shop;
            \Shop::setContext(\Shop::CONTEXT_SHOP, $idShop);
            $currency = new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT', null, (int) $shop->id_shop_group, $idShop));
            if (\Validate::isLoadedObject($currency)) {
                $c->currency = $currency;
            }
            self::flushCaches();
        }

        return $idShop;
    }

    /**
     * @param string|int|null $lang ISO code or id_lang; null = shop default
     *
     * @throws ToolException
     */
    public function switchLanguage($lang, int $idShop): int
    {
        $idLang = self::resolveLanguageId($lang, $idShop);
        $c = \Context::getContext();
        if ((int) $c->language->id !== $idLang) {
            $c->language = new \Language($idLang);
        }

        return $idLang;
    }

    /**
     * @param string|int|null $lang
     *
     * @throws ToolException
     */
    public static function resolveLanguageId($lang, int $idShop): int
    {
        if ($lang === null || $lang === '') {
            $shop = new \Shop($idShop);

            return (int) \Configuration::get('PS_LANG_DEFAULT', null, (int) $shop->id_shop_group, $idShop);
        }
        if (is_int($lang) || ctype_digit((string) $lang)) {
            $id = (int) $lang;
            if (\Language::getLanguage($id)) {
                return $id;
            }
        } else {
            $id = (int) \Language::getIdByIso(strtolower((string) $lang));
            if ($id) {
                return $id;
            }
        }
        $known = [];
        foreach (\Language::getLanguages(false, $idShop) as $l) {
            $known[] = $l['iso_code'] . ' (id ' . $l['id_lang'] . ')';
        }
        throw new ToolException(sprintf('Unknown language "%s".', $lang), 'Known languages: ' . implode(', ', $known));
    }

    /**
     * Static caches keyed without shop id (Module::isEnabled, prices,
     * Product::checkAccess…) go stale after a shop switch.
     */
    public static function flushCaches(): void
    {
        \Cache::clean('*');
        \Product::flushPriceCache();
        if (method_exists('Product', 'resetStaticCache')) {
            \Product::resetStaticCache();
        }
    }

    public function restore(): void
    {
        if (!$this->saved) {
            return;
        }
        $c = \Context::getContext();
        $switched = $c->shop !== $this->saved['shop'];
        $c->shop = $this->saved['shop'];
        $c->language = $this->saved['language'];
        $c->currency = $this->saved['currency'];
        if ($this->saved['shop_context'] === \Shop::CONTEXT_ALL) {
            \Shop::setContext(\Shop::CONTEXT_ALL);
        } elseif ($this->saved['shop_context_id']) {
            \Shop::setContext($this->saved['shop_context'], $this->saved['shop_context_id']);
        }
        $this->saved = null;
        if ($switched) {
            self::flushCaches();
        }
    }
}
