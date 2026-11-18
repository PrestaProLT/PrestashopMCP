<?php
/**
 * Base for the module's API-style front controllers (MCP endpoint, OAuth):
 * no theme, no front-office cookie, no SSL redirect / maintenance page /
 * geolocation blocking. handleRequest() returns the whole HTTP response.
 *
 * @author    PrestaPro
 * @license   AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'ppmcp/autoload.php';

use PrestaPro\PpMcp\Mcp\HttpResponse;

abstract class PpMcpBareFrontController extends ModuleFrontController
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
        $this->context->cookie->disallowWriting();
        parent::init();
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

    public function displayAjax()
    {
    }

    abstract protected function handleRequest(): HttpResponse;

    public function postProcess()
    {
        try {
            $response = $this->handleRequest();
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('ppmcp: ' . static::class . ' failure: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 3, null, 'ppmcp');
            $response = HttpResponse::json(500, ['error' => 'server_error', 'error_description' => 'Internal server error']);
        }
        $response->send();
        exit;
    }

    protected function httpMethod(): string
    {
        return strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET');
    }

    /**
     * Form-encoded POST body (or JSON, some clients send it).
     */
    protected function postParams(): array
    {
        $type = isset($_SERVER['CONTENT_TYPE']) ? strtolower((string) $_SERVER['CONTENT_TYPE']) : '';
        if (strpos($type, 'application/json') !== false) {
            $data = json_decode((string) file_get_contents('php://input'), true);

            return is_array($data) ? array_map(function ($v) { return is_scalar($v) ? (string) $v : ''; }, $data) : [];
        }

        return array_map(function ($v) { return is_scalar($v) ? (string) $v : ''; }, $_POST);
    }
}
