<?php
/**
 * PrestaPro MCP — activity log: every MCP request with who / what / where,
 * outcome, duration, arguments and the before/after values of every change.
 * Read-only for everyone; only super administrators can delete entries.
 *
 * @author    PrestaPro
 * @license   AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'ppmcp/autoload.php';

class AdminPpMcpLogsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'ppmcp_log';
        $this->className = 'PpMcpLog';
        $this->identifier = 'id_ppmcp_log';
        $this->lang = false;
        $this->list_no_link = false;
        $this->_defaultOrderBy = 'id_ppmcp_log';
        $this->_defaultOrderWay = 'DESC';
        $this->allow_export = true;

        parent::__construct();

        $this->_select = 'k.`name` AS `key_name`, CONCAT(e.`firstname`, " ", e.`lastname`) AS `employee_name`';
        $this->_join = 'LEFT JOIN `' . _DB_PREFIX_ . 'ppmcp_key` k ON (k.`id_ppmcp_key` = a.`id_ppmcp_key`)
            LEFT JOIN `' . _DB_PREFIX_ . 'employee` e ON (e.`id_employee` = a.`id_employee`)';
        // Arguments/results can contain customer data: employees who are not
        // super admins only see (and export) entries made under their identity.
        if (!$this->isSuperAdmin()) {
            $this->_where = ' AND a.`id_employee` = ' . (int) $this->context->employee->id;
        }

        $statuses = ['ok' => 'ok', 'error' => 'error', 'denied' => 'denied'];
        $this->fields_list = [
            'id_ppmcp_log' => ['title' => $this->trans('ID', [], 'Admin.Global'), 'class' => 'fixed-width-xs', 'align' => 'center'],
            'date_add' => ['title' => $this->trans('Date', [], 'Admin.Global'), 'type' => 'datetime', 'filter_key' => 'a!date_add'],
            'tool' => ['title' => $this->trans('Tool', [], 'Modules.Ppmcp.Admin'), 'callback' => 'renderTool'],
            'rpc_method' => ['title' => $this->trans('Method', [], 'Modules.Ppmcp.Admin')],
            'status' => ['title' => $this->trans('Status', [], 'Admin.Global'), 'type' => 'select', 'list' => $statuses, 'filter_key' => 'a!status', 'callback' => 'renderStatus'],
            'is_write' => ['title' => $this->trans('Write', [], 'Modules.Ppmcp.Admin'), 'type' => 'bool', 'align' => 'center', 'class' => 'fixed-width-xs'],
            'dry_run' => ['title' => $this->trans('Dry run', [], 'Modules.Ppmcp.Admin'), 'type' => 'bool', 'align' => 'center', 'class' => 'fixed-width-xs'],
            'changes_count' => ['title' => $this->trans('Changes', [], 'Modules.Ppmcp.Admin'), 'align' => 'center', 'class' => 'fixed-width-xs'],
            'key_name' => ['title' => $this->trans('API key', [], 'Modules.Ppmcp.Admin'), 'filter_key' => 'k!name'],
            'employee_name' => ['title' => $this->trans('Employee', [], 'Admin.Global'), 'havingFilter' => true],
            'id_shop' => ['title' => $this->trans('Shop', [], 'Admin.Global'), 'align' => 'center', 'class' => 'fixed-width-xs', 'filter_key' => 'a!id_shop'],
            'client' => ['title' => $this->trans('Client', [], 'Modules.Ppmcp.Admin')],
            'duration_ms' => ['title' => 'ms', 'align' => 'right', 'class' => 'fixed-width-xs', 'search' => false],
            'request_id' => ['title' => $this->trans('Request', [], 'Modules.Ppmcp.Admin'), 'filter_key' => 'a!request_id'],
        ];
        $this->addRowAction('view');
        if ($this->isSuperAdmin()) {
            $this->addRowAction('delete');
            $this->bulk_actions = [
                'delete' => [
                    'text' => $this->trans('Delete selected', [], 'Admin.Notifications.Info'),
                    'confirm' => $this->trans('Delete selected items?', [], 'Admin.Notifications.Info'),
                    'icon' => 'icon-trash',
                ],
            ];
        }
    }

    private function isSuperAdmin(): bool
    {
        return (int) $this->context->employee->id_profile === (int) _PS_ADMIN_PROFILE_;
    }

    public function initContent()
    {
        $this->content = $this->module->renderNav('AdminPpMcpLogs') . $this->content;
        parent::initContent();
    }

    public function initToolbar()
    {
        parent::initToolbar();
        unset($this->toolbar_btn['new']);
    }

    public function initPageHeaderToolbar()
    {
        parent::initPageHeaderToolbar();
        unset($this->page_header_toolbar_btn['new_ppmcp_log']);
    }

    public function renderTool($value, $row)
    {
        $owner = isset($row['owner']) ? (string) $row['owner'] : '';

        return '<code>' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</code>';
    }

    public function renderStatus($value)
    {
        $class = $value === 'ok' ? 'success' : ($value === 'denied' ? 'warning' : 'danger');

        return '<span class="badge badge-' . $class . '">' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    public function processDelete()
    {
        if (!$this->isSuperAdmin()) {
            $this->errors[] = $this->trans('Only super administrators can delete MCP log entries.', [], 'Modules.Ppmcp.Admin');

            return false;
        }

        return parent::processDelete();
    }

    protected function processBulkDelete()
    {
        if (!$this->isSuperAdmin()) {
            $this->errors[] = $this->trans('Only super administrators can delete MCP log entries.', [], 'Modules.Ppmcp.Admin');

            return false;
        }

        return parent::processBulkDelete();
    }

    protected function loadObject($opt = false)
    {
        $obj = parent::loadObject($opt);
        if ($obj && !empty($obj->id) && !$this->isSuperAdmin() && (int) $obj->id_employee !== (int) $this->context->employee->id) {
            $this->errors[] = $this->trans('Log entry not found.', [], 'Modules.Ppmcp.Admin');

            return false;
        }

        return $obj;
    }

    public function renderView()
    {
        /** @var PpMcpLog $log */
        $log = $this->loadObject();
        if (!$log) {
            return '';
        }
        $pretty = function ($json) {
            if ($json === '' || $json === null) {
                return '';
            }
            $decoded = json_decode($json, true);

            return $decoded === null ? (string) $json : json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        };
        $key = new PpMcpApiKey((int) $log->id_ppmcp_key);
        $employee = new Employee((int) $log->id_employee);
        $changes = $log->changes ? json_decode($log->changes, true) : [];

        $related = Db::getInstance()->executeS(
            'SELECT `id_ppmcp_log`, `rpc_method`, `tool`, `status` FROM `' . _DB_PREFIX_ . 'ppmcp_log`
             WHERE `request_id` = \'' . pSQL($log->request_id) . '\' AND `id_ppmcp_log` != ' . (int) $log->id
        );

        $this->context->smarty->assign([
            'log' => $log,
            'key_name' => Validate::isLoadedObject($key) ? $key->name : '-',
            'employee_name' => Validate::isLoadedObject($employee) ? $employee->firstname . ' ' . $employee->lastname . ' <' . $employee->email . '>' : '-',
            'arguments_json' => $pretty($log->arguments),
            'result_json' => $pretty($log->result),
            'changes' => is_array($changes) ? $changes : [],
            'related' => $related ?: [],
            'back_url' => $this->context->link->getAdminLink('AdminPpMcpLogs'),
        ]);

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'ppmcp/views/templates/admin/log_view.tpl');
    }
}
