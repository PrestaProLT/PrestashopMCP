<?php
/**
 * PrestaPro MCP — API keys (create / edit / revoke).
 *
 * The plain token is generated server-side and displayed exactly once after
 * creation. Only super administrators may create keys for other employees,
 * so nobody can mint a key with more rights than they have.
 *
 * @author    PrestaPro
 * @license   AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'ppmcp/autoload.php';

class AdminPpMcpKeysController extends ModuleAdminController
{
    const SCOPES = ['read', 'write', 'code'];

    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'ppmcp_key';
        $this->className = 'PpMcpApiKey';
        $this->identifier = 'id_ppmcp_key';
        $this->lang = false;
        $this->_defaultOrderBy = 'id_ppmcp_key';
        $this->_defaultOrderWay = 'DESC';

        parent::__construct();

        $this->_select = 'CONCAT(e.`firstname`, " ", e.`lastname`, " <", e.`email`, ">") AS `employee_name`';
        $this->_join = 'LEFT JOIN `' . _DB_PREFIX_ . 'employee` e ON (e.`id_employee` = a.`id_employee`)';
        if (!$this->isSuperAdmin()) {
            $this->_where = ' AND a.`id_employee` = ' . (int) $this->context->employee->id;
        }

        $this->fields_list = [
            'id_ppmcp_key' => ['title' => $this->trans('ID', [], 'Admin.Global'), 'class' => 'fixed-width-xs', 'align' => 'center'],
            'name' => ['title' => $this->trans('Name', [], 'Admin.Global')],
            'token_hint' => ['title' => $this->trans('Token starts with', [], 'Modules.Ppmcp.Admin'), 'search' => false, 'callback' => 'renderHint'],
            'employee_name' => ['title' => $this->trans('Acts as employee', [], 'Modules.Ppmcp.Admin'), 'havingFilter' => true],
            'scopes' => ['title' => $this->trans('Scopes', [], 'Modules.Ppmcp.Admin')],
            'shops' => ['title' => $this->trans('Shops', [], 'Modules.Ppmcp.Admin'), 'callback' => 'renderShops', 'search' => false],
            'last_used' => ['title' => $this->trans('Last used', [], 'Modules.Ppmcp.Admin'), 'type' => 'datetime'],
            'last_ip' => ['title' => $this->trans('Last IP', [], 'Modules.Ppmcp.Admin')],
            'date_expire' => ['title' => $this->trans('Expires', [], 'Modules.Ppmcp.Admin'), 'type' => 'datetime'],
            'active' => ['title' => $this->trans('Enabled', [], 'Admin.Global'), 'active' => 'status', 'type' => 'bool', 'align' => 'center', 'class' => 'fixed-width-sm'],
        ];
        $this->addRowAction('edit');
        $this->addRowAction('delete');
        $this->bulk_actions = [
            'delete' => [
                'text' => $this->trans('Delete selected', [], 'Admin.Notifications.Info'),
                'confirm' => $this->trans('Delete selected items?', [], 'Admin.Notifications.Info'),
                'icon' => 'icon-trash',
            ],
        ];
    }

    private function isSuperAdmin(): bool
    {
        return (int) $this->context->employee->id_profile === (int) _PS_ADMIN_PROFILE_;
    }

    public function renderHint($value)
    {
        return '<code>' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '…</code>';
    }

    public function renderShops($value)
    {
        return $value === '' || $value === null ? $this->trans('All', [], 'Admin.Global') : htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public function initContent()
    {
        $newToken = isset($this->context->cookie->ppmcp_new_token) ? (string) $this->context->cookie->ppmcp_new_token : '';
        if ($newToken !== '') {
            unset($this->context->cookie->ppmcp_new_token);
            $this->context->cookie->write();
            $this->context->smarty->assign([
                'ppmcp_new_token' => $newToken,
                'ppmcp_endpoint' => $this->module->getEndpointUrl(),
            ]);
            $this->content .= $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'ppmcp/views/templates/admin/new_token.tpl');
        }
        parent::initContent();
    }

    public function renderForm()
    {
        /** @var PpMcpApiKey|null $obj */
        $obj = $this->loadObject(true);

        $employees = [];
        foreach (Employee::getEmployees(true) as $e) {
            if ($this->isSuperAdmin() || (int) $e['id_employee'] === (int) $this->context->employee->id) {
                $employees[] = ['id' => (int) $e['id_employee'], 'name' => $e['firstname'] . ' ' . $e['lastname']];
            }
        }
        $scopeValues = [
            ['id' => 'read', 'name' => $this->trans('read — read catalog, orders, customers, diagnostics', [], 'Modules.Ppmcp.Admin')],
            ['id' => 'write', 'name' => $this->trans('write — change data (products, stock, order status, cart rules…)', [], 'Modules.Ppmcp.Admin')],
            ['id' => 'code', 'name' => $this->trans('code — read module source files (module_explain, module_file_read…)', [], 'Modules.Ppmcp.Admin')],
        ];

        $inputs = [
            ['type' => 'text', 'label' => $this->trans('Name', [], 'Admin.Global'), 'name' => 'name', 'required' => true, 'maxlength' => 64,
                'desc' => $this->trans('Who/what uses this key, e.g. "Jonas – Claude Code laptop".', [], 'Modules.Ppmcp.Admin')],
            ['type' => 'select', 'label' => $this->trans('Acts as employee', [], 'Modules.Ppmcp.Admin'), 'name' => 'id_employee', 'required' => true,
                'options' => ['query' => $employees, 'id' => 'id', 'name' => 'name'],
                'desc' => $this->trans('Every MCP call runs with this employee\'s back-office permissions (profile). Choose a limited profile for limited assistants.', [], 'Modules.Ppmcp.Admin')],
            ['type' => 'checkbox', 'label' => $this->trans('Scopes', [], 'Modules.Ppmcp.Admin'), 'name' => 'scope',
                'values' => ['query' => $scopeValues, 'id' => 'id', 'name' => 'name']],
        ];
        if (Shop::isFeatureActive()) {
            $shops = [];
            foreach (Shop::getShops(false) as $s) {
                $shops[] = ['id' => (int) $s['id_shop'], 'name' => '#' . $s['id_shop'] . ' ' . $s['name']];
            }
            $inputs[] = ['type' => 'checkbox', 'label' => $this->trans('Restrict to shops', [], 'Modules.Ppmcp.Admin'), 'name' => 'shop',
                'values' => ['query' => $shops, 'id' => 'id', 'name' => 'name'],
                'desc' => $this->trans('Leave all unchecked to allow every shop.', [], 'Modules.Ppmcp.Admin')];
        }
        $inputs[] = ['type' => 'datetime', 'label' => $this->trans('Expires on', [], 'Modules.Ppmcp.Admin'), 'name' => 'date_expire',
            'desc' => $this->trans('Optional. Empty = never expires.', [], 'Modules.Ppmcp.Admin')];
        $inputs[] = ['type' => 'switch', 'label' => $this->trans('Enabled', [], 'Admin.Global'), 'name' => 'active', 'is_bool' => true,
            'values' => [
                ['id' => 'active_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')],
                ['id' => 'active_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')],
            ]];

        $this->fields_form = [
            'legend' => ['title' => $this->trans('MCP API key', [], 'Modules.Ppmcp.Admin'), 'icon' => 'icon-key'],
            'description' => $obj && $obj->id
                ? $this->trans('The token itself cannot be shown again. To get a new token, delete this key and create a new one.', [], 'Modules.Ppmcp.Admin')
                : $this->trans('The token is generated when you save and shown only once.', [], 'Modules.Ppmcp.Admin'),
            'input' => $inputs,
            'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions')],
        ];

        $scopes = $obj && $obj->id ? $obj->getScopes() : ['read'];
        foreach (self::SCOPES as $s) {
            $this->fields_value['scope_' . $s] = in_array($s, $scopes, true);
        }
        $shopIds = $obj && $obj->id ? $obj->getShopIds() : [];
        foreach (Shop::getShops(false) as $s) {
            $this->fields_value['shop_' . (int) $s['id_shop']] = in_array((int) $s['id_shop'], $shopIds, true);
        }
        if (!$obj || !$obj->id) {
            $this->fields_value['active'] = 1;
            $this->fields_value['id_employee'] = (int) $this->context->employee->id;
        }

        return parent::renderForm();
    }

    public function processSave()
    {
        $idEmployee = (int) Tools::getValue('id_employee');
        if (!$this->isSuperAdmin() && $idEmployee !== (int) $this->context->employee->id) {
            $this->errors[] = $this->trans('You can only create keys that act as yourself.', [], 'Modules.Ppmcp.Admin');

            return false;
        }

        $scopes = [];
        foreach (self::SCOPES as $s) {
            if (Tools::getValue('scope_' . $s)) {
                $scopes[] = $s;
            }
        }
        if (!$scopes) {
            $this->errors[] = $this->trans('Select at least one scope.', [], 'Modules.Ppmcp.Admin');

            return false;
        }
        $_POST['scopes'] = implode(',', $scopes);

        $shops = [];
        foreach (Shop::getShops(false) as $s) {
            if (Tools::getValue('shop_' . (int) $s['id_shop'])) {
                $shops[] = (int) $s['id_shop'];
            }
        }
        $_POST['shops'] = implode(',', $shops);

        $id = (int) Tools::getValue($this->identifier);
        $newToken = '';
        if ($id) {
            $existing = new PpMcpApiKey($id);
            if (!Validate::isLoadedObject($existing) || (!$this->isSuperAdmin() && (int) $existing->id_employee !== (int) $this->context->employee->id)) {
                $this->errors[] = $this->trans('Key not found.', [], 'Modules.Ppmcp.Admin');

                return false;
            }
            $_POST['token_hash'] = $existing->token_hash;
            $_POST['token_hint'] = $existing->token_hint;
        } else {
            $newToken = PpMcpApiKey::generateToken();
            $_POST['token_hash'] = PpMcpApiKey::hashToken($newToken);
            $_POST['token_hint'] = substr($newToken, 0, 12);
        }

        $result = parent::processSave();
        if ($newToken !== '' && empty($this->errors)) {
            $this->context->cookie->ppmcp_new_token = $newToken;
            $this->context->cookie->write();
        }

        return $result;
    }

    /**
     * Single choke point for edit / status toggle / delete: a non-super-admin
     * can only ever load keys that act as themselves.
     */
    protected function loadObject($opt = false)
    {
        $obj = parent::loadObject($opt);
        if ($obj && !empty($obj->id) && !$this->isSuperAdmin() && (int) $obj->id_employee !== (int) $this->context->employee->id) {
            $this->errors[] = $this->trans('Key not found.', [], 'Modules.Ppmcp.Admin');

            return false;
        }

        return $obj;
    }

    protected function processBulkDelete()
    {
        if (!$this->isSuperAdmin() && is_array($this->boxes)) {
            $own = [];
            foreach ($this->boxes as $id) {
                $key = new PpMcpApiKey((int) $id);
                if (Validate::isLoadedObject($key) && (int) $key->id_employee === (int) $this->context->employee->id) {
                    $own[] = (int) $id;
                }
            }
            $this->boxes = $own;
        }

        return parent::processBulkDelete();
    }
}
