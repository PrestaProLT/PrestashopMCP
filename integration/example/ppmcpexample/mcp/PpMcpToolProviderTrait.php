<?php
/**
 * PrestaPro MCP — tool provider trait for OTHER modules.
 *
 * Add MCP tools (and skills) to any module:
 *
 *   require_once _PS_MODULE_DIR_ . 'yourmodule/mcp/PpMcpToolProviderTrait.php'; // bundled copy
 *   class YourModule extends Module
 *   {
 *       use PpMcpToolProviderTrait;
 *
 *       public function install() { return parent::install() && $this->ppMcpRegisterHook(); }
 *
 *       public function ppMcpTools()
 *       {
 *           return [[
 *               'name' => 'slide_list',            // exposed as yourmodule_slide_list
 *               'title' => 'List slides',
 *               'description' => 'Lists slides with device visibility (desktop/mobile)…',
 *               'params' => ['device' => ['type' => 'string', 'enum' => ['desktop', 'mobile', 'all']]],
 *               'shop' => true, 'lang' => true,
 *               'permission' => ['AdminModules', 'view'],
 *               'handler' => 'mcpSlideList',       // public method on this module
 *           ]];
 *       }
 *
 *       public function mcpSlideList(array $args, array $context) { return ['slides' => []]; }
 *   }
 *
 * The file is dependency-free (it never references a ppmcp class) so a module
 * can ship its own copy and keep working when ppmcp is not installed. The
 * trait_exists() guard lets several modules bundle the same file.
 * Full contract: modules/ppmcp/docs/INTEGRATION.md
 *
 * @author    PrestaPro
 * @license   AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

if (!trait_exists('PpMcpToolProviderTrait', false)) {
    trait PpMcpToolProviderTrait
    {
        /**
         * Tool definitions. Keys per tool:
         *  name (required)        local name "<resource>_<action>", prefixed with the module name automatically
         *  title, description     (description required: tell the model WHEN and HOW to use it)
         *  params                 ['arg' => ['type' => 'integer', 'description' => '…', 'required' => true]]
         *  write (bool)           true if it changes data. Needs an API key with the "write" scope.
         *  destructive (bool)     deletes/overwrites data that cannot be restored
         *  dry_run (bool)         the handler honours $context['dry_run'] (no save, return planned changes)
         *  permission             ['AdminTabClassName', 'view'|'add'|'edit'|'delete'] employee BO right
         *  shop / lang (bool)     inject id_shop / lang args; context is switched before the call
         *  keywords               extra words for mcp_tool_search
         *  handler                public method name: function (array $args, array $context)
         *
         * @return array
         */
        abstract public function ppMcpTools();

        /**
         * Optional skills (markdown playbooks) shipped by this module.
         *
         * @return array list of absolute paths to .md files (with front matter: name, title, description)
         */
        public function ppMcpSkills()
        {
            $dir = _PS_MODULE_DIR_ . $this->name . '/mcp/skills/';
            $files = is_dir($dir) ? glob($dir . '*.md') : [];

            return $files ?: [];
        }

        /**
         * Called by ppmcp to collect this module's tools. Do not override.
         *
         * @param array $params
         *
         * @return array
         */
        public function hookActionPpMcpRegisterTools($params)
        {
            return [
                'contract' => 1,
                'tools' => $this->ppMcpTools(),
                'skills' => $this->ppMcpSkills(),
            ];
        }

        /**
         * Call from install() (and from an upgrade script for existing installs).
         *
         * @return bool
         */
        public function ppMcpRegisterHook()
        {
            if (empty($this->id)) {
                $this->id = (int) Module::getModuleIdByName($this->name);
            }

            return (bool) $this->registerHook('actionPpMcpRegisterTools');
        }

        /**
         * Build a result that also reports what changed (shown to the model and
         * written to the MCP activity log).
         *
         * @param array $data
         * @param array $changes list built with ppMcpChange()
         *
         * @return array
         */
        protected function ppMcpResult(array $data, array $changes = [])
        {
            if ($changes) {
                $data['_changes'] = $changes;
            }

            return $data;
        }

        /**
         * @return array
         */
        protected function ppMcpChange($entity, $id, $field, $before, $after, $idShop = null, $idLang = null)
        {
            return [
                'entity' => (string) $entity,
                'id' => $id,
                'field' => (string) $field,
                'before' => $before,
                'after' => $after,
                'id_shop' => $idShop,
                'id_lang' => $idLang,
            ];
        }

        /**
         * Stop the tool with an error message the model can act on.
         *
         * @throws DomainException
         */
        protected function ppMcpFail($message, $hint = '')
        {
            throw new DomainException($hint ? $message . ' Hint: ' . $hint : $message);
        }
    }
}
