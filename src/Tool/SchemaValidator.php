<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Tool;

/**
 * Validates and normalises tool arguments against the subset of JSON Schema
 * the tools use: type (incl. type lists), enum, minimum/maximum, minLength/
 * maxLength, pattern, items, properties, required, default, maxItems.
 *
 * Lenient by design: models often send "5" for integers or "true" for
 * booleans, so scalar strings are coerced when unambiguous. Errors are
 * collected and reported together so the model can fix all of them at once.
 */
class SchemaValidator
{
    /** @var string[] */
    private $errors = [];

    /**
     * @return array normalised arguments
     *
     * @throws ToolException
     */
    public function validate(array $schema, array $args): array
    {
        $this->errors = [];
        $result = $this->validateObject($schema, $args, '');
        if ($this->errors) {
            throw new ToolException(
                'Invalid arguments: ' . implode('; ', $this->errors),
                'Fix the listed arguments and call the tool again. The tool inputSchema lists every accepted argument.'
            );
        }

        return $result;
    }

    private function validateObject(array $schema, $value, string $path)
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (!is_array($value) || ($value && array_keys($value) === range(0, count($value) - 1))) {
            if (is_array($value) && !$value) {
                $value = [];
            } else {
                $this->errors[] = ($path ?: 'arguments') . ' must be an object';

                return $value;
            }
        }
        $props = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];
        $required = isset($schema['required']) ? (array) $schema['required'] : [];
        $out = [];

        foreach ($required as $name) {
            if (!array_key_exists($name, $value) || $value[$name] === null || $value[$name] === '') {
                $this->errors[] = $this->join($path, $name) . ' is required';
            }
        }
        foreach ($props as $name => $spec) {
            if (array_key_exists($name, $value) && $value[$name] !== null) {
                $out[$name] = $this->validateValue((array) $spec, $value[$name], $this->join($path, $name));
            } elseif (is_array($spec) && array_key_exists('default', $spec)) {
                $out[$name] = $spec['default'];
            }
        }
        // Unknown keys are reported so typos ("id_prodcut") are not silently ignored.
        if ($props) {
            foreach (array_keys($value) as $name) {
                if (!isset($props[$name]) && !(isset($schema['additionalProperties']) && $schema['additionalProperties'] !== false)) {
                    $this->errors[] = sprintf('%s is not an accepted argument (accepted: %s)', $this->join($path, (string) $name), implode(', ', array_keys($props)));
                } elseif (!isset($props[$name])) {
                    $out[$name] = $value[$name];
                }
            }
        } elseif (!isset($schema['additionalProperties']) || $schema['additionalProperties'] !== false) {
            $out = $value + $out;
        }

        return $out;
    }

    private function validateValue(array $spec, $value, string $path)
    {
        $types = isset($spec['type']) ? (array) $spec['type'] : [];
        if ($types) {
            $matched = false;
            foreach ($types as $type) {
                $coerced = $this->coerce($type, $value, $ok);
                if ($ok) {
                    $value = $coerced;
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $this->errors[] = sprintf('%s must be of type %s', $path, implode('|', $types));

                return $value;
            }
        }

        if (isset($spec['enum']) && is_array($spec['enum']) && !in_array($value, $spec['enum'], true)) {
            $this->errors[] = sprintf('%s must be one of: %s', $path, implode(', ', array_map('json_encode', $spec['enum'])));
        }
        if (is_int($value) || is_float($value)) {
            if (isset($spec['minimum']) && $value < $spec['minimum']) {
                $this->errors[] = sprintf('%s must be >= %s', $path, $spec['minimum']);
            }
            if (isset($spec['maximum']) && $value > $spec['maximum']) {
                $this->errors[] = sprintf('%s must be <= %s', $path, $spec['maximum']);
            }
        }
        if (is_string($value)) {
            $len = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
            if (isset($spec['minLength']) && $len < $spec['minLength']) {
                $this->errors[] = sprintf('%s must be at least %d characters', $path, $spec['minLength']);
            }
            if (isset($spec['maxLength']) && $len > $spec['maxLength']) {
                $this->errors[] = sprintf('%s must be at most %d characters', $path, $spec['maxLength']);
            }
            if (isset($spec['pattern']) && !preg_match('/' . str_replace('/', '\/', $spec['pattern']) . '/u', $value)) {
                $this->errors[] = sprintf('%s does not match the expected format %s', $path, $spec['pattern']);
            }
        }
        if (is_array($value) && in_array('array', $types, true)) {
            if (isset($spec['maxItems']) && count($value) > $spec['maxItems']) {
                $this->errors[] = sprintf('%s accepts at most %d items', $path, $spec['maxItems']);
            }
            if (isset($spec['minItems']) && count($value) < $spec['minItems']) {
                $this->errors[] = sprintf('%s needs at least %d items', $path, $spec['minItems']);
            }
            if (isset($spec['items']) && is_array($spec['items'])) {
                foreach ($value as $i => $item) {
                    $value[$i] = $this->validateValue($spec['items'], $item, $path . '[' . $i . ']');
                }
            }
        } elseif (is_array($value) && in_array('object', $types, true) && isset($spec['properties'])) {
            $value = $this->validateObject($spec, $value, $path);
        }

        return $value;
    }

    /**
     * @param bool $ok set to true when $value is (or could be coerced to) $type
     */
    private function coerce(string $type, $value, &$ok)
    {
        $ok = true;
        switch ($type) {
            case 'integer':
                if (is_int($value)) {
                    return $value;
                }
                if (is_float($value) && floor($value) == $value) {
                    return (int) $value;
                }
                if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
                    return (int) trim($value);
                }
                break;
            case 'number':
                if (is_int($value) || is_float($value)) {
                    return $value;
                }
                if (is_string($value) && is_numeric(trim(str_replace(',', '.', $value)))) {
                    return (float) trim(str_replace(',', '.', $value));
                }
                break;
            case 'boolean':
                if (is_bool($value)) {
                    return $value;
                }
                if ($value === 1 || $value === 0) {
                    return (bool) $value;
                }
                if (is_string($value) && in_array(strtolower($value), ['true', 'false', '1', '0', 'yes', 'no'], true)) {
                    return in_array(strtolower($value), ['true', '1', 'yes'], true);
                }
                break;
            case 'string':
                if (is_string($value)) {
                    return $value;
                }
                if (is_int($value) || is_float($value)) {
                    return (string) $value;
                }
                break;
            case 'array':
                if ($value instanceof \stdClass) {
                    $value = (array) $value;
                }
                if (is_array($value) && (!$value || array_keys($value) === range(0, count($value) - 1))) {
                    return $value;
                }
                break;
            case 'object':
                if ($value instanceof \stdClass) {
                    return (array) $value;
                }
                if (is_array($value) && ($value === [] || array_keys($value) !== range(0, count($value) - 1))) {
                    return $value;
                }
                break;
            case 'null':
                if ($value === null) {
                    return null;
                }
                break;
        }
        $ok = false;

        return $value;
    }

    private function join(string $path, string $name): string
    {
        return $path === '' ? $name : $path . '.' . $name;
    }
}
