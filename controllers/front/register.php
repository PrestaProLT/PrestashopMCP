<?php
/**
 * OAuth dynamic client registration (RFC 7591).
 *
 * @author    PrestaPro
 * @license   AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'ppmcp/classes/PpMcpBareFrontController.php';

use PrestaPro\PpMcp\Mcp\HttpResponse;
use PrestaPro\PpMcp\OAuth\OAuthServer;

class PpMcpRegisterModuleFrontController extends PpMcpBareFrontController
{
    protected function handleRequest(): HttpResponse
    {
        $method = $this->httpMethod();
        if ($method === 'OPTIONS') {
            return OAuthServer::cors(new HttpResponse(204));
        }
        if ($method !== 'POST') {
            return OAuthServer::cors(OAuthServer::oauthError(405, 'invalid_request', 'Use POST with a JSON client metadata document.'));
        }

        return (new OAuthServer($this->module))->register((string) file_get_contents('php://input'), Tools::getRemoteAddr());
    }
}
