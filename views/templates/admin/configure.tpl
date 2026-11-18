{**
 * PrestaPro MCP — configuration page header: endpoint + how to connect.
 * @author    PrestaPro
 * @license   AFL-3.0
 *}
<div class="panel ppmcp-panel">
  <div class="panel-heading"><i class="icon-link"></i> {l s='Connect an AI assistant' d='Modules.Ppmcp.Admin'} <span class="badge">v{$ppmcp_version|escape:'htmlall':'UTF-8'} · PrestaShop {$ppmcp_ps_version|escape:'htmlall':'UTF-8'}</span></div>


  <h4>{l s='MCP endpoint (Streamable HTTP)' d='Modules.Ppmcp.Admin'}</h4>
  {if $ppmcp_endpoints|count > 1}
    <table class="table">
      <thead><tr><th>{l s='Shop' d='Modules.Ppmcp.Admin'}</th><th>{l s='Endpoint URL' d='Modules.Ppmcp.Admin'}</th></tr></thead>
      <tbody>
      {foreach $ppmcp_endpoints as $ep}
        <tr><td>#{$ep.id_shop|intval} {$ep.name|escape:'htmlall':'UTF-8'}</td><td><code>{$ep.url|escape:'htmlall':'UTF-8'}</code></td></tr>
      {/foreach}
      </tbody>
    </table>
    <p class="help-block">{l s='Multistore: every shop has its own endpoint; tools default to that shop and accept id_shop to work on another one (if the key allows it).' d='Modules.Ppmcp.Admin'}</p>
  {else}
    <pre>{$ppmcp_endpoint|escape:'htmlall':'UTF-8'}</pre>
  {/if}

  {if !$ppmcp_auth_header_ok}
    <div class="alert alert-warning">
      {l s='Your server may strip the Authorization header (PHP CGI/FPM behind Apache). If the assistant gets "401 Unauthorized", send the key as an X-Api-Key header instead, or add this line to the top of .htaccess:' d='Modules.Ppmcp.Admin'}
      <pre>CGIPassAuth On</pre>
    </div>
  {/if}

  <h4>{l s='Claude (web, mobile, Desktop) and ChatGPT: sign in with OAuth' d='Modules.Ppmcp.Admin'}</h4>
  {if $ppmcp_oauth_enabled}
    <ol>
      <li>{l s='Claude: claude.ai > Settings > Connectors > Add custom connector. ChatGPT: Settings > Apps & Connectors > Create (developer mode). Paste the server URL above, leave client ID/secret empty.' d='Modules.Ppmcp.Admin'}</li>
      <li>{l s='The app opens a page of this shop showing a code. Enter it on' d='Modules.Ppmcp.Admin'} <a href="{$ppmcp_connect_url|escape:'htmlall':'UTF-8'}">{l s='Approve connection' d='Modules.Ppmcp.Admin'}</a>, {l s='choose the employee, scopes and lifetime, and approve.' d='Modules.Ppmcp.Admin'}</li>
      <li>{l s='Connectors added on claude.ai also work in the Claude mobile app and Claude Desktop.' d='Modules.Ppmcp.Admin'}</li>
    </ol>
  {elseif !$ppmcp_oauth_on}
    <p class="text-muted">{l s='OAuth sign-in is off (see the settings below).' d='Modules.Ppmcp.Admin'}</p>
  {else}
    <div class="alert alert-warning">{l s='OAuth sign-in needs friendly URLs (Shop Parameters > Traffic & SEO > Friendly URL). Until then, use an API key.' d='Modules.Ppmcp.Admin'}</div>
  {/if}

  <h4>{l s='Other clients: API key' d='Modules.Ppmcp.Admin'}</h4>
  <p>{l s='Create a key on the API keys tab and send it as "Authorization: Bearer <key>".' d='Modules.Ppmcp.Admin'}</p>

  <h4>Claude Code</h4>
  <pre>claude mcp add --transport http prestashop {$ppmcp_endpoint|escape:'htmlall':'UTF-8'} --header "Authorization: Bearer ppmcp_YOUR_KEY"</pre>

  <h4>Claude Desktop (claude_desktop_config.json, needs Node.js)</h4>
  <pre>{literal}{
  "mcpServers": {
    "prestashop": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "{/literal}{$ppmcp_endpoint|escape:'htmlall':'UTF-8'}{literal}",
               "--header", "Authorization:${AUTH_HEADER}", "--transport", "http-only"],
      "env": { "AUTH_HEADER": "Bearer ppmcp_YOUR_KEY" }
    }
  }
}{/literal}</pre>

  <h4>Cursor (.cursor/mcp.json) / other clients</h4>
  <pre>{literal}{ "mcpServers": { "prestashop": { "url": "{/literal}{$ppmcp_endpoint|escape:'htmlall':'UTF-8'}{literal}", "headers": { "Authorization": "Bearer ppmcp_YOUR_KEY" } } } }{/literal}</pre>

  <p class="help-block">
    {l s='claude.ai web connectors: add the endpoint as a custom connector with the request header "Authorization: Bearer ppmcp_…" (requires the request-headers option for your organization). Name the server "prestashop" or another short name: tool names are prefixed with it.' d='Modules.Ppmcp.Admin'}
  </p>
</div>
