<?php

namespace SimpleCode\Tool;

class GrepTool implements ToolInterface
{
    public function getName(): string
    {
        return 'grep';
    }

    public function getDescription(): string
    {
        return 'Fast content search tool. Searches file contents using regex patterns. Supports include filter.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'The regex pattern to search for in file contents',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'The directory to search in (defaults to current directory)',
                ],
                'include' => [
                    'type' => 'string',
                    'description' => 'File pattern to include (e.g. "*.php", "*.{ts,tsx}")',
                ],
            ],
            'required' => ['pattern'],
        ];
    }

    public function execute(array $params): string
    {
        $pattern = $params['pattern'];
        $path = $params['path'] ?? getcwd();
        $include = $params['include'] ?? '*';

        $output = [];
        $returnVar = 0;

        if (function_exists('shell_exec')) {
            $cmd = sprintf(
                'cd %s && grep -rni --include=%s %s . 2>/dev/null',
                escapeshellarg($path),
                escapeshellarg($include),
                escapeshellarg($pattern)
            );
            $result = shell_exec($cmd);
            if ($result) {
                return $result;
            }
        }

        $files = $this->findFiles($path, $include);
        $matches = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $lines = explode(PHP_EOL, $content);
            foreach ($lines as $lineNum => $line) {
                if (preg_match('/' . $pattern . '/i', $line)) {
                    $matches[] = $file . ':' . ($lineNum + 1) . ': ' . trim($line);
                }
            }
        }

        if (empty($matches)) {
            return 'No matches found for pattern: ' . $pattern;
        }

        return implode(PHP_EOL, $matches);
    }

    private function findFiles(string $dir, string $pattern): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && fnmatch($pattern, $file->getFilename())) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
