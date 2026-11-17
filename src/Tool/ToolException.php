<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Tool;

/**
 * A tool-level failure the model should see and can act on (bad id, invalid
 * value, nothing found…). Becomes a tools/call result with isError = true.
 */
class ToolException extends \Exception
{
    /** @var string|null */
    private $hint;

    /** @var array */
    private $data;

    public function __construct(string $message, ?string $hint = null, array $data = [])
    {
        parent::__construct($message);
        $this->hint = $hint;
        $this->data = $data;
    }

    public function getHint(): ?string
    {
        return $this->hint;
    }

    public function getData(): array
    {
        return $this->data;
    }
}
