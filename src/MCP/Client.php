<?php

namespace SimpleCode\MCP;

use GuzzleHttp\Client as HttpClient;
use SimpleCode\Util\Config;

class Client
{
    private HttpClient $http;
    private string $baseUrl;
    private array $tools = [];
    private array $resources = [];
    private array $prompts = [];

    public function __construct(?string $baseUrl = null)
    {
        if ($baseUrl === null) {
            $baseUrl = (new Config())->getMcpBaseUrl();
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->http = new HttpClient([
            'timeout' => 60,
            'stream' => true,
        ]);
    }

    /**
     * Initialize by fetching all available tools, resources, and prompts
     */
    public function init(): void
    {
        $this->tools = $this->callMethod('tools/list', 1)['tools'] ?? [];
        $this->resources = $this->callMethod('resources/list', 2)['resources'] ?? [];
        $this->prompts = $this->callMethod('prompts/list', 3)['prompts'] ?? [];
    }

    /**
     * Call MCP method via JSON-RPC
     */
    private function callMethod(string $method, int $id): array
    {
        $request = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => new \stdClass(),
        ];

        try {
            $response = $this->http->post($this->baseUrl, [
                'json' => $request,
                'stream' => true,
                'headers' => [
                    'Accept' => 'application/json, text/event-stream',
                ],
            ]);

            $contentType = $response->getHeaderLine('Content-Type');
            $body = $response->getBody();

            if (strpos($contentType, 'text/event-stream') !== false) {
                return $this->parseSSE($body);
            }

            $result = json_decode($body->getContents(), true);
            return $result['result'] ?? [];
        } catch (\Exception $e) {
            error_log("MCP call $method failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Parse SSE response
     */
    private function parseSSE($stream): array
    {
        $result = [];
        $buffer = '';

        while (!$stream->eof()) {
            $chunk = $stream->read(1024);
            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines) ?? '';

            foreach ($lines as $line) {
                $line = trim($line);
                if (strpos($line, 'data: ') === 0) {
                    $data = substr($line, 6);
                    if ($data === '[DONE]' || $data === '') {
                        continue;
                    }
                    $parsed = json_decode($data, true);
                    if (isset($parsed['result'])) {
                        $result = $parsed['result'];
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Get tools info for system prompt
     */
    public function getToolsInfo(): string
    {
        if (empty($this->tools)) {
            return '';
        }

        $info = "## AVAILABLE TOOLS FOR SUPERASSISTANT\n";
        foreach ($this->tools as $tool) {
            $name = $tool['name'] ?? '';
            $description = $tool['description'] ?? '';
            $inputSchema = $tool['inputSchema'] ?? [];

            $info .= " - $name\n**Description**:\n$description\n";
            $info .= "**Parameters**:\n" . $this->formatParameters($inputSchema) . "\n";
            $info .= "**Example**:\n" . $this->formatExample($name, $inputSchema) . "\n\n";
        }

        return $info;
    }

    /**
     * 将 inputSchema 拼装为纯文本参数列表(递归展开 items/object 子结构)
     * Assemble inputSchema into a plain-text parameter list (recursively expands items/object substructures)
     */
    private function formatParameters(array $schema, string $indent = ''): string
    {
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];
        $lines = [];
        foreach ($properties as $param => $def) {
            $def = (array) $def;
            $type = $this->resolveType($def);
            $desc = $def['description'] ?? '';
            $req = in_array($param, $required, true) ? 'required' : 'optional';
            $marker = $indent === '' ? ($req === 'required' ? '* ' : '  ') : '- ';
            $line = $indent . $marker . $param . ' (' . $type . ', ' . $req . ')';
            if ($desc) {
                $line .= ' - ' . $desc;
            }
            if (isset($def['enum']) && is_array($def['enum'])) {
                $line .= ' [enum: ' . implode(', ', $def['enum']) . ']';
            }
            $lines[] = $line;

            if ($type === 'array' && isset($def['items'])) {
                $items = (array) $def['items'];
                $lines[] = $indent . '  (items)';
                if (!empty($items['properties'])) {
                    $lines[] = $this->formatParameters([
                        'type' => 'object',
                        'properties' => $items['properties'],
                        'required' => $items['required'] ?? [],
                    ], $indent . '    ');
                } else {
                    $lines[] = $indent . '    items type: ' . $this->resolveType($items);
                }
            } elseif ($type === 'object' && !empty($def['properties'])) {
                $lines[] = $this->formatParameters($def, $indent . '    ');
            }
        }
        return $lines ? implode("\n", $lines) : '(none)';
    }

    /**
     * 生成该工具的 SuperAssistant JSONL 调用示例(只拼必填参数)
     * Generate a SuperAssistant JSONL call example for the tool (required params only)
     */
    private function formatExample(string $name, array $schema): string
    {
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];

        $params = array_values(array_filter(
            array_keys($properties),
            fn($p) => in_array($p, $required, true)
        ));
        if (!$params && $properties) {
            $params = [array_key_first($properties)];
        }

        $jsonl = [];
        $jsonl[] = '{"type":"function_call_start","name":' . json_encode($name) . ',"call_id":1}';
        $jsonl[] = '{"type":"description","text":"Short 1 line of what this function does"}';
        foreach ($params as $param) {
            $jsonl[] = '{"type":"parameter","key":' . json_encode($param) . ',"value":' . json_encode($this->sampleValueFor((array) $properties[$param])) . '}';
        }
        $jsonl[] = '{"type":"function_call_end","call_id":1}';
        return "```jsonl\n" . implode("\n", $jsonl) . "\n```";
    }

    /**
     * 根据参数定义递归生成示例值(支持嵌套 array/object/items/enum)
     * Recursively generate a sample value from the parameter definition (nested array/object/items/enum supported)
     */
    private function sampleValueFor(array $def)
    {
        $type = $this->resolveType($def);
        switch ($type) {
            case 'integer':
            case 'number':
                return 1;
            case 'boolean':
                return true;
            case 'array':
                return isset($def['items']) ? [$this->sampleValueFor((array) $def['items'])] : ['ex'];
            case 'object':
                $out = [];
                foreach ($def['properties'] ?? [] as $k => $item) {
                    $out[$k] = $this->sampleValueFor((array) $item);
                }
                return $out ?: ['ex' => 'ex'];
            case 'null':
                return null;
            default:
                if (isset($def['enum']) && is_array($def['enum']) && $def['enum'] !== []) {
                    return $def['enum'][0];
                }
                return 'ex';
        }
    }

    /**
     * 归一化 JSON Schema 的 type(可能是数组,或来自 anyOf/oneOf)
     * Normalize a JSON Schema type (may be an array, or come from anyOf/oneOf)
     */
    private function resolveType(array $def): string
    {
        $type = $def['type'] ?? null;
        if (is_array($type)) {
            $nonNull = array_values(array_filter($type, fn($t) => $t !== 'null'));
            $type = $nonNull ? $nonNull[0] : ($type[0] ?? null);
        }
        if (is_string($type) && $type !== '') {
            return $type;
        }
        foreach (['anyOf', 'oneOf'] as $key) {
            if (isset($def[$key][0]['type'])) {
                $sub = $def[$key][0]['type'];
                return is_array($sub) ? ($sub[0] ?? 'string') : $sub;
            }
        }
        return 'string';
    }

    /**
     * Send JSONL (MCP JSON-RPC calls) to server
     */
    public function send(string $jsonl): string
    {
        try {
            $lines = explode("\n", trim($jsonl));

            // 如果是多行，只处理第一行（暂时简化）
            // TODO: 支持批量请求
            $singleJson = $lines[0];

            $response = $this->http->post($this->baseUrl, [
                'body' => $singleJson,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json, text/event-stream',
                ],
                'stream' => true,
            ]);

            $contentType = $response->getHeaderLine('Content-Type');
            if (strpos($contentType, 'text/event-stream') !== false) {
                return $this->parseSSEStream($response->getBody());
            }

            return $response->getBody()->getContents();
        } catch (\Exception $e) {
            return 'Error communicating with MCP server: ' . $e->getMessage();
        }
    }

    /**
     * Parse SSE stream for tool call responses
     */
    private function parseSSEStream($stream): string
    {
        $results = [];
        $buffer = '';

        while (!$stream->eof()) {
            $chunk = $stream->read(1024);
            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines) ?? '';

            foreach ($lines as $line) {
                $line = trim($line);
                if (strpos($line, 'data: ') === 0) {
                    $data = substr($line, 6);
                    if ($data === '[DONE]' || $data === '') {
                        continue;
                    }
                    $parsed = json_decode($data, true);
                    if ($parsed) {
                        $results[] = $this->formatResult($parsed);
                    }
                }
            }
        }

        return implode("\n---\n", $results);
    }

    /**
     * Format result for LLM
     */
    private function formatResult(array $data): string
    {
        if (isset($data['result']['content'])) {
            $texts = [];
            foreach ($data['result']['content'] as $item) {
                if ($item['type'] === 'text') {
                    $texts[] = $this->ensureUtf8($item['text']);
                }
            }
            return implode("\n", $texts);
        }

        if (isset($data['result'])) {
            return json_encode($data['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        if (isset($data['error'])) {
            return 'Error: ' . json_encode($data['error'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return json_encode($data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Ensure string is valid UTF-8
     */
    private function ensureUtf8(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        return $text;
    }

    /**
     * Convert SuperAssistant JSONL format to MCP JSON-RPC format
     */
    public function convertToMCP(string $jsonl): string
    {
        $lines = explode("\n", trim($jsonl));
        $mcpCalls = [];
        $currentCall = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $data = json_decode($line, true);
            if (!$data) {
                continue;
            }

            $type = $data['type'] ?? '';

            if ($type === 'function_call_start') {
                $currentCall = [
                    'jsonrpc' => '2.0',
                    'id' => $data['call_id'] ?? 1,
                    'method' => 'tools/call',
                    'params' => [
                        'name' => $data['name'],
                        'arguments' => new \stdClass(),
                    ],
                ];
            } elseif ($type === 'parameter' && $currentCall) {
                // Convert stdClass to array for adding parameters
                if ($currentCall['params']['arguments'] instanceof \stdClass) {
                    $currentCall['params']['arguments'] = [];
                }
                $currentCall['params']['arguments'][$data['key']] = $data['value'];
            } elseif ($type === 'function_call_end' && $currentCall) {
                $mcpCalls[] = json_encode($currentCall, JSON_UNESCAPED_SLASHES);
                $currentCall = null;
            }
        }

        if ($currentCall) {
            $mcpCalls[] = json_encode($currentCall);
        }

        return implode("\n", $mcpCalls);
    }

    public function getTools(): array
    {
        return $this->tools;
    }

    public function getResources(): array
    {
        return $this->resources;
    }

    public function getPrompts(): array
    {
        return $this->prompts;
    }
}
