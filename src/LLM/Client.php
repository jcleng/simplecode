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
    private int $totalPromptTokens = 0;
    private int $totalCompletionTokens = 0;

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

        $request = ['json' => $body];
        try {
            // 优先请求随流返回 usage
            // Prefer requesting usage along the stream
            $response = $this->http->post('chat/completions', array_merge($request, ['stream_options' => ['include_usage' => true]]));
            $logger->log('llm_stream', 'HTTP ' . $response->getStatusCode());
        } catch (\Exception $e) {
            // 部分端点不支持 stream_options,降级重试
            // Some endpoints reject stream_options; degrade and retry
            $logger->log('llm_stream', 'ERROR first attempt failed (' . $e->getMessage() . '), retrying without stream_options');
            try {
                $response = $this->http->post('chat/completions', $request);
                $logger->log('llm_stream', 'HTTP ' . $response->getStatusCode());
            } catch (\Exception $e2) {
                $logger->log('llm_stream', 'ERROR request failed: ' . $e2->getMessage());
                throw $e2;
            }
        }

        $stream = $response->getBody();
        $fullContent = '';
        $toolCalls = [];
        $buffer = '';
        $usage = [];
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
                    if (isset($parsed['usage']) && is_array($parsed['usage'])) {
                        $usage = $parsed['usage'];
                    }
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

        $result = ['choices' => [['message' => $message]]];
        $estIn = $this->estimateInputTokens();
        $estOut = $this->estimateTokens($fullContent);

        if (!empty($usage) && $this->isUsagePlausible($usage, $estIn, $estOut)) {
            // 接口返回的 usage 可信(与估算量级接近)时优先采用
            // Prefer the endpoint usage when it is plausible (close to the estimate)
            $this->totalPromptTokens += (int) ($usage['prompt_tokens'] ?? 0);
            $this->totalCompletionTokens += (int) ($usage['completion_tokens'] ?? 0);
            $result['usage'] = $usage;
        } else {
            // 端点未返回 usage,或其值不合理(如恒为 1),本地估算
            // Endpoint returned no usage, or its value is implausible (e.g. always 1); estimate locally
            $this->totalPromptTokens += $estIn;
            $this->totalCompletionTokens += $estOut;
            $result['usage'] = ['prompt_tokens' => $estIn, 'completion_tokens' => $estOut, 'total_tokens' => $estIn + $estOut, 'estimated' => true];
        }

        $logger->log('llm_stream', '--- stream end | chunks=' . $readCount . ' | events=' . $eventCount . ' | contentLen=' . mb_strlen($fullContent) . ' | toolCalls=' . count($toolCalls) . ' | prompt_tokens=' . $result['usage']['prompt_tokens'] . ' | completion_tokens=' . $result['usage']['completion_tokens']);

        return $result;
    }

    /**
     * 累计的会话 token 用量(输入 + 输出)
     * Cumulative session token usage (input + output)
     */
    public function getUsage(): array
    {
        return [
            'prompt_tokens' => $this->totalPromptTokens,
            'completion_tokens' => $this->totalCompletionTokens,
            'total_tokens' => $this->totalPromptTokens + $this->totalCompletionTokens,
        ];
    }

    /**
     * 估算一段文本的 token 数(中文按 1 字/token,其余按 4 字符/token)
     * Estimate tokens for a text (CJK ~1 char/token, else ~4 chars/token)
     */
    private function estimateTokens(string $text): int
    {
        $cjk = @preg_match_all('/[\x{4E00}-\x{9FFF}\x{3000}-\x{303F}\x{FF00}-\x{FFEF}]/u', $text, $m);
        if ($cjk === false) {
            // 非法 UTF-8 会使 /u 正则返回 false,规范化后再统计
            // Invalid UTF-8 makes /u regex return false; normalize before counting
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            $cjk = preg_match_all('/[\x{4E00}-\x{9FFF}\x{3000}-\x{303F}\x{FF00}-\x{FFEF}]/u', $text, $m);
        }
        $rest = strlen((string) preg_replace('/[\x{4E00}-\x{9FFF}\x{3000}-\x{303F}\x{FF00}-\x{FFEF}]/u', '', $text));
        return (int) round((int) $cjk + $rest / 4);
    }

    /**
     * 判断接口返回的 usage 是否可信(与本地估算量级接近才采用)
     * Decide whether endpoint usage is trustworthy (only when close to the local estimate)
     */
    private function isUsagePlausible(array $usage, int $estIn, int $estOut): bool
    {
        $in = (int) ($usage['prompt_tokens'] ?? 0);
        $out = (int) ($usage['completion_tokens'] ?? 0);
        return $in >= max(2, $estIn / 2)
            && $in <= max(2, $estIn * 2)
            && $out >= max(2, $estOut / 2)
            && $out <= max(2, $estOut * 2);
    }

    /**
     * 估算全部消息(含工具定义)的输入 token 数
     * Estimate input tokens across all messages (incl. tool definitions)
     */
    private function estimateInputTokens(): int
    {
        $total = 0;
        foreach ($this->messages as $msg) {
            foreach ($msg as $value) {
                if (is_string($value)) {
                    $total += $this->estimateTokens($value);
                } elseif (is_array($value)) {
                    $total += $this->estimateTokens(json_encode($value, JSON_UNESCAPED_UNICODE));
                }
            }
        }
        if ($this->tools) {
            $total += $this->estimateTokens(json_encode($this->tools, JSON_UNESCAPED_UNICODE));
        }
        return $total;
    }
}
