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
            $description = $tool['description'] ?? '';
            $name = $tool['name'] ?? '';
            $info .= " - $name\n**Description**:\n$description\n\n";
        }

        return $info;
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
