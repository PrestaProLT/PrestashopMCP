<?php
/**
 * One row per MCP request (tool call, list call, auth failure…): who, what,
 * where, the arguments, the outcome and — for write tools — every field that
 * changed with its old and new value.
 *
 * @author    PrestaPro
 * @license   AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class PpMcpLog extends ObjectModel
{
    const STATUS_OK = 'ok';
    const STATUS_ERROR = 'error';
    const STATUS_DENIED = 'denied';

    /** @var string */
    public $request_id;
    /** @var int */
    public $id_ppmcp_key = 0;
    /** @var int */
    public $id_employee = 0;
    /** @var int */
    public $id_shop = 0;
    /** @var int */
    public $id_lang = 0;
    /** @var string */
    public $client = '';
    /** @var string */
    public $protocol_version = '';
    /** @var string */
    public $rpc_method = '';
    /** @var string */
    public $tool = '';
    /** @var string */
    public $owner = '';
    /** @var bool */
    public $is_write = false;
    /** @var bool */
    public $dry_run = false;
    /** @var string */
    public $status = self::STATUS_OK;
    /** @var string */
    public $error = '';
    /** @var string JSON */
    public $arguments = '';
    /** @var string JSON */
    public $result = '';
    /** @var string JSON */
    public $changes = '';
    /** @var int */
    public $changes_count = 0;
    /** @var int */
    public $duration_ms = 0;
    /** @var string */
    public $ip = '';
    /** @var string */
    public $date_add;

    public static $definition = [
        'table' => 'ppmcp_log',
        'primary' => 'id_ppmcp_log',
        'fields' => [
            'request_id' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 32],
            'id_ppmcp_key' => ['type' => self::TYPE_INT, 'validate' => 'isInt'],
            'id_employee' => ['type' => self::TYPE_INT, 'validate' => 'isInt'],
            'id_shop' => ['type' => self::TYPE_INT, 'validate' => 'isInt'],
            'id_lang' => ['type' => self::TYPE_INT, 'validate' => 'isInt'],
            'client' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 128],
            'protocol_version' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 16],
            'rpc_method' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 64],
            'tool' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 128],
            'owner' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 64],
            'is_write' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'dry_run' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'status' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 16],
            'error' => ['type' => self::TYPE_STRING, 'validate' => 'isString'],
            'arguments' => ['type' => self::TYPE_HTML, 'validate' => 'isString'],
            'result' => ['type' => self::TYPE_HTML, 'validate' => 'isString'],
            'changes' => ['type' => self::TYPE_HTML, 'validate' => 'isString'],
            'changes_count' => ['type' => self::TYPE_INT, 'validate' => 'isInt'],
            'duration_ms' => ['type' => self::TYPE_INT, 'validate' => 'isInt'],
            'ip' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 45],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];
}
