{**
 * PrestaPro MCP — navigation between the module's pages (the module has no
 * sidebar entry; it is opened from the Module Manager "Configure" button).
 * @author    PrestaPro
 * @license   AFL-3.0
 *}
<div class="ppmcp-nav" style="margin-bottom:15px">
  <ul class="nav nav-tabs">
    {foreach $ppmcp_nav as $item}
      <li{if $item.active} class="active"{/if}><a href="{$item.url|escape:'htmlall':'UTF-8'}"><i class="{$item.icon|escape:'htmlall':'UTF-8'}"></i> {$item.label|escape:'htmlall':'UTF-8'}</a></li>
    {/foreach}
  </ul>
</div>
