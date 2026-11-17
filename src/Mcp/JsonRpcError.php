<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Mcp;

/**
 * Protocol-level error (JSON-RPC "error" member), as opposed to a tool
 * execution error which is a normal result with isError = true.
 */
class JsonRpcError extends \Exception
{
    const PARSE_ERROR = -32700;
    const INVALID_REQUEST = -32600;
    const METHOD_NOT_FOUND = -32601;
    const INVALID_PARAMS = -32602;
    const INTERNAL_ERROR = -32603;
    const RESOURCE_NOT_FOUND_LEGACY = -32002;
    const HEADER_MISMATCH = -32020;
    const UNSUPPORTED_PROTOCOL_VERSION = -32022;

    /** @var mixed */
    private $data;
    /** @var int */
    private $httpStatus;

    public function __construct(int $code, string $message, $data = null, int $httpStatus = 200)
    {
        parent::__construct($message, $code);
        $this->data = $data;
        $this->httpStatus = $httpStatus;
    }

    public function getData()
    {
        return $this->data;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function toArray(): array
    {
        $e = ['code' => $this->getCode(), 'message' => $this->getMessage()];
        if ($this->data !== null) {
            $e['data'] = $this->data;
        }

        return $e;
    }
}
