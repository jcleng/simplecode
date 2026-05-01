<?php

namespace SimpleCode\Util;

class Config
{
    private array $config = [];
    private string $configPath;

    public function __construct()
    {
        $this->configPath = __DIR__ . '/../../.simplecode.json';
        $this->load();
    }

    private function load(): void
    {
        if (file_exists($this->configPath)) {
            $content = file_get_contents($this->configPath);
            $this->config = json_decode($content, true) ?? [];
        }
    }

    public function get(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    public function set(string $key, $value): void
    {
        $this->config[$key] = $value;
        $this->save();
    }

    private function save(): void
    {
        file_put_contents($this->configPath, json_encode($this->config, JSON_PRETTY_PRINT));
    }

    public function getApiKey(): string
    {
        return $this->get('api_key') ?? getenv('OPENCODE_API_KEY') ?? '';
    }

    public function getBaseUrl(): string
    {
        return $this->get('base_url') ?? 'https://api.openai.com/v1';
    }

    public function getModel(): string
    {
        return $this->get('model') ?? 'gpt-4o';
    }
}
