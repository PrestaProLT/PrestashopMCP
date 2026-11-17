<?php
/**
 * PrestaPro MCP Server — Model Context Protocol server for PrestaShop 1.7 / 8 / 9.
 *
 * Lets AI assistants (Claude Code, Claude Desktop, claude.ai connectors,
 * Cursor…) help shop managers with catalog, orders, customers, carts,
 * diagnostics and module insight. Other modules add their own tools with
 * PpMcpToolProviderTrait. Every call is logged with what it changed.
 *
 * @author    PrestaPro
 * @copyright PrestaPro
 * @license   AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/autoload.php';

use PrestaPro\PpMcp\Install\Installer;
use PrestaPro\PpMcp\Install\Uninstaller;
use PrestaPro\PpMcp\Log\ActionLogger;

class PpMcp extends Module
{
    const TRANSLATION_DOMAIN = 'Modules.Ppmcp.Admin';

    public function __construct()
    {
        $this->name = 'ppmcp';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'PrestaPro';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->trans('PrestaPro MCP Server', [], self::TRANSLATION_DOMAIN);
        $this->description = $this->trans('Model Context Protocol (MCP) server for shop managers: catalog, orders, customers, diagnostics and module insight for AI assistants.', [], self::TRANSLATION_DOMAIN);
        $this->confirmUninstall = $this->trans('Uninstalling permanently deletes all MCP API keys and the full MCP activity log. AI assistants will lose access immediately. This cannot be undone.', [], self::TRANSLATION_DOMAIN);
    }

    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }
        $installer = new Installer($this);
        if (!$installer->install(true)) {
            $this->_errors = array_merge($this->_errors, $installer->errors);

            return false;
        }

        return true;
    }

    /**
     * Module Manager "Reset": restore default settings, keep keys and logs.
     */
    public function reset()
    {
        $installer = new Installer($this);
        if (!$installer->install(true)) {
            $this->_errors = array_merge($this->_errors, $installer->errors);

            return false;
        }

        return true;
    }

    public function uninstall()
    {
        return Uninstaller::run() && parent::uninstall();
    }

    /**
     * MCP endpoint URL for the current shop (friendly URL when enabled).
     */
    public function getEndpointUrl(?int $idShop = null): string
    {
        return $this->context->link->getModuleLink($this->name, 'server', [], true, null, $idShop);
    }

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitPpMcpConfig')) {
            Configuration::updateGlobalValue('PPMCP_ENABLED', (int) Tools::getValue('PPMCP_ENABLED'));
            Configuration::updateGlobalValue('PPMCP_READ_ONLY', (int) Tools::getValue('PPMCP_READ_ONLY'));
            Configuration::updateGlobalValue('PPMCP_LOG_ARGUMENTS', (int) Tools::getValue('PPMCP_LOG_ARGUMENTS'));
            Configuration::updateGlobalValue('PPMCP_LOG_RESULTS', (int) Tools::getValue('PPMCP_LOG_RESULTS'));
            Configuration::updateGlobalValue('PPMCP_LOG_RETENTION_DAYS', max(0, (int) Tools::getValue('PPMCP_LOG_RETENTION_DAYS')));
            Configuration::updateGlobalValue('PPMCP_MAX_FILE_BYTES', max(10000, min(2000000, (int) Tools::getValue('PPMCP_MAX_FILE_BYTES'))));
            $origins = trim(preg_replace('/[^a-z0-9:\/\.\-\s,]/i', '', (string) Tools::getValue('PPMCP_ALLOWED_ORIGINS')));
            Configuration::updateGlobalValue('PPMCP_ALLOWED_ORIGINS', $origins);
            $purged = ActionLogger::purge();
            $output .= $this->displayConfirmation($this->trans('Settings updated.', [], self::TRANSLATION_DOMAIN)
                . ($purged ? ' ' . $this->trans('%count% old log entries removed.', ['%count%' => $purged], self::TRANSLATION_DOMAIN) : ''));
        }

        $this->context->smarty->assign([
            'ppmcp_endpoint' => $this->getEndpointUrl(),
            'ppmcp_endpoints' => $this->getShopEndpoints(),
            'ppmcp_keys_url' => $this->context->link->getAdminLink('AdminPpMcpKeys'),
            'ppmcp_logs_url' => $this->context->link->getAdminLink('AdminPpMcpLogs'),
            'ppmcp_tools_url' => $this->context->link->getAdminLink('AdminPpMcpTools'),
            'ppmcp_version' => $this->version,
            'ppmcp_ps_version' => _PS_VERSION_,
            'ppmcp_auth_header_ok' => $this->authorizationHeaderLikelyPassed(),
        ]);

        return $output
            . $this->context->smarty->fetch($this->getLocalPath() . 'views/templates/admin/configure.tpl')
            . $this->renderConfigForm();
    }

    /**
     * @return array[] one endpoint per shop (multistore)
     */
    private function getShopEndpoints(): array
    {
        $out = [];
        foreach (Shop::getShops(true) as $shop) {
            $out[] = ['id_shop' => (int) $shop['id_shop'], 'name' => $shop['name'], 'url' => $this->getEndpointUrl((int) $shop['id_shop'])];
        }

        return $out;
    }

    /**
     * PrestaShop's generated .htaccess forwards Authorization only for the
     * webservice; under CGI/FPM other URLs may lose it. X-Api-Key always works.
     */
    private function authorizationHeaderLikelyPassed(): bool
    {
        $file = _PS_ROOT_DIR_ . '/.htaccess';
        if (!is_file($file)) {
            return true; // nginx or no Apache: headers reach PHP
        }
        $content = (string) @file_get_contents($file);

        return stripos(PHP_SAPI, 'apache') !== false
            || stripos($content, 'CGIPassAuth On') !== false
            || stripos($content, 'E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]') !== false && stripos($content, 'ppmcp') !== false;
    }

    private function renderConfigForm(): string
    {
        $switch = function ($name, $label, $desc) {
            return [
                'type' => 'switch',
                'label' => $label,
                'name' => $name,
                'desc' => $desc,
                'is_bool' => true,
                'values' => [
                    ['id' => $name . '_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')],
                    ['id' => $name . '_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')],
                ],
            ];
        };

        $form = [
            'form' => [
                'legend' => ['title' => $this->trans('Server settings', [], self::TRANSLATION_DOMAIN), 'icon' => 'icon-cogs'],
                'input' => [
                    $switch('PPMCP_ENABLED', $this->trans('Enable MCP server', [], self::TRANSLATION_DOMAIN), $this->trans('When disabled the endpoint answers 503 for every request.', [], self::TRANSLATION_DOMAIN)),
                    $switch('PPMCP_READ_ONLY', $this->trans('Read-only mode', [], self::TRANSLATION_DOMAIN), $this->trans('Hides and blocks every write tool for all keys, whatever their scopes.', [], self::TRANSLATION_DOMAIN)),
                    $switch('PPMCP_LOG_ARGUMENTS', $this->trans('Log tool arguments', [], self::TRANSLATION_DOMAIN), $this->trans('Stores call arguments in the activity log (secrets are masked). Changes made by write tools are always logged.', [], self::TRANSLATION_DOMAIN)),
                    $switch('PPMCP_LOG_RESULTS', $this->trans('Log tool results', [], self::TRANSLATION_DOMAIN), $this->trans('Stores what was returned to the assistant. Useful for debugging, uses more space.', [], self::TRANSLATION_DOMAIN)),
                    [
                        'type' => 'text',
                        'label' => $this->trans('Log retention (days)', [], self::TRANSLATION_DOMAIN),
                        'name' => 'PPMCP_LOG_RETENTION_DAYS',
                        'class' => 'fixed-width-sm',
                        'desc' => $this->trans('0 keeps logs forever. Old entries are purged when settings are saved and daily on use.', [], self::TRANSLATION_DOMAIN),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Max file size for module_file_read (bytes)', [], self::TRANSLATION_DOMAIN),
                        'name' => 'PPMCP_MAX_FILE_BYTES',
                        'class' => 'fixed-width-md',
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->trans('Extra allowed browser origins', [], self::TRANSLATION_DOMAIN),
                        'name' => 'PPMCP_ALLOWED_ORIGINS',
                        'desc' => $this->trans('Only needed for browser-based MCP clients. Requests without an Origin header (Claude Code, claude.ai, Desktop) are not affected. One origin per line, e.g. https://app.example.com', [], self::TRANSLATION_DOMAIN),
                    ],
                ],
                'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions')],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitPpMcpConfig';
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->fields_value = [];
        foreach (['PPMCP_ENABLED', 'PPMCP_READ_ONLY', 'PPMCP_LOG_ARGUMENTS', 'PPMCP_LOG_RESULTS', 'PPMCP_LOG_RETENTION_DAYS', 'PPMCP_MAX_FILE_BYTES', 'PPMCP_ALLOWED_ORIGINS'] as $k) {
            $helper->fields_value[$k] = Configuration::getGlobalValue($k);
        }

        return $helper->generateForm([$form]);
    }
}
