<?php

namespace SimpleCode\LLM;

use GuzzleHttp\Client as HttpClient;
use SimpleCode\Util\Config;

class Client
{
    private HttpClient $http;
    private Config $config;
    private array $messages = [];
    private array $tools = [];

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->http = new HttpClient([
            'base_uri' => rtrim($this->config->getBaseUrl(), '/') . '/',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->config->getApiKey(),
                'Content-Type' => 'application/json',
            ],
            'timeout' => 120,
            'stream' => true,
        ]);
    }

    public function setTools(array $tools): void
    {
        $this->tools = $tools;
    }

    public function addMessage(array $message): void
    {
        $this->messages[] = $message;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function clearMessages(): void
    {
        $this->messages = [];
    }

    public function chat(array $tools = []): array
    {
        return $this->chatNonStream($tools);
    }

    private function chatNonStream(array $tools = []): array
    {
        $body = [
            'model' => $this->config->getModel(),
            'messages' => $this->messages,
        ];

        if (!empty($tools)) {
            $body['tools'] = $tools;
            $body['tool_choice'] = 'auto';
        }

        $response = $this->http->post('chat/completions', [
            'json' => $body,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Stream chat response, calling $onChunk for each content delta
     * Returns the full response array for tool calls
     */
    public function chatStream(array $tools, callable $onChunk): array
    {
        $body = [
            'model' => $this->config->getModel(),
            'messages' => $this->messages,
            'stream' => true,
        ];

        if (!empty($tools)) {
            $body['tools'] = $tools;
            $body['tool_choice'] = 'auto';
        }

        $response = $this->http->post('chat/completions', [
            'json' => $body,
        ]);

        $stream = $response->getBody();
        $fullContent = '';
        $toolCalls = [];
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
                if (str_starts_with($line, 'data: ')) {
                    $data = substr($line, 6);
                    if ($data === '[DONE]') {
                        break;
                    }

                    $parsed = json_decode($data, true);
                    if (!isset($parsed['choices'][0]['delta'])) {
                        continue;
                    }

                    $delta = $parsed['choices'][0]['delta'];
                    $content = $delta['content'] ?? '';
                    $tc = $delta['tool_calls'] ?? [];

                    if ($content) {
                        $fullContent .= $content;
                        $onChunk($content);
                    }

                    foreach ($tc as $tcItem) {
                        $index = $tcItem['index'] ?? 0;
                        if (!isset($toolCalls[$index])) {
                            $toolCalls[$index] = [
                                'id' => '',
                                'type' => 'function',
                                'function' => ['name' => '', 'arguments' => ''],
                            ];
                        }
                        if (isset($tcItem['id'])) {
                            $toolCalls[$index]['id'] = $tcItem['id'];
                        }
                        if (isset($tcItem['function']['name'])) {
                            $toolCalls[$index]['function']['name'] = $tcItem['function']['name'];
                        }
                        if (isset($tcItem['function']['arguments'])) {
                            $toolCalls[$index]['function']['arguments'] .= $tcItem['function']['arguments'];
                        }
                    }
                }
            }
        }

        $message = [];
        if ($fullContent) {
            $message['content'] = $fullContent;
        }
        if (!empty($toolCalls)) {
            $message['tool_calls'] = array_values($toolCalls);
        }

        return ['choices' => [['message' => $message]]];
    }
}
