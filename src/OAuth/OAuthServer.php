<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\OAuth;

use PrestaPro\PpMcp\Mcp\HttpResponse;

/**
 * OAuth 2.1 authorization server for MCP clients (claude.ai / Claude mobile /
 * Claude Desktop connectors, ChatGPT connectors, Claude Code…).
 *
 *  - Discovery: RFC 9728 protected resource metadata + RFC 8414 server metadata
 *    under /.well-known/ (module routes, friendly URLs required).
 *  - RFC 7591 dynamic client registration, limited to allowed redirect hosts.
 *  - Authorization code + PKCE (S256 only), refresh tokens with rotation.
 *
 * Approval never happens on the public page: the connecting page shows a
 * one-time code, and an employee logged in to the back office enters it on
 * PrestaPro MCP > Approve connection, where they choose the employee, scopes,
 * shops and lifetime. The back-office URL is never revealed, there is no
 * password form to brute-force, and back-office 2FA modules keep applying.
 * The issued access token is an ordinary ppmcp API key, so scopes, employee
 * permissions, shop restriction, logging and revocation work as for manual keys.
 */
class OAuthServer
{
    const SCOPES = ['read', 'write', 'code'];
    const REQUEST_TTL = 900;      // seconds to approve a connection request
    const CODE_TTL = 300;         // seconds to exchange an authorization code
    const CODE_ALPHABET = 'BCDFGHJKLMNPQRSTVWXZ';
    const REFRESH_PREFIX = 'ppmcpr_';
    const CLIENT_PREFIX = 'ppmcpc_';
    const SECRET_PREFIX = 'ppmcps_';
    const DEFAULT_REDIRECT_HOSTS = "claude.ai\nclaude.com\nchatgpt.com\nlocalhost\n127.0.0.1";

    /** @var \Module */
    private $module;

    public function __construct(\Module $module)
    {
        $this->module = $module;
    }

    public static function enabled(): bool
    {
        return (bool) \Configuration::getGlobalValue('PPMCP_OAUTH_ENABLED') && (bool) \Configuration::get('PS_REWRITING_SETTINGS');
    }

    /**
     * Module routes (hook moduleRoutes). "{/:rest}" = optional "/<path>" suffix
     * (issuer / resource path, RFC 8414 §3.1 and RFC 9728 §3.1).
     */
    public static function routes(): array
    {
        $rest = ['rest' => ['regexp' => '[_a-zA-Z0-9/.\-]+', 'param' => 'rest']];

        return [
            'module-ppmcp-oauth-as' => [
                'controller' => 'oauthmeta',
                'rule' => '.well-known/oauth-authorization-server{/:rest}',
                'keywords' => $rest,
                'params' => ['fc' => 'module', 'module' => 'ppmcp', 'controller' => 'oauthmeta', 'kind' => 'as'],
            ],
            'module-ppmcp-oauth-pr' => [
                'controller' => 'oauthmeta',
                'rule' => '.well-known/oauth-protected-resource{/:rest}',
                'keywords' => $rest,
                'params' => ['fc' => 'module', 'module' => 'ppmcp', 'controller' => 'oauthmeta', 'kind' => 'pr'],
            ],
        ];
    }

    // ------------------------------------------------------------------ discovery

    /**
     * Scheme + host of this request, only when the host is a domain of a shop
     * (no Host header injection into metadata).
     */
    public static function origin(): ?string
    {
        $host = strtolower((string) \Tools::getHttpHost(false, false, true));
        if ($host === '' || !self::shopIdsForHost($host)) {
            return null;
        }

        return (\Tools::usingSecureMode() ? 'https://' : 'http://') . $host;
    }

    /**
     * @return array[] shop_url rows (id_shop, uri) for a host, longest uri first
     */
    private static function shopIdsForHost(string $host): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT su.`id_shop`, CONCAT(su.`physical_uri`, su.`virtual_uri`) AS uri
             FROM `' . _DB_PREFIX_ . 'shop_url` su INNER JOIN `' . _DB_PREFIX_ . 'shop` s ON s.`id_shop` = su.`id_shop`
             WHERE s.`active` = 1 AND s.`deleted` = 0
               AND (su.`domain` = \'' . pSQL($host) . '\' OR su.`domain_ssl` = \'' . pSQL($host) . '\')'
        ) ?: [];
        usort($rows, function ($a, $b) {
            return strlen($b['uri']) - strlen($a['uri']);
        });

        return $rows;
    }

    /**
     * Shop whose base path is the longest prefix of $path (no leading slash).
     */
    private static function shopForPath(string $host, string $path, bool $exact): ?int
    {
        $path = trim($path, '/');
        foreach (self::shopIdsForHost($host) as $r) {
            $uri = trim((string) $r['uri'], '/');
            if ($exact ? $uri === $path : ($uri === '' || $path === $uri || strpos($path . '/', $uri . '/') === 0)) {
                return (int) $r['id_shop'];
            }
        }

        return null;
    }

    public function issuer(int $idShop): string
    {
        $shop = new \Shop($idShop);
        $ssl = (bool) \Configuration::get('PS_SSL_ENABLED', null, null, $idShop);
        $base = ($ssl ? 'https://' . $shop->domain_ssl : 'http://' . $shop->domain) . rtrim($shop->getBaseURI(), '/');

        return $base;
    }

    public function endpoint(string $controller, int $idShop): string
    {
        return \Context::getContext()->link->getModuleLink($this->module->name, $controller, [], true, null, $idShop);
    }

    /**
     * URL for the resource_metadata parameter of a 401 from the MCP endpoint.
     */
    public static function resourceMetadataUrl(): ?string
    {
        if (!self::enabled() || !($origin = self::origin())) {
            return null;
        }
        $path = (string) parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
        if ($path === '' || strpos($path, 'index.php') !== false) {
            return null;
        }

        return $origin . '/.well-known/oauth-protected-resource' . $path;
    }

    public function metadata(string $kind, string $rest): HttpResponse
    {
        $origin = self::origin();
        if (!self::enabled() || !$origin) {
            return self::cors(HttpResponse::json(404, ['error' => 'not_found', 'error_description' => 'OAuth is not enabled on this shop.']));
        }
        $host = (string) parse_url($origin, PHP_URL_HOST);
        $rest = trim($rest, '/');

        if ($kind === 'as') {
            $idShop = self::shopForPath($host, $rest, true);
            if (!$idShop) {
                return self::cors(HttpResponse::json(404, ['error' => 'not_found']));
            }
            $doc = [
                'issuer' => $origin . ($rest !== '' ? '/' . $rest : ''),
                'authorization_endpoint' => $this->endpoint('authorize', $idShop),
                'token_endpoint' => $this->endpoint('token', $idShop),
                'registration_endpoint' => $this->endpoint('register', $idShop),
                'revocation_endpoint' => $this->endpoint('token', $idShop) . '?revoke=1',
                'scopes_supported' => self::SCOPES,
                'response_types_supported' => ['code'],
                'response_modes_supported' => ['query'],
                'grant_types_supported' => ['authorization_code', 'refresh_token'],
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
                'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
                'authorization_response_iss_parameter_supported' => true,
                'service_documentation' => 'https://github.com/PrestaProLT/PrestashopMCP/blob/main/docs/CONNECTING.md',
            ];

            return self::cors(HttpResponse::json(200, $doc));
        }

        // Protected resource metadata. The resource is the MCP endpoint the client used.
        $idShop = $rest !== '' ? self::shopForPath($host, $rest, false) : (int) \Context::getContext()->shop->id;
        if (!$idShop || ($rest !== '' && !preg_match('#(^|/)module/' . preg_quote($this->module->name, '#') . '/server$#', $rest))) {
            return self::cors(HttpResponse::json(404, ['error' => 'not_found']));
        }
        $shop = new \Shop($idShop);
        $doc = [
            'resource' => $rest !== '' ? $origin . '/' . $rest : $this->endpoint('server', $idShop),
            'authorization_servers' => [$this->issuerFor($origin, $idShop)],
            'scopes_supported' => self::SCOPES,
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'PrestaPro MCP — ' . $shop->name,
            'resource_documentation' => 'https://github.com/PrestaProLT/PrestashopMCP/blob/main/docs/CONNECTING.md',
        ];

        return self::cors(HttpResponse::json(200, $doc));
    }

    /**
     * Issuer on the host the client is using (domain or domain_ssl).
     */
    private function issuerFor(string $origin, int $idShop): string
    {
        $shop = new \Shop($idShop);

        return $origin . rtrim($shop->getBaseURI(), '/');
    }

    // ------------------------------------------------------------------ registration

    public function register(string $body, string $ip): HttpResponse
    {
        if (!self::enabled()) {
            return self::cors(self::oauthError(404, 'invalid_request', 'OAuth is not enabled on this shop.'));
        }
        $this->cleanup();
        $db = \Db::getInstance();
        $p = _DB_PREFIX_;
        $recent = (int) $db->getValue('SELECT COUNT(*) FROM `' . $p . 'ppmcp_oauth_client` WHERE `ip` = \'' . pSQL($ip) . '\' AND `date_add` > DATE_SUB(NOW(), INTERVAL 1 HOUR)', false);
        $daily = (int) $db->getValue('SELECT COUNT(*) FROM `' . $p . 'ppmcp_oauth_client` WHERE `date_add` > DATE_SUB(NOW(), INTERVAL 1 DAY)', false);
        if ($recent >= 20 || $daily >= 500) {
            return self::cors(self::oauthError(429, 'invalid_request', 'Too many client registrations, try again later.'));
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return self::cors(self::oauthError(400, 'invalid_client_metadata', 'Body must be a JSON object.'));
        }
        $uris = isset($data['redirect_uris']) && is_array($data['redirect_uris']) ? array_values($data['redirect_uris']) : [];
        if (!$uris || count($uris) > 10) {
            return self::cors(self::oauthError(400, 'invalid_redirect_uri', 'redirect_uris must list 1 to 10 URIs.'));
        }
        foreach ($uris as $u) {
            if (!is_string($u) || ($why = $this->redirectUriProblem($u)) !== null) {
                return self::cors(self::oauthError(400, 'invalid_redirect_uri', is_string($u) ? $why : 'redirect_uris must be strings.'));
            }
        }
        $method = isset($data['token_endpoint_auth_method']) ? (string) $data['token_endpoint_auth_method'] : 'client_secret_basic';
        if (!in_array($method, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
            return self::cors(self::oauthError(400, 'invalid_client_metadata', 'Unsupported token_endpoint_auth_method.'));
        }
        $grants = isset($data['grant_types']) && is_array($data['grant_types']) ? $data['grant_types'] : ['authorization_code', 'refresh_token'];
        if (array_diff($grants, ['authorization_code', 'refresh_token']) || !in_array('authorization_code', $grants, true)) {
            return self::cors(self::oauthError(400, 'invalid_client_metadata', 'Supported grant_types: authorization_code, refresh_token.'));
        }
        $name = isset($data['client_name']) && is_string($data['client_name']) ? trim(strip_tags($data['client_name'])) : '';
        $name = mb_substr($name !== '' ? $name : (string) parse_url($uris[0], PHP_URL_HOST), 0, 100);

        $clientId = self::CLIENT_PREFIX . bin2hex(random_bytes(16));
        $secret = $method === 'none' ? null : self::SECRET_PREFIX . bin2hex(random_bytes(24));
        $now = date('Y-m-d H:i:s');
        $ok = $db->insert('ppmcp_oauth_client', [
            'client_id' => pSQL($clientId),
            'secret_hash' => $secret ? hash('sha256', $secret) : null,
            'auth_method' => pSQL($method),
            'client_name' => pSQL($name),
            'redirect_uris' => pSQL(json_encode($uris, JSON_UNESCAPED_SLASHES)),
            'ip' => pSQL(substr($ip, 0, 45)),
            'date_add' => $now,
        ], true);
        if (!$ok) {
            return self::cors(self::oauthError(500, 'server_error', 'Could not save the client.'));
        }
        $out = [
            'client_id' => $clientId,
            'client_id_issued_at' => time(),
            'client_name' => $name,
            'redirect_uris' => $uris,
            'grant_types' => array_values($grants),
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $method,
        ];
        if ($secret) {
            $out['client_secret'] = $secret;
            $out['client_secret_expires_at'] = 0;
        }

        return self::cors(HttpResponse::json(201, $out));
    }

    /**
     * @return string|null why the redirect URI is not accepted
     */
    public function redirectUriProblem(string $uri): ?string
    {
        if (strlen($uri) > 2000 || strpos($uri, '#') !== false) {
            return 'Redirect URI is too long or has a fragment.';
        }
        $parts = parse_url($uri);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return 'Redirect URI must be an absolute URL.';
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower(trim($parts['host'], '[]'));
        $loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $loopback)) {
            return 'Redirect URI must use https (http only for localhost).';
        }
        foreach (self::allowedRedirectHosts() as $allowed) {
            if ($host === $allowed || (strpos($allowed, '*.') === 0 && substr($host, -strlen($allowed) + 1) === substr($allowed, 1))) {
                return null;
            }
        }

        return 'Redirect host "' . $host . '" is not allowed. Add it under PrestaPro MCP > Settings > OAuth redirect hosts.';
    }

    /**
     * @return string[]
     */
    public static function allowedRedirectHosts(): array
    {
        $raw = \Configuration::getGlobalValue('PPMCP_OAUTH_REDIRECT_HOSTS');
        if ($raw === false || $raw === null) {
            $raw = self::DEFAULT_REDIRECT_HOSTS;
        }
        $hosts = [];
        foreach (preg_split('/[\s,]+/', strtolower((string) $raw)) as $h) {
            $h = trim($h);
            if ($h !== '') {
                $hosts[] = $h === '[::1]' ? '::1' : $h;
            }
        }

        return $hosts;
    }

    // ------------------------------------------------------------------ authorization

    /**
     * Validates an authorization request and records it.
     *
     * @return array one of
     *   ['page_error' => message]            invalid client / redirect: never redirect
     *   ['redirect' => url]                  error returned to the client
     *   ['request' => row, 'secret' => s]    pending request to approve
     */
    public function startAuthorization(array $q, string $ip, string $ua, int $idShop): array
    {
        if (!self::enabled()) {
            return ['page_error' => 'OAuth sign-in is not enabled on this shop.'];
        }
        $this->cleanup();
        $client = $this->client(isset($q['client_id']) ? (string) $q['client_id'] : '');
        if (!$client) {
            return ['page_error' => 'Unknown client. Remove the connector in your AI app and add it again.'];
        }
        $redirect = isset($q['redirect_uri']) ? (string) $q['redirect_uri'] : '';
        $registered = json_decode((string) $client['redirect_uris'], true) ?: [];
        if ($redirect === '' && count($registered) === 1) {
            $redirect = $registered[0];
        }
        if (!in_array($redirect, $registered, true) || $this->redirectUriProblem($redirect) !== null) {
            return ['page_error' => 'The redirect address does not match the one this client registered.'];
        }
        $state = isset($q['state']) ? (string) $q['state'] : '';
        $origin = self::origin();
        $iss = $origin ? $this->issuerFor($origin, $idShop) : $this->issuer($idShop);
        $fail = function ($error, $desc) use ($redirect, $state, $iss) {
            return ['redirect' => $this->withParams($redirect, ['error' => $error, 'error_description' => $desc, 'state' => $state, 'iss' => $iss])];
        };
        if (strlen($state) > 1000) {
            return $fail('invalid_request', 'state is too long.');
        }
        if (!isset($q['response_type']) || $q['response_type'] !== 'code') {
            return $fail('unsupported_response_type', 'Only response_type=code is supported.');
        }
        $challenge = isset($q['code_challenge']) ? (string) $q['code_challenge'] : '';
        $method = isset($q['code_challenge_method']) ? (string) $q['code_challenge_method'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge) || $method !== 'S256') {
            return $fail('invalid_request', 'PKCE with code_challenge_method=S256 is required.');
        }
        $scopes = array_values(array_intersect(self::SCOPES, preg_split('/\s+/', isset($q['scope']) ? (string) $q['scope'] : '')));

        $db = \Db::getInstance();
        $p = _DB_PREFIX_;
        $pending = (int) $db->getValue('SELECT COUNT(*) FROM `' . $p . 'ppmcp_oauth_request` WHERE `ip` = \'' . pSQL($ip) . '\' AND `status` = \'pending\' AND `date_expire` > NOW()', false);
        if ($pending >= 10) {
            return ['page_error' => 'Too many pending connection requests from your address. Wait 15 minutes and try again.'];
        }

        $secret = bin2hex(random_bytes(24));
        $requestKey = bin2hex(random_bytes(16));
        $userCode = $this->newUserCode();
        $row = [
            'request_key' => $requestKey,
            'browser_hash' => hash('sha256', $secret),
            'user_code' => $userCode,
            'client_id' => pSQL($client['client_id']),
            'redirect_uri' => pSQL($redirect),
            'state' => pSQL($state),
            'code_challenge' => pSQL($challenge),
            'scope' => pSQL(implode(' ', $scopes)),
            'resource' => pSQL(mb_substr(isset($q['resource']) ? (string) $q['resource'] : '', 0, 500)),
            'id_shop' => $idShop,
            'issuer' => pSQL($iss),
            'ip' => pSQL(substr($ip, 0, 45)),
            'user_agent' => pSQL(mb_substr($ua, 0, 255)),
            'status' => 'pending',
            'date_add' => date('Y-m-d H:i:s'),
            'date_expire' => date('Y-m-d H:i:s', time() + self::REQUEST_TTL),
        ];
        if (!$db->insert('ppmcp_oauth_request', $row)) {
            return ['page_error' => 'Could not save the connection request.'];
        }
        $row['client_name'] = $client['client_name'];

        return ['request' => $row, 'secret' => $secret];
    }

    private function newUserCode(): string
    {
        $alphabet = self::CODE_ALPHABET;
        do {
            $code = '';
            for ($i = 0; $i < 8; ++$i) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $code = substr($code, 0, 4) . '-' . substr($code, 4);
            $exists = (int) \Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ppmcp_oauth_request` WHERE `user_code` = \'' . pSQL($code) . '\' AND `status` = \'pending\'', false);
        } while ($exists);

        return $code;
    }

    public static function normaliseUserCode(string $code): string
    {
        $c = preg_replace('/[^A-Z]/', '', strtoupper($code));

        return strlen($c) === 8 ? substr($c, 0, 4) . '-' . substr($c, 4) : '';
    }

    /**
     * Polled by the connecting page. Only the browser holding the request's
     * cookie receives the authorization code.
     *
     * @return array ['status' => pending|approved|denied|expired|done|unknown, 'redirect' => ?string]
     */
    public function poll(string $requestKey, string $secret): array
    {
        $db = \Db::getInstance();
        $p = _DB_PREFIX_;
        if (!preg_match('/^[a-f0-9]{32}$/', $requestKey) || $secret === '') {
            return ['status' => 'unknown'];
        }
        $r = $db->getRow('SELECT * FROM `' . $p . 'ppmcp_oauth_request` WHERE `request_key` = \'' . pSQL($requestKey) . '\'', false);
        if (!$r || !hash_equals((string) $r['browser_hash'], hash('sha256', $secret))) {
            return ['status' => 'unknown'];
        }
        $iss = (string) $r['issuer'];
        if ($r['status'] === 'pending') {
            if (strtotime($r['date_expire']) < time()) {
                $db->update('ppmcp_oauth_request', ['status' => 'expired'], '`id_ppmcp_oauth_request` = ' . (int) $r['id_ppmcp_oauth_request']);

                return ['status' => 'expired', 'redirect' => $this->withParams($r['redirect_uri'], ['error' => 'access_denied', 'error_description' => 'The request was not approved in time.', 'state' => $r['state'], 'iss' => $iss])];
            }

            return ['status' => 'pending'];
        }
        if ($r['status'] === 'denied') {
            $db->update('ppmcp_oauth_request', ['status' => 'done'], '`id_ppmcp_oauth_request` = ' . (int) $r['id_ppmcp_oauth_request']);

            return ['status' => 'denied', 'redirect' => $this->withParams($r['redirect_uri'], ['error' => 'access_denied', 'error_description' => 'The shop employee declined the connection.', 'state' => $r['state'], 'iss' => $iss])];
        }
        if ($r['status'] === 'approved') {
            $code = bin2hex(random_bytes(24));
            // Conditional update: two parallel polls cannot both get a code.
            $db->execute(
                'UPDATE `' . $p . 'ppmcp_oauth_request` SET `status` = \'code_issued\', `code_hash` = \'' . hash('sha256', $code) . '\',
                    `code_expire` = \'' . date('Y-m-d H:i:s', time() + self::CODE_TTL) . '\'
                 WHERE `id_ppmcp_oauth_request` = ' . (int) $r['id_ppmcp_oauth_request'] . ' AND `status` = \'approved\''
            );
            if ((int) $db->Affected_Rows() !== 1) {
                return ['status' => 'done'];
            }

            return ['status' => 'approved', 'redirect' => $this->withParams($r['redirect_uri'], ['code' => $code, 'state' => $r['state'], 'iss' => $iss])];
        }

        return ['status' => $r['status'] === 'expired' ? 'expired' : 'done'];
    }

    public function findPendingByUserCode(string $userCode): ?array
    {
        $code = self::normaliseUserCode($userCode);
        if ($code === '') {
            return null;
        }
        $r = \Db::getInstance()->getRow(
            'SELECT r.*, c.`client_name` FROM `' . _DB_PREFIX_ . 'ppmcp_oauth_request` r
             LEFT JOIN `' . _DB_PREFIX_ . 'ppmcp_oauth_client` c ON c.`client_id` = r.`client_id`
             WHERE r.`user_code` = \'' . pSQL($code) . '\' AND r.`status` = \'pending\' AND r.`date_expire` > NOW()',
            false
        );

        return $r ?: null;
    }

    public function findPendingById(int $id): ?array
    {
        $r = \Db::getInstance()->getRow(
            'SELECT r.*, c.`client_name` FROM `' . _DB_PREFIX_ . 'ppmcp_oauth_request` r
             LEFT JOIN `' . _DB_PREFIX_ . 'ppmcp_oauth_client` c ON c.`client_id` = r.`client_id`
             WHERE r.`id_ppmcp_oauth_request` = ' . (int) $id . ' AND r.`status` = \'pending\' AND r.`date_expire` > NOW()',
            false
        );

        return $r ?: null;
    }

    /**
     * Back-office approval: creates the API key the client will receive.
     *
     * @param int[] $shops empty = all shops
     */
    public function approve(array $request, int $idEmployee, int $idApprover, array $scopes, array $shops, ?string $expire, string $name): int
    {
        $key = new \PpMcpApiKey();
        $key->name = mb_substr($name, 0, 64);
        // Placeholder token nobody knows; the real one is issued at the token exchange.
        $key->setToken(\PpMcpApiKey::generateToken());
        $key->id_employee = $idEmployee;
        $key->scopes = implode(',', array_values(array_intersect(self::SCOPES, $scopes)));
        $key->shops = implode(',', array_map('intval', $shops));
        $key->active = true;
        $key->date_expire = $expire;
        $key->oauth_client = $request['client_id'];
        $key->token_expire = date('Y-m-d H:i:s', time() - 1);
        if (!$key->add()) {
            throw new \RuntimeException('Could not create the API key.');
        }
        \Db::getInstance()->update('ppmcp_oauth_request', [
            'status' => 'approved',
            'id_employee' => $idApprover,
            'id_ppmcp_key' => (int) $key->id,
        ], '`id_ppmcp_oauth_request` = ' . (int) $request['id_ppmcp_oauth_request'] . ' AND `status` = \'pending\'');

        return (int) $key->id;
    }

    public function deny(array $request, int $idApprover): void
    {
        \Db::getInstance()->update('ppmcp_oauth_request', ['status' => 'denied', 'id_employee' => $idApprover], '`id_ppmcp_oauth_request` = ' . (int) $request['id_ppmcp_oauth_request'] . ' AND `status` = \'pending\'');
    }

    // ------------------------------------------------------------------ token

    public function token(array $post, array $headers): HttpResponse
    {
        if (!self::enabled()) {
            return self::cors(self::oauthError(404, 'invalid_request', 'OAuth is not enabled on this shop.'));
        }
        list($clientId, $clientSecret) = $this->clientCredentials($post, $headers);
        $client = $this->client($clientId);
        if (!$client || !$this->clientAuthenticated($client, $clientSecret)) {
            return self::cors(self::oauthError(401, 'invalid_client', 'Unknown client or wrong client secret.', ['WWW-Authenticate' => 'Basic realm="PrestaPro MCP"']));
        }
        \Db::getInstance()->update('ppmcp_oauth_client', ['last_used' => date('Y-m-d H:i:s')], '`client_id` = \'' . pSQL($client['client_id']) . '\'');
        $grant = isset($post['grant_type']) ? (string) $post['grant_type'] : '';
        if ($grant === 'authorization_code') {
            return self::cors($this->exchangeCode($client, $post));
        }
        if ($grant === 'refresh_token') {
            return self::cors($this->refresh($client, $post));
        }

        return self::cors(self::oauthError(400, 'unsupported_grant_type', 'Supported: authorization_code, refresh_token.'));
    }

    /**
     * RFC 7009 revocation: deletes the API key behind an access or refresh token.
     */
    public function revoke(array $post, array $headers): HttpResponse
    {
        list($clientId, $clientSecret) = $this->clientCredentials($post, $headers);
        $client = $this->client($clientId);
        if (!$client || !$this->clientAuthenticated($client, $clientSecret)) {
            return self::cors(self::oauthError(401, 'invalid_client', 'Unknown client or wrong client secret.'));
        }
        $token = isset($post['token']) ? (string) $post['token'] : '';
        $key = strpos($token, self::REFRESH_PREFIX) === 0 ? $this->keyByRefresh($token, $client['client_id']) : \PpMcpApiKey::findByToken($token);
        if ($key && $key->oauth_client === $client['client_id']) {
            $key->delete();
        }

        return self::cors(new HttpResponse(200, ''));
    }

    private function exchangeCode(array $client, array $post): HttpResponse
    {
        $db = \Db::getInstance();
        $p = _DB_PREFIX_;
        $code = isset($post['code']) ? (string) $post['code'] : '';
        $hash = hash('sha256', $code);
        $r = $code !== '' ? $db->getRow('SELECT * FROM `' . $p . 'ppmcp_oauth_request` WHERE `code_hash` = \'' . pSQL($hash) . '\'', false) : null;
        if (!$r || $r['client_id'] !== $client['client_id']) {
            return self::oauthError(400, 'invalid_grant', 'Unknown or expired authorization code.');
        }
        if ($r['status'] === 'used') {
            // Code replay: revoke what was issued with it (RFC 6749 §4.1.2).
            $key = new \PpMcpApiKey((int) $r['id_ppmcp_key']);
            if (\Validate::isLoadedObject($key)) {
                $key->delete();
            }

            return self::oauthError(400, 'invalid_grant', 'Authorization code already used; the access it gave has been revoked.');
        }
        if ($r['status'] !== 'code_issued' || strtotime((string) $r['code_expire']) < time()) {
            return self::oauthError(400, 'invalid_grant', 'Unknown or expired authorization code.');
        }
        $redirect = isset($post['redirect_uri']) ? (string) $post['redirect_uri'] : '';
        if ($redirect !== '' && $redirect !== $r['redirect_uri']) {
            return self::oauthError(400, 'invalid_grant', 'redirect_uri does not match the authorization request.');
        }
        $verifier = isset($post['code_verifier']) ? (string) $post['code_verifier'] : '';
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) || !hash_equals((string) $r['code_challenge'], $expected)) {
            return self::oauthError(400, 'invalid_grant', 'PKCE verification failed.');
        }
        $db->execute('UPDATE `' . $p . 'ppmcp_oauth_request` SET `status` = \'used\' WHERE `id_ppmcp_oauth_request` = ' . (int) $r['id_ppmcp_oauth_request'] . ' AND `status` = \'code_issued\'');
        if ((int) $db->Affected_Rows() !== 1) {
            return self::oauthError(400, 'invalid_grant', 'Authorization code already used.');
        }
        $key = new \PpMcpApiKey((int) $r['id_ppmcp_key']);
        if (!\Validate::isLoadedObject($key) || !$key->active || $key->oauth_client !== $client['client_id']) {
            return self::oauthError(400, 'invalid_grant', 'The approved access was revoked.');
        }

        return $this->issueTokens($key);
    }

    private function refresh(array $client, array $post): HttpResponse
    {
        $token = isset($post['refresh_token']) ? (string) $post['refresh_token'] : '';
        $key = $this->keyByRefresh($token, $client['client_id']);
        if (!$key) {
            return self::oauthError(400, 'invalid_grant', 'Unknown or revoked refresh token.');
        }
        if (!$key->active || ($key->date_expire && strtotime($key->date_expire) < time())) {
            return self::oauthError(400, 'invalid_grant', 'The access was disabled or has expired. Connect again.');
        }
        $employee = new \Employee((int) $key->id_employee);
        if (!\Validate::isLoadedObject($employee) || !$employee->active) {
            return self::oauthError(400, 'invalid_grant', 'The linked employee is inactive.');
        }

        return $this->issueTokens($key);
    }

    private function keyByRefresh(string $token, string $clientId): ?\PpMcpApiKey
    {
        if (strpos($token, self::REFRESH_PREFIX) !== 0) {
            return null;
        }
        $id = (int) \Db::getInstance()->getValue(
            'SELECT `id_ppmcp_key` FROM `' . _DB_PREFIX_ . 'ppmcp_key` WHERE `refresh_hash` = \'' . pSQL(hash('sha256', $token)) . '\'
             AND `oauth_client` = \'' . pSQL($clientId) . '\'',
            false
        );
        $key = $id ? new \PpMcpApiKey($id) : null;

        return $key && \Validate::isLoadedObject($key) ? $key : null;
    }

    /**
     * New access + refresh token (the previous ones stop working: rotation).
     */
    private function issueTokens(\PpMcpApiKey $key): HttpResponse
    {
        $ttl = max(300, (int) \Configuration::getGlobalValue('PPMCP_OAUTH_ACCESS_TTL') ?: 3600);
        if ($key->date_expire && strtotime($key->date_expire) - time() < $ttl) {
            $ttl = max(1, strtotime($key->date_expire) - time());
        }
        $access = \PpMcpApiKey::generateToken();
        $refresh = self::REFRESH_PREFIX . bin2hex(random_bytes(32));
        $key->setToken($access);
        $key->token_expire = date('Y-m-d H:i:s', time() + $ttl);
        $key->refresh_hash = hash('sha256', $refresh);
        if (!$key->update()) {
            return self::oauthError(500, 'server_error', 'Could not issue the token.');
        }

        return HttpResponse::json(200, [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => $ttl,
            'refresh_token' => $refresh,
            'scope' => implode(' ', $key->getScopes()),
        ], ['Pragma' => 'no-cache']);
    }

    // ------------------------------------------------------------------ helpers

    private function client(string $clientId): ?array
    {
        if (strpos($clientId, self::CLIENT_PREFIX) !== 0 || strlen($clientId) > 64) {
            return null;
        }
        $r = \Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'ppmcp_oauth_client` WHERE `client_id` = \'' . pSQL($clientId) . '\'', false);

        return $r ?: null;
    }

    /**
     * @return string[] [client_id, client_secret]
     */
    private function clientCredentials(array $post, array $headers): array
    {
        $id = isset($post['client_id']) ? (string) $post['client_id'] : '';
        $secret = isset($post['client_secret']) ? (string) $post['client_secret'] : '';
        $auth = isset($headers['authorization']) ? (string) $headers['authorization'] : '';
        if ($auth === '' && !empty($_SERVER['PHP_AUTH_USER'])) {
            $id = $id !== '' ? $id : (string) $_SERVER['PHP_AUTH_USER'];
            $secret = isset($_SERVER['PHP_AUTH_PW']) ? (string) $_SERVER['PHP_AUTH_PW'] : $secret;
        }
        if (preg_match('/^\s*Basic\s+(\S+)\s*$/i', $auth, $m)) {
            $pair = explode(':', (string) base64_decode($m[1]), 2);
            $id = urldecode($pair[0]);
            $secret = isset($pair[1]) ? urldecode($pair[1]) : '';
        }

        return [$id, $secret];
    }

    private function clientAuthenticated(array $client, string $secret): bool
    {
        if (empty($client['secret_hash'])) {
            return true; // public client: PKCE protects the code
        }

        return $secret !== '' && hash_equals((string) $client['secret_hash'], hash('sha256', $secret));
    }

    private function withParams(string $url, array $params): string
    {
        $params = array_filter($params, function ($v) {
            return $v !== null && $v !== '';
        });

        return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public static function oauthError(int $status, string $error, string $description, array $headers = []): HttpResponse
    {
        $headers['Pragma'] = 'no-cache';

        return HttpResponse::json($status, ['error' => $error, 'error_description' => $description], $headers);
    }

    public static function cors(HttpResponse $r): HttpResponse
    {
        // No cookies are involved on these endpoints, so any origin may call them
        // (browser-based clients such as the MCP Inspector).
        $r->headers['Access-Control-Allow-Origin'] = '*';
        $r->headers['Access-Control-Allow-Headers'] = 'Authorization, Content-Type, MCP-Protocol-Version';
        $r->headers['Access-Control-Allow-Methods'] = 'GET, POST, OPTIONS';

        return $r;
    }

    /**
     * Removes stale requests, never-used clients and approvals that were never exchanged.
     */
    public function cleanup(): void
    {
        $db = \Db::getInstance();
        $p = _DB_PREFIX_;
        $db->execute('DELETE FROM `' . $p . 'ppmcp_oauth_request` WHERE `date_add` < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        $db->execute(
            'DELETE k FROM `' . $p . 'ppmcp_key` k WHERE k.`oauth_client` IS NOT NULL AND k.`refresh_hash` IS NULL
             AND k.`date_add` < DATE_SUB(NOW(), INTERVAL 1 DAY)'
        );
        $db->execute(
            'DELETE c FROM `' . $p . 'ppmcp_oauth_client` c
             LEFT JOIN `' . $p . 'ppmcp_key` k ON k.`oauth_client` = c.`client_id`
             WHERE k.`id_ppmcp_key` IS NULL
               AND ((c.`last_used` IS NULL AND c.`date_add` < DATE_SUB(NOW(), INTERVAL 1 DAY))
                 OR c.`last_used` < DATE_SUB(NOW(), INTERVAL 30 DAY))'
        );
    }
}
