<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Context;

/**
 * Everything a tool needs to know about the call it is serving: who is
 * calling (API key + employee), in which shop and language, whether it is a
 * dry run, and a place to record the changes it makes (for the audit log).
 */
class ExecutionContext
{
    /** @var string */
    public $requestId;
    /** @var \PpMcpApiKey */
    public $apiKey;
    /** @var \Employee */
    public $employee;
    /** @var int */
    public $idShop;
    /** @var int */
    public $idLang;
    /** @var bool */
    public $dryRun = false;
    /** @var string */
    public $protocolVersion = '';
    /** @var string */
    public $clientName = '';
    /** @var array */
    private $changes = [];
    /** @var string[] */
    private $warnings = [];

    public function __construct(string $requestId, \PpMcpApiKey $apiKey, \Employee $employee)
    {
        $this->requestId = $requestId;
        $this->apiKey = $apiKey;
        $this->employee = $employee;
    }

    /**
     * Record one field change. Logged with the call so every write is traceable.
     *
     * @param mixed $before
     * @param mixed $after
     */
    public function recordChange(string $entity, $id, string $field, $before, $after, ?int $idShop = null, ?int $idLang = null): void
    {
        if ($before === $after) {
            return;
        }
        $change = ['entity' => $entity, 'id' => $id, 'field' => $field, 'before' => $before, 'after' => $after];
        if ($idShop) {
            $change['id_shop'] = $idShop;
        }
        if ($idLang) {
            $change['id_lang'] = $idLang;
        }
        $this->changes[] = $change;
    }

    /**
     * Create tools record changes before saving (so dry runs show them) with
     * the placeholder id "new"; call this after the save to log the real id.
     */
    public function resolveCreatedId(string $entity, $id): void
    {
        foreach ($this->changes as $i => $c) {
            if ($c['entity'] === $entity && $c['id'] === 'new') {
                $this->changes[$i]['id'] = $id;
            }
        }
    }

    public function getChanges(): array
    {
        return $this->changes;
    }

    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Plain-array view handed to third-party module tool handlers, so they do
     * not need to type-hint any ppmcp class.
     */
    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'id_shop' => $this->idShop,
            'id_lang' => $this->idLang,
            'id_employee' => (int) $this->employee->id,
            'id_api_key' => (int) $this->apiKey->id,
            'dry_run' => $this->dryRun,
            'client' => $this->clientName,
        ];
    }
}
