<?php

namespace SimpleCode\LLM;

use GuzzleHttp\Client as HttpClient;
use SimpleCode\Util\Config;
use SimpleCode\Util\Logger;

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
        $this->messages[] = $this->sanitizeMessage($message);
    }

    /**
     * Recursively sanitize message to ensure valid UTF-8
     */
    private function sanitizeMessage(array $message): array
    {
        foreach ($message as $key => $value) {
            if (is_string($value)) {
                $message[$key] = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            } elseif (is_array($value)) {
                $message[$key] = $this->sanitizeMessage($value);
            }
        }
        return $message;
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
        $logger = new Logger();
        $logger->log('llm_stream', '--- stream start | model=' . $this->config->getModel() . ' | url=' . $this->config->getBaseUrl());

        $body = [
            'model' => $this->config->getModel(),
            'messages' => $this->messages,
            'stream' => true,
        ];

        if (!empty($tools)) {
            $body['tools'] = $tools;
            $body['tool_choice'] = 'auto';
        }

        try {
            $response = $this->http->post('chat/completions', [
                'json' => $body,
            ]);
            $logger->log('llm_stream', 'HTTP ' . $response->getStatusCode());
        } catch (\Exception $e) {
            $logger->log('llm_stream', 'ERROR request failed: ' . $e->getMessage());
            throw $e;
        }

        $stream = $response->getBody();
        $fullContent = '';
        $toolCalls = [];
        $buffer = '';
        $readCount = 0;
        $eventCount = 0;
        $lineCount = 0;

        while (!$stream->eof()) {
            $chunk = $stream->read(1024);
            if ($chunk === '') {
                continue;
            }
            $readCount++;
            $logger->log('llm_stream', '--- raw chunk#' . $readCount . ' size=' . strlen($chunk));

            $buffer .= $chunk;
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines) ?? '';

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $lineCount++;
                if (str_starts_with($line, 'data: ')) {
                    $eventCount++;
                    $logger->log('llm_stream', 'EVENT#' . $eventCount . ' ' . $line);

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
                } else {
                    $logger->log('llm_stream', 'LINE#' . $lineCount . ' ' . $line);
                }
            }
        }

        if (trim($buffer) !== '') {
            $logger->log('llm_stream', 'TAIL ' . trim($buffer));
        }

        $message = [];
        if ($fullContent) {
            $message['content'] = $fullContent;
        }
        if (!empty($toolCalls)) {
            $message['tool_calls'] = array_values($toolCalls);
        }

        $logger->log('llm_stream', '--- stream end | chunks=' . $readCount . ' | events=' . $eventCount . ' | contentLen=' . mb_strlen($fullContent) . ' | toolCalls=' . count($toolCalls));

        return ['choices' => [['message' => $message]]];
    }
}
