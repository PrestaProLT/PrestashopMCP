<?php
/**
 * OAuth token endpoint (authorization_code + PKCE, refresh_token) and,
 * with ?revoke=1, token revocation (RFC 7009).
 *
 * @author    PrestaPro
 * @license   AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'ppmcp/classes/PpMcpBareFrontController.php';

use PrestaPro\PpMcp\Mcp\Endpoint;
use PrestaPro\PpMcp\Mcp\HttpResponse;
use PrestaPro\PpMcp\OAuth\OAuthServer;

class PpMcpTokenModuleFrontController extends PpMcpBareFrontController
{
    protected function handleRequest(): HttpResponse
    {
        $method = $this->httpMethod();
        if ($method === 'OPTIONS') {
            return OAuthServer::cors(new HttpResponse(204));
        }
        if ($method !== 'POST') {
            return OAuthServer::cors(OAuthServer::oauthError(405, 'invalid_request', 'Use POST.'));
        }
        $server = new OAuthServer($this->module);
        $post = $this->postParams();
        if (isset($_GET['revoke'])) {
            return $server->revoke($post, Endpoint::requestHeaders());
        }

        return $server->token($post, Endpoint::requestHeaders());
    }
}
