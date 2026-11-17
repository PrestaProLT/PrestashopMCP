<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Mcp;

use PrestaPro\PpMcp\Skill\SkillRepository;
use PrestaPro\PpMcp\Tool\ToolRegistry;
use PrestaPro\PpMcp\Tool\ToolRunner;

/**
 * Stateless, dual-era MCP server over Streamable HTTP (JSON responses only).
 *
 *  - legacy era (2025-03-26 … 2025-11-25): initialize handshake, optional
 *    MCP-Protocol-Version header, batches allowed for 2025-03-26.
 *  - modern era (2026-07-28): no handshake, protocol version + client
 *    capabilities in every request's params._meta, Mcp-Method / Mcp-Name
 *    headers, server/discover, resultType / ttlMs / cacheScope on results.
 *
 * No session ids are minted and nothing is kept between requests.
 */
class Server
{
    const MODERN_VERSIONS = ['2026-07-28'];
    const LEGACY_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];
    const DEFAULT_LEGACY_VERSION = '2025-03-26';
    const META_VERSION = 'io.modelcontextprotocol/protocolVersion';
    const META_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
    const META_CLIENT_INFO = 'io.modelcontextprotocol/clientInfo';
    const META_SERVER_INFO = 'io.modelcontextprotocol/serverInfo';
    const LIST_TTL_MS = 300000;
    const MAX_RESULT_BYTES = 120000;

    /** @var ToolRegistry */
    private $registry;
    /** @var SkillRepository */
    private $skills;
    /** @var ToolRunner */
    private $runner;
    /** @var array name, title, version */
    private $serverInfo;
    /** @var string */
    private $instructions;
    /** @var array lower-cased request headers */
    private $headers = [];
    /** @var string era of the message being processed */
    private $era = 'legacy';
    /** @var string */
    private $version = self::DEFAULT_LEGACY_VERSION;

    public function __construct(ToolRegistry $registry, SkillRepository $skills, ToolRunner $runner, array $serverInfo, string $instructions)
    {
        $this->registry = $registry;
        $this->skills = $skills;
        $this->runner = $runner;
        $this->serverInfo = $serverInfo;
        $this->instructions = $instructions;
    }

    public static function supportedVersions(): array
    {
        return array_merge(self::MODERN_VERSIONS, self::LEGACY_VERSIONS);
    }

    /**
     * @param array  $headers lower-cased header name => value
     * @param string $body    raw POST body
     */
    public function handle(array $headers, string $body): HttpResponse
    {
        $this->headers = $headers;
        $decoded = Json::decode($body);
        if (!is_array($decoded)) {
            return HttpResponse::json(400, self::errorEnvelope(null, new JsonRpcError(JsonRpcError::PARSE_ERROR, 'Parse error: body is not valid JSON.')));
        }

        // Batch: only legal for 2025-03-26 clients; we accept it for any legacy client.
        if (Json::isList($decoded) && $decoded) {
            $responses = [];
            foreach ($decoded as $message) {
                $r = $this->handleMessage(is_array($message) ? $message : [], true);
                if ($r !== null) {
                    $responses[] = $r['payload'];
                }
            }
            if (!$responses) {
                return new HttpResponse(202);
            }

            return HttpResponse::json(200, $responses);
        }

        $r = $this->handleMessage($decoded, false);
        if ($r === null) {
            return new HttpResponse(202);
        }

        return HttpResponse::json($r['status'], $r['payload']);
    }

    /**
     * @return array|null [status, payload] or null for notifications/responses
     */
    private function handleMessage(array $msg, bool $inBatch): ?array
    {
        $hasId = array_key_exists('id', $msg) && $msg['id'] !== null;
        $id = $hasId ? $msg['id'] : null;

        // Client responses (to server requests we never send) and notifications → 202.
        if (!isset($msg['method'])) {
            if (isset($msg['result']) || isset($msg['error'])) {
                return null;
            }

            return ['status' => 400, 'payload' => self::errorEnvelope($id, new JsonRpcError(JsonRpcError::INVALID_REQUEST, 'Invalid request: "method" is missing.'))];
        }
        $method = (string) $msg['method'];
        $params = isset($msg['params']) && is_array($msg['params']) ? $msg['params'] : [];
        if (!$hasId) {
            return null; // notifications/initialized, notifications/cancelled …
        }

        try {
            $this->detectEra($method, $params, $inBatch);
            $result = $this->dispatch($method, $params);
            $result = $this->decorate($method, $result);

            return ['status' => 200, 'payload' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]];
        } catch (JsonRpcError $e) {
            return ['status' => $e->getHttpStatus(), 'payload' => self::errorEnvelope($id, $e)];
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('ppmcp: internal error in ' . $method . ': ' . $e->getMessage(), 3, null, 'ppmcp');

            return ['status' => 200, 'payload' => self::errorEnvelope($id, new JsonRpcError(JsonRpcError::INTERNAL_ERROR, 'Internal error: ' . $e->getMessage()))];
        }
    }

    /**
     * @throws JsonRpcError
     */
    private function detectEra(string $method, array $params, bool $inBatch): void
    {
        $meta = isset($params['_meta']) && is_array($params['_meta']) ? $params['_meta'] : [];
        $headerVersion = isset($this->headers['mcp-protocol-version']) ? trim($this->headers['mcp-protocol-version']) : '';

        if ($method === 'initialize') {
            $this->era = 'legacy';
            $this->version = self::negotiateLegacy(isset($params['protocolVersion']) ? (string) $params['protocolVersion'] : '');

            return;
        }

        if (isset($meta[self::META_VERSION])) {
            $requested = (string) $meta[self::META_VERSION];
            if (!in_array($requested, self::MODERN_VERSIONS, true)) {
                if (in_array($requested, self::LEGACY_VERSIONS, true)) {
                    $this->era = 'legacy';
                    $this->version = $requested;

                    return;
                }
                throw new JsonRpcError(JsonRpcError::UNSUPPORTED_PROTOCOL_VERSION, 'Unsupported protocol version', ['supported' => self::supportedVersions(), 'requested' => $requested], 400);
            }
            if ($inBatch) {
                throw new JsonRpcError(JsonRpcError::INVALID_REQUEST, 'Batching is not supported in protocol ' . $requested, null, 400);
            }
            if ($headerVersion !== '' && $headerVersion !== $requested) {
                throw new JsonRpcError(JsonRpcError::HEADER_MISMATCH, 'MCP-Protocol-Version header does not match params._meta', null, 400);
            }
            if (!isset($meta[self::META_CAPABILITIES])) {
                throw new JsonRpcError(JsonRpcError::INVALID_PARAMS, 'params._meta["' . self::META_CAPABILITIES . '"] is required', null, 400);
            }
            $this->checkRoutingHeaders($method, $params);
            $this->era = 'modern';
            $this->version = $requested;

            return;
        }

        if ($headerVersion !== '' && in_array($headerVersion, self::MODERN_VERSIONS, true)) {
            throw new JsonRpcError(JsonRpcError::INVALID_PARAMS, 'params._meta["' . self::META_VERSION . '"] is required for protocol ' . $headerVersion, null, 400);
        }
        if ($headerVersion !== '' && !in_array($headerVersion, self::LEGACY_VERSIONS, true)) {
            throw new JsonRpcError(JsonRpcError::UNSUPPORTED_PROTOCOL_VERSION, 'Unsupported protocol version', ['supported' => self::supportedVersions(), 'requested' => $headerVersion], 400);
        }
        $this->era = 'legacy';
        $this->version = $headerVersion !== '' ? $headerVersion : self::DEFAULT_LEGACY_VERSION;
    }

    /**
     * Modern era: Mcp-Method must equal the body method, Mcp-Name the tool /
     * prompt name or resource URI.
     *
     * @throws JsonRpcError
     */
    private function checkRoutingHeaders(string $method, array $params): void
    {
        $hMethod = isset($this->headers['mcp-method']) ? self::decodeHeader($this->headers['mcp-method']) : null;
        if ($hMethod === null || $hMethod !== $method) {
            throw new JsonRpcError(JsonRpcError::HEADER_MISMATCH, 'Mcp-Method header is missing or does not match the request method', null, 400);
        }
        $name = null;
        if ($method === 'tools/call' || $method === 'prompts/get') {
            $name = isset($params['name']) ? (string) $params['name'] : '';
        } elseif ($method === 'resources/read') {
            $name = isset($params['uri']) ? (string) $params['uri'] : '';
        }
        if ($name !== null) {
            $hName = isset($this->headers['mcp-name']) ? self::decodeHeader($this->headers['mcp-name']) : null;
            if ($hName === null || $hName !== $name) {
                throw new JsonRpcError(JsonRpcError::HEADER_MISMATCH, 'Mcp-Name header is missing or does not match the request', null, 400);
            }
        }
    }

    /**
     * Supports the "=?base64?…?=" sentinel encoding for non-ASCII values.
     */
    private static function decodeHeader(string $v): string
    {
        $v = trim($v);
        if (preg_match('/^=\?base64\?(.*)\?=$/', $v, $m)) {
            $d = base64_decode($m[1], true);

            return $d === false ? $v : $d;
        }

        return $v;
    }

    public static function negotiateLegacy(string $requested): string
    {
        if (in_array($requested, self::LEGACY_VERSIONS, true)) {
            return $requested;
        }
        if ($requested !== '' && strcmp($requested, self::DEFAULT_LEGACY_VERSION) < 0) {
            return self::DEFAULT_LEGACY_VERSION;
        }

        return self::LEGACY_VERSIONS[0];
    }

    /**
     * @throws JsonRpcError
     */
    private function dispatch(string $method, array $params): array
    {
        switch ($method) {
            case 'initialize':
                return $this->initialize($params);
            case 'server/discover':
                return $this->discover();
            case 'ping':
                return [];
            case 'tools/list':
                return $this->toolsList();
            case 'tools/call':
                return $this->toolsCall($params);
            case 'prompts/list':
                return $this->promptsList();
            case 'prompts/get':
                return $this->promptsGet($params);
            case 'resources/list':
                return $this->resourcesList();
            case 'resources/templates/list':
                return $this->resourceTemplatesList();
            case 'resources/read':
                return $this->resourcesRead($params);
        }
        throw new JsonRpcError(JsonRpcError::METHOD_NOT_FOUND, 'Method not found: ' . $method, null, $this->era === 'modern' ? 404 : 200);
    }

    private function capabilities(): array
    {
        return [
            'tools' => ['listChanged' => false],
            'prompts' => ['listChanged' => false],
            'resources' => ['subscribe' => false, 'listChanged' => false],
        ];
    }

    private function initialize(array $params): array
    {
        if (isset($params['clientInfo']) && is_array($params['clientInfo'])) {
            $this->runner->setClientInfo($params['clientInfo']);
        }

        return [
            'protocolVersion' => $this->version,
            'capabilities' => $this->capabilities(),
            'serverInfo' => $this->serverInfo,
            'instructions' => $this->instructions,
        ];
    }

    private function discover(): array
    {
        return [
            'supportedVersions' => self::supportedVersions(),
            'capabilities' => $this->capabilities(),
            'instructions' => $this->instructions,
            'ttlMs' => 3600000,
            'cacheScope' => 'private',
        ];
    }

    private function toolsList(): array
    {
        $tools = [];
        foreach ($this->runner->visibleTools($this->registry->all()) as $tool) {
            $tools[] = $tool->toMcp($this->version);
        }
        $this->runner->logListCall('tools/list', $this->version, count($tools));

        return ['tools' => $tools];
    }

    /**
     * @throws JsonRpcError
     */
    private function toolsCall(array $params): array
    {
        $name = isset($params['name']) ? (string) $params['name'] : '';
        if ($name === '') {
            throw new JsonRpcError(JsonRpcError::INVALID_PARAMS, 'tools/call: "name" is required');
        }
        $args = isset($params['arguments']) ? $params['arguments'] : [];
        if (!is_array($args)) {
            throw new JsonRpcError(JsonRpcError::INVALID_PARAMS, 'tools/call: "arguments" must be an object');
        }
        $tool = $this->registry->get($name);
        if (!$tool) {
            $this->runner->logUnknownTool($name, $args, $this->version);
            throw new JsonRpcError(JsonRpcError::INVALID_PARAMS, 'Unknown tool: ' . $name . '. Call tools/list or mcp_tool_search to find the right tool.');
        }
        $meta = isset($params['_meta']) && is_array($params['_meta']) ? $params['_meta'] : [];
        if (isset($meta[self::META_CLIENT_INFO]) && is_array($meta[self::META_CLIENT_INFO])) {
            $this->runner->setClientInfo($meta[self::META_CLIENT_INFO]);
        }

        $outcome = $this->runner->call($tool, $args, $this->version);
        $text = Json::encode($outcome['payload']);
        if (strlen($text) > self::MAX_RESULT_BYTES) {
            // Backstop: clients reject or truncate huge results (claude.ai ~150k chars,
            // Claude Code ~25k tokens). Tools should page; this keeps the call usable.
            $outcome['payload'] = [
                'error' => 'Result too large (' . strlen($text) . ' bytes). Narrow the request: use limit/offset, filters, or a smaller line range.',
                'truncated_preview' => function_exists('mb_strcut') ? mb_strcut($text, 0, 4000, 'UTF-8') : substr($text, 0, 4000),
            ];
            $outcome['isError'] = true;
            $text = Json::encode($outcome['payload']);
        }
        $result = [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => $outcome['isError'],
        ];
        if (!$outcome['isError'] && strcmp($this->version, '2025-06-18') >= 0 && is_array($outcome['payload']) && !Json::isList($outcome['payload'])) {
            $result['structuredContent'] = $outcome['payload'];
        }

        return $result;
    }

    private function promptsList(): array
    {
        $prompts = [];
        foreach ($this->skills->all() as $skill) {
            $prompts[] = [
                'name' => $skill['name'],
                'title' => $skill['title'],
                'description' => trim($skill['description'] . ($skill['when_to_use'] ? ' Use when: ' . $skill['when_to_use'] : '')),
                'arguments' => [
                    ['name' => 'task', 'description' => 'Optional: the concrete task to apply the playbook to.', 'required' => false],
                ],
            ];
        }

        return ['prompts' => $prompts];
    }

    /**
     * @throws JsonRpcError
     */
    private function promptsGet(array $params): array
    {
        $name = isset($params['name']) ? (string) $params['name'] : '';
        $skill = $name !== '' ? $this->skills->get($name) : null;
        if (!$skill) {
            throw new JsonRpcError(JsonRpcError::INVALID_PARAMS, 'Unknown prompt: ' . $name);
        }
        $task = isset($params['arguments']['task']) ? trim((string) $params['arguments']['task']) : '';
        $text = $skill['content'];
        if ($task !== '') {
            $text .= "\n\n---\nTask: " . $task;
        }

        return [
            'description' => $skill['title'],
            'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]]],
        ];
    }

    private function resourcesList(): array
    {
        $resources = [];
        foreach ($this->skills->all() as $skill) {
            $resources[] = [
                'uri' => SkillRepository::uri($skill['name']),
                'name' => $skill['name'],
                'title' => $skill['title'],
                'description' => $skill['description'],
                'mimeType' => 'text/markdown',
            ];
        }

        return ['resources' => $resources];
    }

    private function resourceTemplatesList(): array
    {
        return ['resourceTemplates' => [[
            'uriTemplate' => SkillRepository::URI_PREFIX . '{name}',
            'name' => 'skill',
            'title' => 'PrestaPro MCP skill (playbook)',
            'description' => 'Markdown playbook. List names with mcp_skill_list.',
            'mimeType' => 'text/markdown',
        ]]];
    }

    /**
     * @throws JsonRpcError
     */
    private function resourcesRead(array $params): array
    {
        $uri = isset($params['uri']) ? (string) $params['uri'] : '';
        $skill = null;
        if (strpos($uri, SkillRepository::URI_PREFIX) === 0) {
            $skill = $this->skills->get(substr($uri, strlen(SkillRepository::URI_PREFIX)));
        }
        if (!$skill) {
            throw new JsonRpcError($this->era === 'modern' ? JsonRpcError::INVALID_PARAMS : JsonRpcError::RESOURCE_NOT_FOUND_LEGACY, 'Resource not found', ['uri' => $uri]);
        }

        return ['contents' => [['uri' => $uri, 'mimeType' => 'text/markdown', 'text' => $skill['content']]]];
    }

    /**
     * Adds the fields modern clients require; harmless for legacy ones.
     * Empty arrays that must be JSON objects are converted.
     */
    private function decorate(string $method, array $result)
    {
        if ($this->era === 'modern') {
            $result['resultType'] = 'complete';
            if (!isset($result['_meta'])) {
                $result['_meta'] = [self::META_SERVER_INFO => $this->serverInfo];
            }
            if (in_array($method, ['tools/list', 'prompts/list', 'resources/list', 'resources/templates/list', 'resources/read'], true)) {
                $result['ttlMs'] = self::LIST_TTL_MS;
                $result['cacheScope'] = 'private';
            }
        }

        return self::objectify($result);
    }

    /**
     * json_encode([]) gives "[]"; protocol objects must be "{}". Converts the
     * known object-typed members.
     */
    private static function objectify(array $result)
    {
        if (!$result) {
            return new \stdClass();
        }
        if (isset($result['tools']) && is_array($result['tools'])) {
            foreach ($result['tools'] as $i => $t) {
                if (isset($t['inputSchema']['properties']) && $t['inputSchema']['properties'] === []) {
                    $result['tools'][$i]['inputSchema']['properties'] = new \stdClass();
                }
            }
        }
        if (isset($result['structuredContent']) && $result['structuredContent'] === []) {
            $result['structuredContent'] = new \stdClass();
        }

        return $result;
    }

    private static function errorEnvelope($id, JsonRpcError $e): array
    {
        $env = ['jsonrpc' => '2.0', 'error' => $e->toArray()];
        if ($id !== null) {
            $env['id'] = $id;
        } else {
            $env['id'] = null;
        }

        return $env;
    }
}
