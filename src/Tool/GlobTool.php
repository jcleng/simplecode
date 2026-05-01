<?php

namespace SimpleCode\Tool;

class GlobTool implements ToolInterface
{
    public function getName(): string
    {
        return 'glob';
    }

    public function getDescription(): string
    {
        return 'Fast file pattern matching tool. Supports glob patterns like "**/*.php" or "src/**/*.ts".';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'The glob pattern to match files against',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'The directory to search in (defaults to current directory)',
                ],
            ],
            'required' => ['pattern'],
        ];
    }

    public function execute(array $params): string
    {
        $pattern = $params['pattern'];
        $path = $params['path'] ?? getcwd();

        $searchPath = rtrim($path, '/') . '/' . $pattern;

        $flags = GLOB_BRACE;
        $files = glob($searchPath, $flags);

        if ($files === false || empty($files)) {
            return 'No files found matching pattern: ' . $pattern;
        }

        $result = [];
        foreach ($files as $file) {
            if (is_file($file)) {
                $result[] = $file;
            }
        }

        if (empty($result)) {
            return 'No files found matching pattern: ' . $pattern;
        }

        sort($result);
        return implode(PHP_EOL, $result);
    }
}
