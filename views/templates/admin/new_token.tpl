{**
 * @author    PrestaPro
 * @license   AFL-3.0
 *}
<div class="alert alert-success">
  <h4>{l s='Your new MCP API key' d='Modules.Ppmcp.Admin'}</h4>
  <p>{l s='Copy it now: it is stored only as a hash and will never be shown again.' d='Modules.Ppmcp.Admin'}</p>
  <pre style="font-size:14px;user-select:all">{$ppmcp_new_token|escape:'htmlall':'UTF-8'}</pre>
  <p>{l s='Claude Code:' d='Modules.Ppmcp.Admin'}</p>
  <pre style="user-select:all">claude mcp add --transport http prestashop {$ppmcp_endpoint|escape:'htmlall':'UTF-8'} --header "Authorization: Bearer {$ppmcp_new_token|escape:'htmlall':'UTF-8'}"</pre>
</div>
