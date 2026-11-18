{**
 * @author    PrestaPro
 * @license   AFL-3.0
 *}
{if !$ppmcp_oauth_enabled}
  <div class="alert alert-warning">
    {if !$ppmcp_oauth_on}
      {l s='OAuth sign-in is turned off in the module settings. Turn it on to connect Claude, ChatGPT and other AI apps with a code.' d='Modules.Ppmcp.Admin'}
    {else}
      {l s='OAuth sign-in needs friendly URLs (Shop Parameters > Traffic & SEO). Until then, connect with an API key.' d='Modules.Ppmcp.Admin'}
    {/if}
  </div>
{/if}

{if $ppmcp_result == 'approved'}
  <div class="alert alert-success">
    <h4>{l s='Connection approved' d='Modules.Ppmcp.Admin'}</h4>
    <p>{l s='The connecting page continues by itself. The access is listed under API keys, where you can disable or delete it at any time.' d='Modules.Ppmcp.Admin'}
      <a href="{$ppmcp_keys_url|escape:'htmlall':'UTF-8'}">{l s='API keys' d='Modules.Ppmcp.Admin'}</a></p>
  </div>
{elseif $ppmcp_result == 'denied'}
  <div class="alert alert-info">{l s='Connection declined. Nothing was created.' d='Modules.Ppmcp.Admin'}</div>
{/if}

{if $ppmcp_request}
  <form method="post" action="{$ppmcp_action|escape:'htmlall':'UTF-8'}" class="form-horizontal">
    <input type="hidden" name="id_request" value="{$ppmcp_request.id|intval}">
    <input type="hidden" name="user_code" value="{$ppmcp_request.user_code|escape:'htmlall':'UTF-8'}">
    <div class="panel">
      <div class="panel-heading"><i class="icon-link"></i> {l s='Approve connection' d='Modules.Ppmcp.Admin'} <code>{$ppmcp_request.user_code|escape:'htmlall':'UTF-8'}</code></div>
      <div class="alert alert-warning">{l s='Approve only if you started this connection yourself just now and see this same code on your screen. Never approve a code that someone sent you: they would get access to the shop.' d='Modules.Ppmcp.Admin'}</div>
      <table class="table">
        <tr><th style="width:220px">{l s='Application' d='Modules.Ppmcp.Admin'}</th><td><strong>{$ppmcp_request.client_name|escape:'htmlall':'UTF-8'}</strong></td></tr>
        <tr><th>{l s='Returns to' d='Modules.Ppmcp.Admin'}</th><td><code>{$ppmcp_request.redirect_host|escape:'htmlall':'UTF-8'}</code></td></tr>
        <tr><th>{l s='Started from' d='Modules.Ppmcp.Admin'}</th><td>{$ppmcp_request.ip|escape:'htmlall':'UTF-8'} · {$ppmcp_request.date_add|escape:'htmlall':'UTF-8'}<br><small class="text-muted">{$ppmcp_request.user_agent|escape:'htmlall':'UTF-8'}</small></td></tr>
        <tr><th>{l s='Requested scopes' d='Modules.Ppmcp.Admin'}</th><td>{if $ppmcp_request.requested}{foreach $ppmcp_request.requested as $s}<span class="badge">{$s|escape:'htmlall':'UTF-8'}</span> {/foreach}{else}—{/if}</td></tr>
      </table>

      <div class="form-group">
        <label class="control-label col-lg-3">{l s='Name' d='Admin.Global'}</label>
        <div class="col-lg-6"><input type="text" name="key_name" maxlength="64" value="{$ppmcp_request.default_name|escape:'htmlall':'UTF-8'}"></div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-3">{l s='Acts as employee' d='Modules.Ppmcp.Admin'}</label>
        <div class="col-lg-6">
          <select name="id_employee">
            {foreach $ppmcp_employees as $e}<option value="{$e.id|intval}"{if $e.id == $ppmcp_me} selected{/if}>{$e.name|escape:'htmlall':'UTF-8'}</option>{/foreach}
          </select>
          <p class="help-block">{l s='Every call runs with this employee\'s back-office permissions.' d='Modules.Ppmcp.Admin'}</p>
        </div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-3">{l s='Scopes' d='Modules.Ppmcp.Admin'}</label>
        <div class="col-lg-9">
          <div class="checkbox"><label><input type="checkbox" checked disabled> {l s='read — read catalog, orders, customers, diagnostics' d='Modules.Ppmcp.Admin'}</label></div>
          <div class="checkbox"><label><input type="checkbox" name="scope[write]" value="1"> {l s='write — change data (products, stock, order status, cart rules…)' d='Modules.Ppmcp.Admin'}</label></div>
          <div class="checkbox"><label><input type="checkbox" name="scope[code]" value="1"> {l s='code — read module source files (module_explain, module_file_read…)' d='Modules.Ppmcp.Admin'}</label></div>
        </div>
      </div>
      {if $ppmcp_shops}
        <div class="form-group">
          <label class="control-label col-lg-3">{l s='Restrict to shops' d='Modules.Ppmcp.Admin'}</label>
          <div class="col-lg-9">
            {foreach $ppmcp_shops as $s}<div class="checkbox"><label><input type="checkbox" name="shop[{$s.id|intval}]" value="1"{if $s.id == $ppmcp_request.id_shop} checked{/if}> {$s.name|escape:'htmlall':'UTF-8'}</label></div>{/foreach}
            <p class="help-block">{l s='Leave all unchecked to allow every shop.' d='Modules.Ppmcp.Admin'}</p>
          </div>
        </div>
      {/if}
      <div class="form-group">
        <label class="control-label col-lg-3">{l s='Access valid for' d='Modules.Ppmcp.Admin'}</label>
        <div class="col-lg-3">
          <select name="lifetime">
            <option value="30">{l s='30 days' d='Modules.Ppmcp.Admin'}</option>
            <option value="90" selected>{l s='90 days' d='Modules.Ppmcp.Admin'}</option>
            <option value="365">{l s='1 year' d='Modules.Ppmcp.Admin'}</option>
            <option value="0">{l s='Until revoked' d='Modules.Ppmcp.Admin'}</option>
          </select>
        </div>
      </div>
      <div class="panel-footer">
        <button type="submit" name="submitPpMcpApprove" class="btn btn-primary pull-right"><i class="process-icon-ok"></i> {l s='Approve' d='Modules.Ppmcp.Admin'}</button>
        <button type="submit" name="submitPpMcpDeny" class="btn btn-default"><i class="process-icon-cancel"></i> {l s='Decline' d='Modules.Ppmcp.Admin'}</button>
      </div>
    </div>
  </form>
{else}
  <form method="post" action="{$ppmcp_action|escape:'htmlall':'UTF-8'}">
    <div class="panel">
      <div class="panel-heading"><i class="icon-link"></i> {l s='Approve connection' d='Modules.Ppmcp.Admin'}</div>
      <p>{l s='Add this shop as a connector in your AI app (Claude, ChatGPT…) with the server URL below. The app opens a page with a code: enter it here.' d='Modules.Ppmcp.Admin'}</p>
      <pre style="user-select:all">{$ppmcp_endpoint|escape:'htmlall':'UTF-8'}</pre>
      <div class="form-inline">
        <input type="text" name="user_code" class="form-control" placeholder="XXXX-XXXX" maxlength="12" autocomplete="off" autofocus style="font-family:monospace;font-size:20px;letter-spacing:3px;width:220px;text-transform:uppercase">
        <button type="submit" name="submitPpMcpLookup" class="btn btn-primary">{l s='Continue' d='Modules.Ppmcp.Admin'}</button>
      </div>
    </div>
  </form>
{/if}
