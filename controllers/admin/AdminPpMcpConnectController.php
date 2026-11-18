<?php
/**
 * PrestaPro MCP — approve an OAuth connection (Claude, ChatGPT…).
 *
 * The AI app's connecting page shows a one-time code. The employee enters it
 * here, checks who is asking, chooses the employee the assistant acts as, its
 * scopes, shops and lifetime, and approves or declines. Approving creates an
 * ordinary API key (listed under API keys, revocable there).
 *
 * @author    PrestaPro
 * @license   AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'ppmcp/autoload.php';

use PrestaPro\PpMcp\OAuth\OAuthServer;

class AdminPpMcpConnectController extends ModuleAdminController
{
    const LIFETIMES = [30, 90, 365, 0];

    /** @var array|null request being reviewed */
    private $request;
    /** @var string|null */
    private $result;

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    private function isSuperAdmin(): bool
    {
        return (int) $this->context->employee->id_profile === (int) _PS_ADMIN_PROFILE_;
    }

    private function t(string $s, array $p = []): string
    {
        return $this->trans($s, $p, 'Modules.Ppmcp.Admin');
    }

    public function postProcess()
    {
        $server = new OAuthServer($this->module);
        if (Tools::isSubmit('submitPpMcpLookup')) {
            $this->request = $server->findPendingByUserCode((string) Tools::getValue('user_code'));
            if (!$this->request) {
                $this->errors[] = $this->t('No pending connection with this code. Check the code on the connecting page; it is valid for 15 minutes.');
            }

            return;
        }
        if (!Tools::isSubmit('submitPpMcpApprove') && !Tools::isSubmit('submitPpMcpDeny')) {
            return;
        }
        if (!$this->access('add')) {
            $this->errors[] = $this->trans('You do not have permission to add this.', [], 'Admin.Notifications.Error');

            return;
        }
        $request = $server->findPendingById((int) Tools::getValue('id_request'));
        if (!$request || OAuthServer::normaliseUserCode((string) Tools::getValue('user_code')) !== $request['user_code']) {
            $this->errors[] = $this->t('This connection request has expired or was already handled. Start connecting again from the AI app.');

            return;
        }
        if (Tools::isSubmit('submitPpMcpApprove') && $this->otherNetwork($request) && !Tools::getValue('confirm_other_network')) {
            $this->errors[] = $this->t('This request was started from another network than yours. Confirm that you started it yourself on another device, or decline it.');
            $this->request = $request;

            return;
        }
        if (Tools::isSubmit('submitPpMcpDeny')) {
            $server->deny($request, (int) $this->context->employee->id);
            $this->result = 'denied';

            return;
        }

        $idEmployee = (int) Tools::getValue('id_employee');
        if (!$this->isSuperAdmin() && $idEmployee !== (int) $this->context->employee->id) {
            $this->errors[] = $this->t('You can only connect assistants that act as yourself.');
            $this->request = $request;

            return;
        }
        $employee = new Employee($idEmployee);
        if (!Validate::isLoadedObject($employee) || !$employee->active) {
            $this->errors[] = $this->t('Choose an active employee.');
            $this->request = $request;

            return;
        }
        $scopes = array_values(array_intersect(OAuthServer::SCOPES, array_keys((array) Tools::getValue('scope', []))));
        if (!in_array('read', $scopes, true)) {
            array_unshift($scopes, 'read');
        }
        $shops = [];
        if (Shop::isFeatureActive()) {
            foreach (array_keys((array) Tools::getValue('shop', [])) as $id) {
                if (Shop::getShop((int) $id)) {
                    $shops[] = (int) $id;
                }
            }
        }
        $days = (int) Tools::getValue('lifetime');
        $days = in_array($days, self::LIFETIMES, true) ? $days : 90;
        $expire = $days ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
        $name = trim((string) Tools::getValue('key_name'));
        if ($name === '' || !Validate::isGenericName($name)) {
            $name = $this->defaultName($request);
        }
        try {
            $server->approve($request, $idEmployee, (int) $this->context->employee->id, $scopes, $shops, $expire, $name);
            $this->result = 'approved';
        } catch (\Throwable $e) {
            $this->errors[] = $e->getMessage();
        }
    }

    /**
     * Consent-phishing guard: a request started from another IP than the
     * approving employee's is suspicious (someone may have sent them the code).
     */
    private function otherNetwork(array $request): bool
    {
        return (string) $request['ip'] !== (string) Tools::getRemoteAddr();
    }

    private function defaultName(array $request): string
    {
        $client = trim((string) $request['client_name']);
        $host = (string) parse_url($request['redirect_uri'], PHP_URL_HOST);
        $name = ($client !== '' ? $client : $host) . ' (' . $host . ') ' . date('Y-m-d');

        return mb_substr(preg_replace('/[<>={}]/', '', $name), 0, 64);
    }

    public function initContent()
    {
        $employees = [];
        foreach (Employee::getEmployees(true) as $e) {
            if ($this->isSuperAdmin() || (int) $e['id_employee'] === (int) $this->context->employee->id) {
                $employees[] = ['id' => (int) $e['id_employee'], 'name' => $e['firstname'] . ' ' . $e['lastname']];
            }
        }
        $shops = [];
        if (Shop::isFeatureActive()) {
            foreach (Shop::getShops(false) as $s) {
                $shops[] = ['id' => (int) $s['id_shop'], 'name' => '#' . $s['id_shop'] . ' ' . $s['name']];
            }
        }
        $req = null;
        if ($this->request) {
            $r = $this->request;
            $requested = $r['scope'] !== '' ? explode(' ', $r['scope']) : [];
            $req = [
                'id' => (int) $r['id_ppmcp_oauth_request'],
                'user_code' => $r['user_code'],
                'client_name' => (string) $r['client_name'],
                'redirect_host' => (string) parse_url($r['redirect_uri'], PHP_URL_HOST),
                'ip' => $r['ip'],
                'my_ip' => Tools::getRemoteAddr(),
                'other_network' => $this->otherNetwork($r),
                'client_id' => $r['client_id'],
                'client_ip' => isset($r['client_ip']) ? $r['client_ip'] : '',
                'client_date_add' => isset($r['client_date_add']) ? $r['client_date_add'] : '',
                'user_agent' => $r['user_agent'],
                'date_add' => $r['date_add'],
                'id_shop' => (int) $r['id_shop'],
                'requested' => $requested,
                'default_name' => $this->defaultName($r),
            ];
        }
        $this->context->smarty->assign([
            'ppmcp_action' => $this->context->link->getAdminLink('AdminPpMcpConnect'),
            'ppmcp_keys_url' => $this->context->link->getAdminLink('AdminPpMcpKeys'),
            'ppmcp_request' => $req,
            'ppmcp_result' => $this->result,
            'ppmcp_employees' => $employees,
            'ppmcp_me' => (int) $this->context->employee->id,
            'ppmcp_shops' => $shops,
            'ppmcp_oauth_enabled' => OAuthServer::enabled(),
            'ppmcp_oauth_on' => (bool) Configuration::getGlobalValue('PPMCP_OAUTH_ENABLED'),
            'ppmcp_endpoint' => $this->module->getEndpointUrl(),
        ]);
        $this->content = $this->module->renderNav('AdminPpMcpConnect') . $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'ppmcp/views/templates/admin/connect.tpl');
        parent::initContent();
    }
}
