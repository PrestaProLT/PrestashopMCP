<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Auth;

/**
 * Resolves the bearer token of a request to an active API key and its
 * active employee. Token sources, in order:
 *   Authorization: Bearer <token>   (also REDIRECT_HTTP_AUTHORIZATION under CGI/FPM)
 *   X-Api-Key / X-Mcp-Token: <token> (fallback: Apache + FPM/CGI on PS 1.7/8
 *                                    drops Authorization unless CGI mode is on)
 * Tokens in the query string are never accepted.
 */
class Authenticator
{
    /** @var string reason of the last failure, for the activity log */
    public $failure = '';
    /** @var bool true when an OAuth access token expired (client should refresh) */
    public $tokenExpired = false;

    public static function tokenFromHeaders(array $headers): string
    {
        $auth = '';
        foreach (['authorization'] as $h) {
            if (!empty($headers[$h])) {
                $auth = (string) $headers[$h];
            }
        }
        foreach (['REDIRECT_HTTP_AUTHORIZATION', 'REDIRECT_REDIRECT_HTTP_AUTHORIZATION'] as $k) {
            if ($auth === '' && !empty($_SERVER[$k])) {
                $auth = (string) $_SERVER[$k];
            }
        }
        if ($auth === '' && function_exists('apache_request_headers')) {
            foreach ((array) apache_request_headers() as $n => $v) {
                if (strcasecmp((string) $n, 'Authorization') === 0) {
                    $auth = (string) $v;
                }
            }
        }
        if ($auth !== '' && preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m)) {
            return $m[1];
        }
        foreach (['x-api-key', 'x-mcp-token'] as $h) {
            if (!empty($headers[$h])) {
                return trim((string) $headers[$h]);
            }
        }

        return '';
    }

    /**
     * @return array|null [\PpMcpApiKey, \Employee]
     */
    public function authenticate(string $token): ?array
    {
        if ($token === '') {
            $this->failure = 'missing token';

            return null;
        }
        $key = \PpMcpApiKey::findByToken($token);
        if (!$key) {
            $this->failure = 'unknown token';

            return null;
        }
        if ($key->active && $key->isAccessTokenExpired()) {
            $this->failure = 'access token expired';
            $this->tokenExpired = true;

            return null;
        }
        if (!$key->isUsable()) {
            $this->failure = 'key disabled or expired';

            return null;
        }
        $employee = new \Employee((int) $key->id_employee);
        if (!\Validate::isLoadedObject($employee) || !$employee->active) {
            $this->failure = 'linked employee missing or inactive';

            return null;
        }

        return [$key, $employee];
    }
}
