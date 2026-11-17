<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Tool;

use PrestaPro\PpMcp\Auth\PermissionChecker;
use PrestaPro\PpMcp\Context\ExecutionContext;
use PrestaPro\PpMcp\Context\ShopContext;
use PrestaPro\PpMcp\Log\ActionLogger;

/**
 * Executes one tool call with every guard in a fixed order and writes one
 * activity-log row per call:
 *   1. key scope (read / write / code)
 *   2. global read-only switch for write tools
 *   3. employee back-office permission
 *   4. argument validation (JSON schema)
 *   5. shop restriction of the key + shop / language context switch
 *   6. handler, then result normalisation (changes, warnings)
 */
class ToolRunner
{
    /** @var \PpMcpApiKey */
    private $key;
    /** @var \Employee */
    private $employee;
    /** @var PermissionChecker */
    private $permissions;
    /** @var string */
    private $requestId;
    /** @var string */
    private $ip;
    /** @var string */
    private $client = '';

    public function __construct(\PpMcpApiKey $key, \Employee $employee, PermissionChecker $permissions, string $requestId, string $ip, string $userAgent)
    {
        $this->key = $key;
        $this->employee = $employee;
        $this->permissions = $permissions;
        $this->requestId = $requestId;
        $this->ip = $ip;
        $this->client = substr($userAgent, 0, 128);
    }

    public function setClientInfo(array $info): void
    {
        $name = isset($info['name']) ? (string) $info['name'] : '';
        if ($name !== '') {
            $this->client = substr($name . (isset($info['version']) ? ' ' . $info['version'] : ''), 0, 128);
        }
    }

    public static function isReadOnly(): bool
    {
        return (bool) \Configuration::getGlobalValue('PPMCP_READ_ONLY');
    }

    /**
     * Tools this key/employee may call. tools/list only shows these, so the
     * model never sees tools that would be denied.
     *
     * @param ToolDefinition[] $tools
     *
     * @return ToolDefinition[]
     */
    public function visibleTools(array $tools): array
    {
        $out = [];
        foreach ($tools as $name => $tool) {
            if ($this->denialReason($tool) === null) {
                $out[$name] = $tool;
            }
        }

        return $out;
    }

    public function denialReason(ToolDefinition $tool): ?string
    {
        if (!$this->key->hasScope($tool->scope) || ($tool->write && !$this->key->hasScope(ToolDefinition::SCOPE_WRITE))) {
            return sprintf('The API key "%s" does not have the "%s" scope required by %s.', $this->key->name, $tool->write ? 'write' : $tool->scope, $tool->name);
        }
        if ($tool->write && self::isReadOnly()) {
            return 'The MCP server is in read-only mode (module configuration). Write tools are disabled.';
        }
        if (!$this->permissions->allows($this->employee, $tool->permissions)) {
            return sprintf(
                'Employee #%d (%s) lacks the back-office permission %s required by %s.',
                (int) $this->employee->id,
                $this->employee->email,
                PermissionChecker::describe($tool->permissions),
                $tool->name
            );
        }

        return null;
    }

    /**
     * @return array [payload => mixed, isError => bool]
     */
    public function call(ToolDefinition $tool, array $args, string $protocolVersion): array
    {
        $start = microtime(true);
        $ctx = new ExecutionContext($this->requestId, $this->key, $this->employee);
        $ctx->protocolVersion = $protocolVersion;
        $ctx->clientName = $this->client;
        $ctx->idShop = (int) \Context::getContext()->shop->id;
        $ctx->idLang = (int) \Context::getContext()->language->id;
        $status = \PpMcpLog::STATUS_OK;
        $error = '';
        $payload = null;
        $isError = false;
        $shopContext = null;

        try {
            $reason = $this->denialReason($tool);
            if ($reason !== null) {
                throw new PermissionDeniedException($reason, 'Ask the shop administrator to grant this right, or use a read-only tool.');
            }

            $args = (new SchemaValidator())->validate($tool->inputSchema, $args);
            $ctx->dryRun = $tool->supportsDryRun && !empty($args['dry_run']);

            $shopContext = new ShopContext();
            if ($tool->usesShop || isset($args['id_shop'])) {
                $idShop = isset($args['id_shop']) ? (int) $args['id_shop'] : $this->defaultShopId();
                if (!$this->key->allowsShop($idShop)) {
                    throw new PermissionDeniedException(
                        sprintf('API key "%s" is not allowed to access shop #%d.', $this->key->name, $idShop),
                        'Allowed shop ids: ' . implode(', ', $this->key->getShopIds())
                    );
                }
                $ctx->idShop = $shopContext->switchShop($idShop);
            }
            if ($tool->usesLang || isset($args['lang'])) {
                $ctx->idLang = $shopContext->switchLanguage(isset($args['lang']) ? $args['lang'] : null, $ctx->idShop);
            }
            unset($args['dry_run']);
            if ($tool->isModuleTool) {
                // Module handlers read shop/lang from $context; keep args clean.
                unset($args['id_shop'], $args['lang']);
            }

            $data = call_user_func($tool->handler, $args, $ctx);
            $payload = $this->normalise($tool, $data, $ctx);
        } catch (PermissionDeniedException $e) {
            $status = \PpMcpLog::STATUS_DENIED;
            $error = $e->getMessage();
            $payload = self::errorPayload($e->getMessage(), $e->getHint(), $e->getData());
            $isError = true;
        } catch (ToolException $e) {
            $status = \PpMcpLog::STATUS_ERROR;
            $error = $e->getMessage();
            $payload = self::errorPayload($e->getMessage(), $e->getHint(), $e->getData());
            $isError = true;
        } catch (\Throwable $e) {
            $status = \PpMcpLog::STATUS_ERROR;
            $error = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
            $payload = self::errorPayload(
                ($tool->isModuleTool ? 'Module ' . $tool->owner . ' failed: ' : 'Tool failed: ') . $e->getMessage(),
                'This is an unexpected error; it is recorded in the MCP activity log (request ' . $this->requestId . ').'
            );
            $isError = true;
        } finally {
            if ($shopContext) {
                $shopContext->restore();
            }
        }

        ActionLogger::log([
            'request_id' => $this->requestId,
            'id_ppmcp_key' => (int) $this->key->id,
            'id_employee' => (int) $this->employee->id,
            'id_shop' => $ctx->idShop,
            'id_lang' => $ctx->idLang,
            'client' => $this->client,
            'protocol_version' => $protocolVersion,
            'rpc_method' => 'tools/call',
            'tool' => $tool->name,
            'owner' => $tool->owner,
            'is_write' => $tool->write,
            'dry_run' => $ctx->dryRun,
            'status' => $status,
            'error' => $error,
            'arguments' => $args,
            'result' => $payload,
            'changes' => $ctx->getChanges() ?: null,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'ip' => $this->ip,
        ]);

        return ['payload' => $payload, 'isError' => $isError];
    }

    public function logListCall(string $method, string $protocolVersion, int $count): void
    {
        ActionLogger::log([
            'request_id' => $this->requestId,
            'id_ppmcp_key' => (int) $this->key->id,
            'id_employee' => (int) $this->employee->id,
            'id_shop' => (int) \Context::getContext()->shop->id,
            'client' => $this->client,
            'protocol_version' => $protocolVersion,
            'rpc_method' => $method,
            'status' => \PpMcpLog::STATUS_OK,
            'result' => ['count' => $count],
            'ip' => $this->ip,
        ]);
    }

    public function logUnknownTool(string $name, array $args, string $protocolVersion): void
    {
        ActionLogger::log([
            'request_id' => $this->requestId,
            'id_ppmcp_key' => (int) $this->key->id,
            'id_employee' => (int) $this->employee->id,
            'id_shop' => (int) \Context::getContext()->shop->id,
            'client' => $this->client,
            'protocol_version' => $protocolVersion,
            'rpc_method' => 'tools/call',
            'tool' => $name,
            'status' => \PpMcpLog::STATUS_ERROR,
            'error' => 'Unknown tool',
            'arguments' => $args,
            'ip' => $this->ip,
        ]);
    }

    private function defaultShopId(): int
    {
        $current = (int) \Context::getContext()->shop->id;
        if ($this->key->allowsShop($current)) {
            return $current;
        }
        $ids = $this->key->getShopIds();

        return $ids ? (int) $ids[0] : $current;
    }

    private function normalise(ToolDefinition $tool, $data, ExecutionContext $ctx)
    {
        if (is_array($data)) {
            if (isset($data['_changes']) && is_array($data['_changes'])) {
                foreach ($data['_changes'] as $c) {
                    if (is_array($c) && isset($c['entity'], $c['field'])) {
                        $ctx->recordChange(
                            (string) $c['entity'],
                            isset($c['id']) ? $c['id'] : null,
                            (string) $c['field'],
                            isset($c['before']) ? $c['before'] : null,
                            isset($c['after']) ? $c['after'] : null,
                            isset($c['id_shop']) ? (int) $c['id_shop'] : null,
                            isset($c['id_lang']) ? (int) $c['id_lang'] : null
                        );
                    }
                }
                unset($data['_changes']);
            }
            if (isset($data['_warnings']) && is_array($data['_warnings'])) {
                foreach ($data['_warnings'] as $w) {
                    $ctx->addWarning((string) $w);
                }
                unset($data['_warnings']);
            }
        } elseif (!is_scalar($data) && $data !== null) {
            $data = json_decode(json_encode($data), true);
        } else {
            $data = ['result' => $data];
        }

        if ($tool->write) {
            $out = [
                'dry_run' => $ctx->dryRun,
                'saved' => !$ctx->dryRun,
                'changes' => $ctx->getChanges(),
                'result' => $data,
            ];
            if (!$ctx->getChanges()) {
                $out['note'] = $ctx->dryRun ? 'Nothing would change.' : 'Nothing changed (values already set).';
            }
        } else {
            $out = $data;
        }
        if ($ctx->getWarnings()) {
            $out['warnings'] = $ctx->getWarnings();
        }
        $out['request_id'] = $ctx->requestId;

        return $out;
    }

    private static function errorPayload(string $message, ?string $hint, array $data = []): array
    {
        $p = ['error' => $message];
        if ($hint) {
            $p['hint'] = $hint;
        }
        if ($data) {
            $p['details'] = $data;
        }

        return $p;
    }
}
