<?php

namespace SimpleCode\Tool;

class ToolRegistry
{
    /** @var ToolInterface[] */
    private array $tools = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    private function registerDefaults(): void
    {
        // No local tools registered - all tools are provided by MCP server
        // Local tools have been removed as they were unused
    }

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    /** @return ToolInterface[] */
    public function getAll(): array
    {
        return array_values($this->tools);
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /** @return array Tool schemas for LLM */
    public function getSchemas(): array
    {
        $schemas = [];
        foreach ($this->tools as $tool) {
            $schemas[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => $tool->getSchema(),
                ],
            ];
        }
        return $schemas;
    }

    public function execute(string $name, array $params): string
    {
        $tool = $this->get($name);
        if (!$tool) {
            return "Error: Tool not found: $name";
        }
        return $tool->execute($params);
    }
}
