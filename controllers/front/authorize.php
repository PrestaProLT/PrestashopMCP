<?php
/**
 * OAuth authorization endpoint. Shows a one-time code that an employee
 * approves in the back office (PrestaPro MCP > Approve connection); the page
 * polls (?poll=<request>) and then returns the browser to the AI app.
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

class PpMcpAuthorizeModuleFrontController extends PpMcpBareFrontController
{
    const COOKIE = 'ppmcp_oauth_';

    protected function handleRequest(): HttpResponse
    {
        $server = new OAuthServer($this->module);
        if (isset($_GET['poll'])) {
            $rid = (string) $_GET['poll'];
            $secret = isset($_COOKIE[self::COOKIE . $rid]) ? (string) $_COOKIE[self::COOKIE . $rid] : '';
            $res = $server->poll($rid, $secret);

            return HttpResponse::json(200, $res, ['Referrer-Policy' => 'no-referrer']);
        }
        if ($this->httpMethod() !== 'GET') {
            return $this->page(['error' => $this->t('Open this page from your AI app (Claude, ChatGPT…) when adding the connector.')], 405);
        }
        $q = array_map(function ($v) { return is_scalar($v) ? (string) $v : ''; }, $_GET);
        $res = $server->startAuthorization(
            $q,
            Tools::getRemoteAddr(),
            isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '',
            (int) $this->context->shop->id
        );
        if (isset($res['redirect'])) {
            return new HttpResponse(302, '', ['Location' => $res['redirect'], 'Referrer-Policy' => 'no-referrer']);
        }
        if (isset($res['page_error'])) {
            return $this->page(['error' => $this->t($res['page_error'])], 400);
        }
        $r = $res['request'];
        $secure = Tools::usingSecureMode();
        $cookie = self::COOKIE . $r['request_key'] . '=' . $res['secret'] . '; Path=/; Max-Age=' . OAuthServer::REQUEST_TTL . '; HttpOnly; SameSite=Lax' . ($secure ? '; Secure' : '');
        $pollUrl = $this->context->link->getModuleLink($this->module->name, 'authorize', ['poll' => $r['request_key']], true);

        return $this->page([
            'code' => $r['user_code'],
            'client_name' => (string) $r['client_name'],
            'redirect_host' => (string) parse_url($r['redirect_uri'], PHP_URL_HOST),
            'scopes' => $r['scope'] !== '' ? explode(' ', $r['scope']) : [],
            'poll_url' => $pollUrl,
            'minutes' => (int) (OAuthServer::REQUEST_TTL / 60),
        ], 200, ['Set-Cookie' => $cookie]);
    }

    private function t(string $s, array $params = []): string
    {
        return $this->module->getTranslator()->trans($s, $params, 'Modules.Ppmcp.Shop');
    }

    private function page(array $vars, int $status, array $headers = []): HttpResponse
    {
        $nonce = bin2hex(random_bytes(12));
        $shop = $this->context->shop;
        $h = function ($v) {
            return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };
        $txt = [
            'title' => $this->t('Connect an AI assistant to %shop%', ['%shop%' => $shop->name]),
            'wants' => $this->t('wants access to this shop through PrestaPro MCP.'),
            'callback' => $this->t('After approval you return to:'),
            'scopes' => $this->t('Requested access:'),
            'step1' => $this->t('Open the back office of this shop in another tab and log in.'),
            'step2' => $this->t('Go to Modules > Module Manager > PrestaShop MCP - easy connect with AI > Configure > Approve connection and enter this code:'),
            'step3' => $this->t('Choose what the assistant may do and approve. This page continues by itself.'),
            'expires' => $this->t('The code is valid for %min% minutes.', ['%min%' => isset($vars['minutes']) ? $vars['minutes'] : 15]),
            'warning' => $this->t('Only approve a code you see on this page, right after you started connecting. Never approve a code someone sent you.'),
            'waiting' => $this->t('Waiting for approval…'),
            'approved' => $this->t('Approved. Returning to your AI app…'),
            'denied' => $this->t('The connection was declined.'),
            'expired' => $this->t('The code expired. Start connecting again from your AI app.'),
            'unknown' => $this->t('This connection request is no longer valid. Start connecting again from your AI app.'),
            'error_title' => $this->t('Cannot connect'),
        ];

        if (!empty($vars['error'])) {
            $body = '<h1>' . $h($txt['error_title']) . '</h1><p>' . $h($vars['error']) . '</p>';
        } else {
            $scopes = '';
            foreach ($vars['scopes'] as $s) {
                $scopes .= '<span class="scope">' . $h($s) . '</span>';
            }
            $js = json_encode(['url' => $vars['poll_url'], 'txt' => $txt], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
            $body = '<h1>' . $h($txt['title']) . '</h1>'
                . '<p><span class="client">' . $h($vars['client_name']) . '</span> ' . $h($txt['wants']) . '</p>'
                . '<p class="muted">' . $h($txt['callback']) . ' <strong>' . $h($vars['redirect_host']) . '</strong>'
                . ($scopes !== '' ? '<br>' . $h($txt['scopes']) . ' ' . $scopes : '') . '</p>'
                . '<ol><li>' . $h($txt['step1']) . '</li><li>' . $h($txt['step2']) . '</li></ol>'
                . '<div class="code">' . $h($vars['code']) . '</div>'
                . '<ol start="3"><li>' . $h($txt['step3']) . '</li></ol>'
                . '<p class="muted">' . $h($txt['expires']) . '</p>'
                . '<div class="warn">' . $h($txt['warning']) . '</div>'
                . '<div class="status" id="st">' . $h($txt['waiting']) . '</div>'
                . '<script nonce="' . $nonce . '">(function () {
  var cfg = ' . $js . ', st = document.getElementById("st"), done = false;
  function show(m, c) { st.textContent = m; st.className = "status " + (c || ""); }
  function tick() {
    if (done) { return; }
    fetch(cfg.url, { credentials: "same-origin", cache: "no-store" }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.status === "pending") { setTimeout(tick, 2500); return; }
      done = true;
      show(cfg.txt[d.status === "approved" ? "approved" : (d.status === "denied" ? "denied" : (d.status === "expired" ? "expired" : "unknown"))], d.status === "approved" ? "ok" : "bad");
      if (d.redirect) { setTimeout(function () { window.location.replace(d.redirect); }, d.status === "approved" ? 300 : 2500); }
    }).catch(function () { setTimeout(tick, 5000); });
  }
  setTimeout(tick, 2500);
})();</script>';
        }

        $html = '<!DOCTYPE html><html lang="' . $h($this->context->language->iso_code) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
            . '<title>' . $h($txt['title']) . '</title><style>'
            . 'body{margin:0;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f4f5f7;color:#1f2328}'
            . '.card{max-width:520px;margin:6vh auto;background:#fff;border-radius:12px;padding:28px 32px;box-shadow:0 2px 12px rgba(0,0,0,.08)}'
            . 'h1{font-size:20px;margin:0 0 16px}.client{font-weight:600}.muted{color:#59636e;font-size:14px}ol{padding-left:20px}li{margin:8px 0}'
            . '.code{font:700 34px/1.2 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:4px;text-align:center;background:#f0f4ff;border:2px dashed #2d7ff9;border-radius:10px;padding:14px;margin:14px 0;user-select:all}'
            . '.warn{background:#fff8e6;border-left:4px solid #e8a400;padding:10px 12px;font-size:14px;border-radius:4px;margin-top:16px}'
            . '.status{margin-top:18px;font-weight:600}.status.ok{color:#1a7f37}.status.bad{color:#cf222e}'
            . '.scope{display:inline-block;background:#eef1f4;border-radius:4px;padding:1px 8px;margin-right:4px;font-size:13px}'
            . '</style></head><body><div class="card">' . $body . '</div></body></html>';

        return new HttpResponse($status, $html, array_merge([
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-" . $nonce . "'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
        ], $headers));
    }
}
