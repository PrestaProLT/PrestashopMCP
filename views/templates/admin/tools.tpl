{**
 * @author    PrestaPro
 * @license   AFL-3.0
 *}
{if $ppmcp_read_only}
  <div class="alert alert-warning">{l s='Read-only mode is on: write tools are hidden from every API key.' d='Modules.Ppmcp.Admin'}</div>
{/if}
{if $ppmcp_provider_errors|count}
  <div class="alert alert-danger">
    <strong>{l s='Some module tools could not be registered:' d='Modules.Ppmcp.Admin'}</strong>
    <ul>{foreach $ppmcp_provider_errors as $e}<li>{$e|escape:'htmlall':'UTF-8'}</li>{/foreach}</ul>
  </div>
{/if}

<form method="post" action="{$ppmcp_action|escape:'htmlall':'UTF-8'}">
  <div class="panel">
    <div class="panel-heading"><i class="icon-wrench"></i> {l s='MCP tools' d='Modules.Ppmcp.Admin'}</div>
    <p class="help-block">
      {l s='Naming convention: <owner>_<resource>_<action>. Core owners: shop, catalog, order, customer, cart, diagnose, module, mcp. Any other owner is the technical name of the module providing the tool. Unchecked tools are hidden from every assistant. Tools are also hidden when the API key lacks the scope or the employee lacks the back-office permission shown.' d='Modules.Ppmcp.Admin'}
    </p>
    {foreach $ppmcp_groups as $g}
      <h4>{if $g.module}<i class="icon-puzzle-piece"></i>{else}<i class="icon-cube"></i>{/if} {$g.label|escape:'htmlall':'UTF-8'} <small>({$g.tools|count})</small></h4>
      <table class="table table-condensed">
        <thead><tr><th style="width:40px">{l s='On' d='Modules.Ppmcp.Admin'}</th><th>{l s='Tool' d='Modules.Ppmcp.Admin'}</th><th>{l s='Type' d='Modules.Ppmcp.Admin'}</th><th>{l s='Requires' d='Modules.Ppmcp.Admin'}</th><th>{l s='Description' d='Admin.Global'}</th></tr></thead>
        <tbody>
        {foreach $g.tools as $t}
          <tr>
            <td><input type="checkbox" name="tool_enabled[{$t.name|escape:'htmlall':'UTF-8'}]" value="1" {if $t.enabled}checked{/if}></td>
            <td><code>{$t.name|escape:'htmlall':'UTF-8'}</code><br><small>{$t.title|escape:'htmlall':'UTF-8'}</small></td>
            <td>{if $t.write}<span class="badge badge-warning">{if $t.destructive}{l s='destructive' d='Modules.Ppmcp.Admin'}{else}{l s='write' d='Modules.Ppmcp.Admin'}{/if}</span>{else}<span class="badge badge-success">{l s='read' d='Modules.Ppmcp.Admin'}</span>{/if}{if $t.scope == 'code'} <span class="badge">code</span>{/if}</td>
            <td><small>{$t.permissions|escape:'htmlall':'UTF-8'}</small></td>
            <td><small>{$t.description|truncate:260:'…'|escape:'htmlall':'UTF-8'}</small></td>
          </tr>
        {/foreach}
        </tbody>
      </table>
    {/foreach}
    <div class="panel-footer">
      <button type="submit" name="submitPpMcpTools" class="btn btn-default pull-right"><i class="process-icon-save"></i> {l s='Save' d='Admin.Actions'}</button>
    </div>
  </div>
</form>

<div class="panel">
  <div class="panel-heading"><i class="icon-book"></i> {l s='Skills (playbooks)' d='Modules.Ppmcp.Admin'} <span class="badge">{$ppmcp_skills|count}</span></div>
  <p class="help-block">{l s='Skills are markdown playbooks the assistant reads with mcp_skill_get (also exposed as MCP prompts and resources). Add your own in modules/ppmcp/skills/ or ship them from another module in mcp/skills/.' d='Modules.Ppmcp.Admin'}</p>
  <table class="table table-condensed">
    <thead><tr><th>{l s='Name' d='Admin.Global'}</th><th>{l s='Title' d='Modules.Ppmcp.Admin'}</th><th>{l s='Owner' d='Modules.Ppmcp.Admin'}</th><th>{l s='When to use' d='Modules.Ppmcp.Admin'}</th></tr></thead>
    <tbody>
    {foreach $ppmcp_skills as $s}
      <tr><td><code>{$s.name|escape:'htmlall':'UTF-8'}</code></td><td>{$s.title|escape:'htmlall':'UTF-8'}</td><td>{$s.owner|escape:'htmlall':'UTF-8'}</td><td><small>{$s.when_to_use|escape:'htmlall':'UTF-8'}</small></td></tr>
    {/foreach}
    </tbody>
  </table>
</div>
