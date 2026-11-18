<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Mcp;

use PrestaPro\PpMcp\Auth\Authenticator;
use PrestaPro\PpMcp\Auth\PermissionChecker;
use PrestaPro\PpMcp\Log\ActionLogger;
use PrestaPro\PpMcp\OAuth\OAuthServer;
use PrestaPro\PpMcp\Skill\SkillRepository;
use PrestaPro\PpMcp\Tool\ToolRegistry;
use PrestaPro\PpMcp\Tool\ToolRunner;

/**
 * HTTP front of the MCP server: method / Origin / auth checks, then hands
 * the JSON-RPC body to Server. Framework-agnostic so it can be mounted from
 * the module front controller or the standalone entry file.
 */
class Endpoint
{
    /** @var \Module */
    private $module;

    public function __construct(\Module $module)
    {
        $this->module = $module;
    }

    public static function requestHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (empty($headers['authorization']) && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (empty($headers['authorization']) && function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $k => $v) {
                if (strtolower($k) === 'authorization') {
                    $headers['authorization'] = (string) $v;
                }
            }
        }

        return $headers;
    }

    public function handle(string $httpMethod, array $headers, string $body): HttpResponse
    {
        $requestId = bin2hex(random_bytes(8));
        $ip = \Tools::getRemoteAddr();
        $httpMethod = strtoupper($httpMethod);

        if ($httpMethod === 'OPTIONS') {
            return new HttpResponse(204, '', ['Allow' => 'POST, OPTIONS']);
        }
        if ($httpMethod !== 'POST') {
            return HttpResponse::json(405, ['error' => 'This is an MCP Streamable HTTP endpoint. Use POST with a JSON-RPC body.'], ['Allow' => 'POST, OPTIONS']);
        }
        if (!\Configuration::getGlobalValue('PPMCP_ENABLED')) {
            return HttpResponse::json(503, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => 'The MCP server is disabled in the PrestaPro MCP module configuration.']]);
        }
        if (isset($headers['origin']) && $headers['origin'] !== '' && !$this->originAllowed($headers['origin'])) {
            return HttpResponse::json(403, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => 'Origin not allowed: ' . $headers['origin']]]);
        }

        $auth = new Authenticator();
        $token = Authenticator::tokenFromHeaders($headers);
        $identity = $auth->authenticate($token);
        if (!$identity) {
            $decoded = Json::decode($body);
            // RFC 9728 / MCP authorization: tell OAuth clients where to sign in.
            $challenge = 'Bearer realm="PrestaPro MCP"';
            $metadataUrl = OAuthServer::resourceMetadataUrl();
            if ($metadataUrl) {
                $challenge .= ', resource_metadata="' . $metadataUrl . '"';
            }
            if ($token !== '') {
                $challenge .= ', error="invalid_token", error_description="' . ($auth->tokenExpired ? 'The access token expired' : 'The access token is not valid') . '"';
            }
            if (ActionLogger::authFailureThrottled($ip)) {
                return HttpResponse::json(
                    401,
                    ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'Unauthorized']],
                    ['WWW-Authenticate' => $challenge]
                );
            }
            ActionLogger::log([
                'request_id' => $requestId,
                'rpc_method' => is_array($decoded) && isset($decoded['method']) ? (string) $decoded['method'] : '',
                'tool' => is_array($decoded) && isset($decoded['params']['name']) ? (string) $decoded['params']['name'] : '',
                'status' => \PpMcpLog::STATUS_DENIED,
                'error' => 'Authentication failed: ' . $auth->failure,
                'client' => isset($headers['user-agent']) ? $headers['user-agent'] : '',
                'ip' => $ip,
            ]);

            return HttpResponse::json(
                401,
                ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'Unauthorized: sign in with OAuth, or send "Authorization: Bearer <ppmcp_ token>" (or X-Api-Key). Create keys in the PrestaPro MCP module.']],
                ['WWW-Authenticate' => $challenge]
            );
        }
        list($key, $employee) = $identity;
        $key->touch($ip);

        $context = \Context::getContext();
        $context->employee = $employee;

        $registry = new ToolRegistry($this->module);
        $skills = new SkillRepository(_PS_MODULE_DIR_ . $this->module->name . '/skills', $registry->getModuleSkills());
        $runner = new ToolRunner($key, $employee, new PermissionChecker(), $requestId, $ip, isset($headers['user-agent']) ? $headers['user-agent'] : '');
        Runtime::$registry = $registry;
        Runtime::$skills = $skills;
        Runtime::$runner = $runner;
        $server = new Server($registry, $skills, $runner, $this->serverInfo(), $this->instructions());

        return $server->handle($headers, $body);
    }

    private function originAllowed(string $origin): bool
    {
        $origin = rtrim(strtolower($origin), '/');
        $allowed = [];
        foreach (\Shop::getShops(false) as $s) {
            foreach (['domain', 'domain_ssl'] as $f) {
                if (!empty($s[$f])) {
                    $allowed[] = 'http://' . strtolower($s[$f]);
                    $allowed[] = 'https://' . strtolower($s[$f]);
                }
            }
        }
        foreach (preg_split('/[\s,]+/', (string) \Configuration::getGlobalValue('PPMCP_ALLOWED_ORIGINS')) as $o) {
            if ($o !== '') {
                $allowed[] = rtrim(strtolower($o), '/');
            }
        }

        return in_array($origin, $allowed, true);
    }

    private function serverInfo(): array
    {
        $shop = \Context::getContext()->shop;

        return [
            'name' => 'prestapro-ppmcp',
            'title' => 'PrestaPro MCP — ' . $shop->name,
            'version' => $this->module->version,
            'description' => 'PrestaShop ' . _PS_VERSION_ . ' store manager tools: catalog, orders, customers, carts, diagnostics, module insight.',
            'websiteUrl' => \Tools::getShopDomainSsl(true) . __PS_BASE_URI__,
        ];
    }

    /**
     * Server instructions (kept under 2 KB; Claude Code truncates at 4 KB).
     */
    private function instructions(): string
    {
        $multishop = \Shop::isFeatureActive();

        return trim(
            'PrestaShop ' . _PS_VERSION_ . ' store "' . \Context::getContext()->shop->name . '"'
            . ($multishop ? ' (MULTISTORE: pass id_shop; call shop_context_get to list shops)' : '') . ".\n"
            . "Tool names follow <owner>_<resource>_<action>: core owners are shop, catalog, order, customer, cart, diagnose, module, mcp; any other owner is a PrestaShop module's technical name (e.g. ppslider_slide_update is a tool of module ppslider). To change something a module manages, look for that module's tools first (mcp_tool_search finds tools by intent).\n"
            . "Before multi-step or unfamiliar work (module analysis, add-to-cart problems, bulk catalog edits, order issues) call mcp_skill_list and follow the matching playbook from mcp_skill_get.\n"
            . "Languages: multilang fields accept {\"en\": \"…\", \"lt\": \"…\"} or a plain string for the lang argument. Prices are tax excluded unless the field says tax_incl.\n"
            . "Write tools change the live shop: run them with dry_run=true first when the change is large or ambiguous, show the planned changes, then call again without dry_run. Every call is logged with its changes (mcp_log_list).\n"
            . 'Results are paged (limit/offset); ask for more only when needed.'
        );
    }
}
