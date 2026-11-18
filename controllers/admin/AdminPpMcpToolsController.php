<?php
/**
 * PrestaPro MCP — catalogue of every MCP tool (core + module-provided),
 * grouped by owner, with per-tool enable/disable, provider diagnostics and
 * the list of skills.
 *
 * @author    PrestaPro
 * @license   AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'ppmcp/autoload.php';

use PrestaPro\PpMcp\Skill\SkillRepository;
use PrestaPro\PpMcp\Tool\ToolRegistry;

class AdminPpMcpToolsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function postProcess()
    {
        if (Tools::isSubmit('submitPpMcpTools')) {
            if (!$this->access('edit')) {
                $this->errors[] = $this->trans('You do not have permission to edit this.', [], 'Admin.Notifications.Error');

                return;
            }
            $registry = new ToolRegistry($this->module);
            $enabled = (array) Tools::getValue('tool_enabled', []);
            $disabled = [];
            foreach (array_keys($registry->all(true)) as $name) {
                if (!isset($enabled[$name])) {
                    $disabled[] = $name;
                }
            }
            Configuration::updateGlobalValue('PPMCP_DISABLED_TOOLS', json_encode(array_values($disabled)));
            $this->confirmations[] = $this->trans('Settings updated.', [], 'Admin.Notifications.Success');
        }
    }

    public function initContent()
    {
        $registry = new ToolRegistry($this->module);
        $disabled = ToolRegistry::disabledTools();
        $groups = [];
        foreach ($registry->all(true) as $tool) {
            if (!isset($groups[$tool->owner])) {
                $label = $tool->owner;
                if ($tool->isModuleTool) {
                    $m = Module::getInstanceByName($tool->owner);
                    $label = $m ? $m->displayName . ' (' . $tool->owner . ')' : $tool->owner;
                }
                $groups[$tool->owner] = ['owner' => $tool->owner, 'label' => $label, 'module' => $tool->isModuleTool, 'tools' => []];
            }
            $perms = [];
            foreach ($tool->permissions as $p) {
                $perms[] = $p[0] . ':' . (isset($p[1]) ? $p[1] : 'view');
            }
            $groups[$tool->owner]['tools'][] = [
                'name' => $tool->name,
                'title' => $tool->title,
                'description' => $tool->description,
                'write' => $tool->write,
                'destructive' => $tool->destructive,
                'scope' => $tool->scope,
                'permissions' => implode(' | ', $perms),
                'enabled' => !in_array($tool->name, $disabled, true),
            ];
        }
        uasort($groups, function ($a, $b) {
            return $a['module'] === $b['module'] ? strcmp($a['owner'], $b['owner']) : ($a['module'] ? 1 : -1);
        });

        $skills = new SkillRepository(_PS_MODULE_DIR_ . 'ppmcp/skills', $registry->getModuleSkills());

        $this->context->smarty->assign([
            'ppmcp_groups' => $groups,
            'ppmcp_provider_errors' => $registry->getProviderErrors(),
            'ppmcp_skills' => $skills->all(),
            'ppmcp_action' => $this->context->link->getAdminLink('AdminPpMcpTools'),
            'ppmcp_read_only' => (bool) Configuration::getGlobalValue('PPMCP_READ_ONLY'),
        ]);
        $this->content = $this->module->renderNav('AdminPpMcpTools') . $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'ppmcp/views/templates/admin/tools.tpl');
        parent::initContent();
    }
}
