<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Mcp;

class HttpResponse
{
    /** @var int */
    public $status;
    /** @var array */
    public $headers;
    /** @var string */
    public $body;

    public function __construct(int $status, string $body = '', array $headers = [])
    {
        $this->status = $status;
        $this->body = $body;
        $this->headers = $headers;
    }

    public static function json(int $status, $payload, array $headers = []): self
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';

        return new self($status, Json::encode($payload), $headers);
    }

    public function send(): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code($this->status);
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex, nofollow');
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->body;
    }
}
