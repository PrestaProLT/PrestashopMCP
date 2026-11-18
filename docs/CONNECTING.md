# Connecting an AI assistant

The module has no entry in the back-office sidebar: open it from **Modules → Module Manager →
PrestaShop MCP - easy connect with AI → Configure**. Its pages (Settings, Approve connection, API keys,
Activity log, Tools & skills) are tabs at the top of that page.

## Sign in with OAuth (Claude web / mobile / Desktop, ChatGPT) — recommended

No key to copy. Needs friendly URLs (Shop Parameters → Traffic & SEO) and the **OAuth sign-in**
setting (on by default).

1. Add the shop as a connector with the endpoint URL from the module's Settings tab, e.g.
   `https://your-shop.tld/module/ppmcp/server`. Leave client ID / secret empty.
   * **Claude**: claude.ai → Settings → Connectors → Add custom connector. Connectors added on
     claude.ai are also available in the Claude mobile apps and Claude Desktop.
   * **ChatGPT**: Settings → Apps & Connectors → Advanced settings → turn on Developer mode, then
     Create a connector with the URL and OAuth authentication.
2. The app opens a page of your shop showing a code such as `KTRB-WMZQ`.
3. In the back office open the module → **Approve connection**, enter the code, check the app and
   where it returns to, choose the employee it acts as, scopes (read / write / code), shops and how
   long the access lasts (30 / 90 / 365 days or until revoked), and approve. The connecting page
   continues by itself.
4. The access appears on the **API keys** tab ("Connected via: OAuth …"). Disable or delete it there
   to cut the app off.

Only approve a code you see on your own screen right after you started connecting — never a code
someone sent you.

How it works: the MCP endpoint answers 401 with `resource_metadata` (RFC 9728); discovery documents
are served at `/.well-known/oauth-protected-resource/…` and `/.well-known/oauth-authorization-server`
(RFC 8414); apps register themselves (RFC 7591, only redirect hosts listed in **OAuth redirect hosts**:
claude.ai, claude.com, chatgpt.com, localhost, 127.0.0.1 by default); authorization code + PKCE S256;
access tokens last 1 hour (setting) and are renewed with rotating refresh tokens. The access token is an
ordinary API key, so scopes, employee permissions, shop restriction and logging are the same.

## API key (Claude Code, Cursor, scripts)

1. Open the module → **API keys** and create a key:
   * **Acts as employee** – every call runs with that employee's back-office permissions.
     Create a dedicated employee with a limited profile for limited assistants.
   * **Scopes** – `read` (always), `write` (changes data), `code` (read module source files).
   * Optional shop restriction (multistore) and expiry date.
   The token (`ppmcp_…`) is shown once.
2. The endpoint URL is on the module configuration page (one per shop in multistore):
   `https://your-shop.tld/module/ppmcp/server` (or `index.php?fc=module&module=ppmcp&controller=server`
   without friendly URLs).

## Claude Code

```bash
claude mcp add --transport http prestashop https://your-shop.tld/module/ppmcp/server \
  --header "Authorization: Bearer ppmcp_xxx"
```

## Claude Desktop (config file, needs Node.js)

```json
{
  "mcpServers": {
    "prestashop": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://your-shop.tld/module/ppmcp/server",
               "--header", "Authorization:${AUTH_HEADER}", "--transport", "http-only"],
      "env": { "AUTH_HEADER": "Bearer ppmcp_xxx" }
    }
  }
}
```

## Cursor / other clients

```json
{ "mcpServers": { "prestashop": { "url": "https://your-shop.tld/module/ppmcp/server",
  "headers": { "Authorization": "Bearer ppmcp_xxx" } } } }
```

## Troubleshooting

| symptom | cause / fix |
|---|---|
| 401 although the key is right | Apache + PHP-FPM/CGI strips `Authorization` on PS 1.7/8. Send the key as `X-Api-Key: ppmcp_xxx`, or add `CGIPassAuth On` to `.htaccess`, or enable Webservice → "CGI mode" and regenerate .htaccess. |
| 503 | Server disabled in the module configuration. |
| 403 Origin not allowed | Browser-based client: add its origin in the module configuration. |
| HTML 404 / redirect | Module disabled in that shop, or a non-main shop URL (use the shop's main domain). |
| AI app says it cannot find OAuth / authorization server | Friendly URLs off, OAuth sign-in off, or a web-server rule blocks `/.well-known/`. Open `https://your-shop.tld/.well-known/oauth-authorization-server`: it must return JSON. |
| "Redirect host … is not allowed" | Add the AI app's callback host under Settings → OAuth redirect hosts. |
| Connecting page never continues | The code was not approved within 15 minutes, or approved in another shop's back office. Start again from the app. |
| Tool missing | Key lacks the scope, employee lacks the permission, read-only mode, or the tool is disabled on the *Tools & skills* page. `mcp_whoami` explains. |
| Module tools missing | *Tools & skills* page shows provider errors; the module must be active in the shop and registered on `actionPpMcpRegisterTools`. |
