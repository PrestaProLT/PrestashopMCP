<?php
/**
 * OAuth discovery documents, served at
 *   /.well-known/oauth-authorization-server[/<issuer path>]   (RFC 8414)
 *   /.well-known/oauth-protected-resource[/<resource path>]   (RFC 9728)
 * through the module routes registered on hook moduleRoutes.
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

class PpMcpOauthmetaModuleFrontController extends PpMcpBareFrontController
{
    protected function handleRequest(): HttpResponse
    {
        if ($this->httpMethod() === 'OPTIONS') {
            return OAuthServer::cors(new HttpResponse(204));
        }
        $kind = Tools::getValue('kind') === 'as' ? 'as' : 'pr';

        return (new OAuthServer($this->module))->metadata($kind, (string) Tools::getValue('rest', ''));
    }
}
