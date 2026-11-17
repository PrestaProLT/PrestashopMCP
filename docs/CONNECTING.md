# Connecting an AI assistant

1. Install the module; open **PrestaPro MCP → API keys** and create a key:
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

## claude.ai custom connectors

Add the endpoint as a custom connector and set the request header `Authorization: Bearer ppmcp_xxx`
(needs the "request headers" connector option for your organization). OAuth sign-in is planned for a
later version.

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
| Tool missing | Key lacks the scope, employee lacks the permission, read-only mode, or the tool is disabled on the *Tools & skills* page. `mcp_whoami` explains. |
| Module tools missing | *Tools & skills* page shows provider errors; the module must be active in the shop and registered on `actionPpMcpRegisterTools`. |
