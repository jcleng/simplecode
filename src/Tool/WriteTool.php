<?php

namespace SimpleCode\Tool;

class WriteTool implements ToolInterface
{
    public function getName(): string
    {
        return 'write_file';
    }

    public function getDescription(): string
    {
        return 'Writes a file to the local filesystem. Overwrites existing file if present.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filePath' => [
                    'type' => 'string',
                    'description' => 'The absolute path to the file to write',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The content to write to the file',
                ],
            ],
            'required' => ['filePath', 'content'],
        ];
    }

    public function execute(array $params): string
    {
        $path = $path = $params['filePath'];
        $content = $params['content'];

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_put_contents($path, $content) !== false) {
            return "File written successfully: $path";
        }

        return "Error: Failed to write file: $path";
    }
}
