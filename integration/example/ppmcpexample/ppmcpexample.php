<?php
/**
 * PrestaPro MCP example — how a module exposes its own MCP tools.
 *
 * A tiny promo banner (text per language, shown on desktop and/or mobile).
 * It exposes:
 *   ppmcpexample_banner_get     read  — current banner per language and device
 *   ppmcpexample_banner_update  write — change text / devices (supports dry_run)
 * and ships one skill (mcp/skills/banner_change.md).
 *
 * Copy mcp/PpMcpToolProviderTrait.php into your module: it is dependency-free,
 * so your module keeps working when ppmcp is not installed.
 *
 * @author    PrestaPro
 * @license   AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/mcp/PpMcpToolProviderTrait.php';

class PpMcpExample extends Module
{
    use PpMcpToolProviderTrait;

    const CFG_TEXT = 'PPMCPEXAMPLE_TEXT';
    const CFG_DESKTOP = 'PPMCPEXAMPLE_DESKTOP';
    const CFG_MOBILE = 'PPMCPEXAMPLE_MOBILE';

    public function __construct()
    {
        $this->name = 'ppmcpexample';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'PrestaPro';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
        parent::__construct();
        $this->displayName = $this->trans('PrestaPro MCP example banner', [], 'Modules.Ppmcpexample.Admin');
        $this->description = $this->trans('Example of a module providing MCP tools through PrestaPro MCP.', [], 'Modules.Ppmcpexample.Admin');
        $this->confirmUninstall = $this->trans('Uninstalling deletes the banner texts. This cannot be undone.', [], 'Modules.Ppmcpexample.Admin');
    }

    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('displayBanner')
            && $this->ppMcpRegisterHook()          // makes the tools visible to ppmcp
            && Configuration::updateValue(self::CFG_DESKTOP, 1)
            && Configuration::updateValue(self::CFG_MOBILE, 1);
    }

    public function reset()
    {
        return $this->ppMcpRegisterHook();
    }

    public function uninstall()
    {
        foreach ([self::CFG_TEXT, self::CFG_DESKTOP, self::CFG_MOBILE] as $k) {
            Configuration::deleteByName($k);
        }

        return parent::uninstall();
    }

    public function hookDisplayBanner($params)
    {
        $isMobile = $this->context->isMobile();
        if (($isMobile && !Configuration::get(self::CFG_MOBILE)) || (!$isMobile && !Configuration::get(self::CFG_DESKTOP))) {
            return '';
        }
        $text = Configuration::get(self::CFG_TEXT, (int) $this->context->language->id);

        return $text ? '<div class="ppmcpexample-banner">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>' : '';
    }

    /* ---------------- MCP ---------------- */

    public function ppMcpTools()
    {
        return [
            [
                'name' => 'banner_get',
                'title' => 'Get promo banner',
                'description' => 'Returns the promo banner text in every language and whether it is shown on desktop and on mobile, for the shop.',
                'shop' => true,
                'permission' => ['AdminModules', 'view'],
                'keywords' => ['promo', 'header', 'announcement', 'mobile', 'desktop'],
                'handler' => 'mcpBannerGet',
            ],
            [
                'name' => 'banner_update',
                'title' => 'Update promo banner',
                'description' => 'Changes the promo banner text (per language: {"en": "…", "lt": "…"}) and/or its visibility on desktop and mobile, for the shop. Use dry_run=true to preview.',
                'params' => [
                    'text' => ['type' => 'object', 'description' => 'Texts keyed by language ISO code. Omitted languages stay unchanged.'],
                    'show_on_desktop' => ['type' => 'boolean'],
                    'show_on_mobile' => ['type' => 'boolean'],
                ],
                'write' => true,
                'dry_run' => true,
                'shop' => true,
                'permission' => ['AdminModules', 'edit'],
                'keywords' => ['promo', 'header', 'announcement', 'mobile', 'desktop'],
                'handler' => 'mcpBannerUpdate',
            ],
        ];
    }

    public function mcpBannerGet(array $args, array $context)
    {
        $idShop = (int) $context['id_shop'];
        $idGroup = (int) Shop::getGroupFromShop($idShop);
        $texts = [];
        foreach (Language::getLanguages(false, $idShop) as $l) {
            $texts[$l['iso_code']] = (string) Configuration::get(self::CFG_TEXT, (int) $l['id_lang'], $idGroup, $idShop);
        }

        return [
            'id_shop' => $idShop,
            'text' => $texts,
            'show_on_desktop' => (bool) Configuration::get(self::CFG_DESKTOP, null, $idGroup, $idShop),
            'show_on_mobile' => (bool) Configuration::get(self::CFG_MOBILE, null, $idGroup, $idShop),
        ];
    }

    public function mcpBannerUpdate(array $args, array $context)
    {
        $idShop = (int) $context['id_shop'];
        $idGroup = (int) Shop::getGroupFromShop($idShop);
        $changes = [];

        if (isset($args['text'])) {
            $values = [];
            foreach ($args['text'] as $iso => $text) {
                $idLang = (int) Language::getIdByIso((string) $iso);
                if (!$idLang) {
                    $this->ppMcpFail('Unknown language "' . $iso . '".', 'Use ISO codes such as en, lt.');
                }
                $before = (string) Configuration::get(self::CFG_TEXT, $idLang, $idGroup, $idShop);
                $after = trim(strip_tags((string) $text));
                $values[$idLang] = $after;
                $changes[] = $this->ppMcpChange('ppmcpexample_banner', $idShop, 'text', $before, $after, $idShop, $idLang);
            }
            if (!$context['dry_run']) {
                // keep languages that were not passed
                foreach (Language::getLanguages(false, $idShop) as $l) {
                    if (!isset($values[(int) $l['id_lang']])) {
                        $values[(int) $l['id_lang']] = (string) Configuration::get(self::CFG_TEXT, (int) $l['id_lang'], $idGroup, $idShop);
                    }
                }
                Configuration::updateValue(self::CFG_TEXT, $values, false, $idGroup, $idShop);
            }
        }
        foreach (['show_on_desktop' => self::CFG_DESKTOP, 'show_on_mobile' => self::CFG_MOBILE] as $arg => $key) {
            if (isset($args[$arg])) {
                $before = (bool) Configuration::get($key, null, $idGroup, $idShop);
                $changes[] = $this->ppMcpChange('ppmcpexample_banner', $idShop, $arg, $before, (bool) $args[$arg], $idShop);
                if (!$context['dry_run']) {
                    Configuration::updateValue($key, (int) $args[$arg], false, $idGroup, $idShop);
                }
            }
        }

        return $this->ppMcpResult($this->mcpBannerGet([], $context), $changes);
    }
}
