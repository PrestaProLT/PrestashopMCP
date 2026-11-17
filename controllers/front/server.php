<?php
/**
 * PrestaPro MCP — Streamable HTTP endpoint.
 *
 * URL: /module/ppmcp/server (friendly URLs) or
 *      index.php?fc=module&module=ppmcp&controller=server
 *
 * A ModuleFrontController (not a standalone PHP file: PS 9 denies direct
 * *.php access under modules/). All front-office side effects that make no
 * sense for an API are neutralised: SSL redirect, maintenance page,
 * geolocation blocking, cookies, displayHeader hook and HTML rendering.
 * The request is fully handled in postProcess() and the script exits there.
 *
 * @author    PrestaPro
 * @license   AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaPro\PpMcp\Log\ActionLogger;
use PrestaPro\PpMcp\Mcp\Endpoint;
use PrestaPro\PpMcp\Mcp\HttpResponse;

class PpMcpServerModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;
    /** @var bool */
    public $auth = false;
    /** @var bool */
    public $guestAllowed = true;
    /** @var bool */
    public $ssl = true;
    /** @var bool */
    public $display_header = false;
    /** @var bool */
    public $display_footer = false;

    public function init()
    {
        // API calls must never set or rotate front-office cookies.
        $this->context->cookie->disallowWriting();
        parent::init();
        // Controller::init() resets $ajax from the request; force it back.
        $this->ajax = true;
    }

    protected function sslRedirection()
    {
    }

    protected function displayMaintenancePage()
    {
    }

    protected function displayRestrictedCountryPage()
    {
    }

    protected function geolocationManagement($defaultCountry)
    {
        return false;
    }

    public function setMedia()
    {
        return true;
    }

    public function initContent()
    {
    }

    public function postProcess()
    {
        @set_time_limit(120);
        try {
            $body = (string) file_get_contents('php://input');
            $method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
            $response = (new Endpoint($this->module))->handle($method, Endpoint::requestHeaders(), $body);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('ppmcp: endpoint failure: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 3, null, 'ppmcp');
            $response = HttpResponse::json(500, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32603, 'message' => 'Internal server error']]);
        }
        $response->send();
        ActionLogger::purgeDaily();
        exit;
    }

    public function displayAjax()
    {
        // Never reached: postProcess() exits.
    }
}
