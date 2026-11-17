{**
 * @author    PrestaPro
 * @license   AFL-3.0
 *}
<div class="panel">
  <div class="panel-heading">
    <i class="icon-time"></i> {l s='MCP request' d='Modules.Ppmcp.Admin'} #{$log->id|intval}
    <span class="badge">{$log->status|escape:'htmlall':'UTF-8'}</span>
  </div>
  <table class="table">
    <tr><th>{l s='Date' d='Admin.Global'}</th><td>{$log->date_add|escape:'htmlall':'UTF-8'}</td><th>{l s='Request id' d='Modules.Ppmcp.Admin'}</th><td><code>{$log->request_id|escape:'htmlall':'UTF-8'}</code></td></tr>
    <tr><th>{l s='Method' d='Modules.Ppmcp.Admin'}</th><td>{$log->rpc_method|escape:'htmlall':'UTF-8'}</td><th>{l s='Tool' d='Modules.Ppmcp.Admin'}</th><td><code>{$log->tool|escape:'htmlall':'UTF-8'}</code> ({$log->owner|escape:'htmlall':'UTF-8'})</td></tr>
    <tr><th>{l s='API key' d='Modules.Ppmcp.Admin'}</th><td>{$key_name|escape:'htmlall':'UTF-8'}</td><th>{l s='Employee' d='Admin.Global'}</th><td>{$employee_name|escape:'htmlall':'UTF-8'}</td></tr>
    <tr><th>{l s='Shop / language' d='Modules.Ppmcp.Admin'}</th><td>#{$log->id_shop|intval} / #{$log->id_lang|intval}</td><th>{l s='Client' d='Modules.Ppmcp.Admin'}</th><td>{$log->client|escape:'htmlall':'UTF-8'} · {l s='protocol' d='Modules.Ppmcp.Admin'} {$log->protocol_version|escape:'htmlall':'UTF-8'} · {$log->ip|escape:'htmlall':'UTF-8'}</td></tr>
    <tr><th>{l s='Write' d='Modules.Ppmcp.Admin'}</th><td>{if $log->is_write}{if $log->dry_run}{l s='dry run (nothing saved)' d='Modules.Ppmcp.Admin'}{else}{l s='yes, saved' d='Modules.Ppmcp.Admin'}{/if}{else}{l s='no (read-only)' d='Modules.Ppmcp.Admin'}{/if}</td><th>{l s='Duration' d='Modules.Ppmcp.Admin'}</th><td>{$log->duration_ms|intval} ms</td></tr>
    {if $log->error}<tr><th>{l s='Error' d='Admin.Global'}</th><td colspan="3" class="text-danger">{$log->error|escape:'htmlall':'UTF-8'}</td></tr>{/if}
  </table>

  {if $changes|count}
    <h4>{l s='Changes' d='Modules.Ppmcp.Admin'} ({$changes|count})</h4>
    <table class="table table-condensed">
      <thead><tr><th>{l s='Entity' d='Modules.Ppmcp.Admin'}</th><th>ID</th><th>{l s='Field' d='Modules.Ppmcp.Admin'}</th><th>{l s='Shop' d='Admin.Global'}</th><th>{l s='Lang' d='Modules.Ppmcp.Admin'}</th><th>{l s='Before' d='Modules.Ppmcp.Admin'}</th><th>{l s='After' d='Modules.Ppmcp.Admin'}</th></tr></thead>
      <tbody>
      {foreach $changes as $c}
        <tr>
          <td>{$c.entity|escape:'htmlall':'UTF-8'}</td>
          <td>{if isset($c.id)}{$c.id|escape:'htmlall':'UTF-8'}{/if}</td>
          <td><code>{$c.field|escape:'htmlall':'UTF-8'}</code></td>
          <td>{if isset($c.id_shop)}{$c.id_shop|intval}{/if}</td>
          <td>{if isset($c.id_lang)}{$c.id_lang|intval}{/if}</td>
          <td><small>{if isset($c.before)}{if is_array($c.before)}{$c.before|@json_encode|escape:'htmlall':'UTF-8'}{else}{$c.before|truncate:300:'…'|escape:'htmlall':'UTF-8'}{/if}{/if}</small></td>
          <td><small>{if isset($c.after)}{if is_array($c.after)}{$c.after|@json_encode|escape:'htmlall':'UTF-8'}{else}{$c.after|truncate:300:'…'|escape:'htmlall':'UTF-8'}{/if}{/if}</small></td>
        </tr>
      {/foreach}
      </tbody>
    </table>
  {/if}

  {if $arguments_json}<h4>{l s='Arguments' d='Modules.Ppmcp.Admin'}</h4><pre>{$arguments_json|escape:'htmlall':'UTF-8'}</pre>{/if}
  {if $result_json}<h4>{l s='Result returned to the assistant' d='Modules.Ppmcp.Admin'}</h4><pre style="max-height:500px;overflow:auto">{$result_json|escape:'htmlall':'UTF-8'}</pre>{/if}

  {if $related|count}
    <h4>{l s='Other entries of the same HTTP request' d='Modules.Ppmcp.Admin'}</h4>
    <ul>{foreach $related as $r}<li>#{$r.id_ppmcp_log|intval} {$r.rpc_method|escape:'htmlall':'UTF-8'} {$r.tool|escape:'htmlall':'UTF-8'} ({$r.status|escape:'htmlall':'UTF-8'})</li>{/foreach}</ul>
  {/if}
  <div class="panel-footer"><a class="btn btn-default" href="{$back_url|escape:'htmlall':'UTF-8'}"><i class="process-icon-back"></i> {l s='Back to list' d='Admin.Actions'}</a></div>
</div>
