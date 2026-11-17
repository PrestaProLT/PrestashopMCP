<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Mcp;

class Json
{
    public static function encode($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? '{}' : $json;
    }

    /**
     * Decodes as associative arrays. Empty JSON objects become [] which the
     * server re-encodes as {} where the protocol requires an object.
     */
    public static function decode(string $raw)
    {
        return json_decode($raw, true);
    }

    public static function isList(array $a): bool
    {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }
}
