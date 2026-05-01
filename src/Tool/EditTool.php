<?php

namespace SimpleCode\Tool;

class EditTool implements ToolInterface
{
    public function getName(): string
    {
        return 'edit_file';
    }

    public function getDescription(): string
    {
        return 'Performs exact string replacements in files. Requires reading the file first to get exact indentation.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filePath' => [
                    'type' => 'string',
                    'description' => 'The absolute path to the file to edit',
                ],
                'oldString' => [
                    'type' => 'string',
                    'description' => 'The exact text to replace (must include correct indentation from Read tool output)',
                ],
                'newString' => [
                    'type' => 'string',
                    'description' => 'The text to replace it with (must be different from oldString)',
                ],
                'replaceAll' => [
                    'type' => 'boolean',
                    'description' => 'Replace all occurrences of oldString (default false)',
                ],
            ],
            'required' => ['filePath', 'oldString', 'newString'],
        ];
    }

    public function execute(array $params): string
    {
        $path = $params['filePath'];
        $oldString = $params['oldString'];
        $newString = $params['newString'];
        $replaceAll = $params['replaceAll'] ?? false;

        if (!file_exists($path)) {
            return "Error: File not found: $path";
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return "Error: Failed to read file: $path";
        }

        if ($replaceAll) {
            $newContent = str_replace($oldString, $newString, $content, $count);
        } else {
            $count = 0;
            $pos = strpos($content, $oldString);
            if ($pos === false) {
                return "Error: oldString not found in file: $path";
            }
            $newContent = substr_replace($content, $newString, $pos, strlen($oldString));
            $count = 1;
        }

        if ($count === 0) {
            return "Error: oldString not found in file: $path";
        }

        if (file_put_contents($path, $newContent) !== false) {
            return "File edited successfully: $path ($count replacement(s))";
        }

        return "Error: Failed to write file: $path";
    }
}
