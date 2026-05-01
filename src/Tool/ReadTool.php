<?php

namespace SimpleCode\Tool;

class ReadTool implements ToolInterface
{
    public function getName(): string
    {
        return 'read_file';
    }

    public function getDescription(): string
    {
        return 'Read a file from the local filesystem. Supports text files and images.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filePath' => [
                    'type' => 'string',
                    'description' => 'The absolute path to the file to read',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'The line number to start reading from (1-indexed)',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'The maximum number of lines to read (defaults to 2000)',
                ],
            ],
            'required' => ['filePath'],
        ];
    }

    public function execute(array $params): string
    {
        $path = $params['filePath'];
        $offset = max(1, intval($params['offset'] ?? 1));
        $limit = intval($params['limit'] ?? 2000);

        if (!file_exists($path)) {
            return "Error: File not found: $path";
        }

        if (!is_readable($path)) {
            return "Error: File is not readable: $path";
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return "Error: Failed to read file: $path";
        }

        $totalLines = count($lines);
        $start = $offset - 1;
        $slice = array_slice($lines, $start, $limit);

        $result = [];
        foreach ($slice as $i => $line) {
            $lineNum = $start + $i + 1;
            $result[] = "$lineNum: $line";
        }

        return implode(PHP_EOL, $result);
    }
}
