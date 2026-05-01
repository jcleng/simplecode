<?php

namespace SimpleCode\Tool;

use Symfony\Component\Process\Process;

class BashTool implements ToolInterface
{
    public function getName(): string
    {
        return 'bash';
    }

    public function getDescription(): string
    {
        return 'Executes a given bash command in a persistent shell session with optional timeout. Use for git, npm, docker, etc.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'The command to execute',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Clear description of what this command does (5-10 words)',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Optional timeout in milliseconds (max 120000)',
                ],
                'workdir' => [
                    'type' => 'string',
                    'description' => 'Working directory for the command (defaults to current)',
                ],
            ],
            'required' => ['command', 'description'],
        ];
    }

    public function execute(array $params): string
    {
        $command = $params['command'];
        $timeout = min(intval($params['timeout'] ?? 120000), 120000) / 1000;
        $workdir = $params['workdir'] ?? getcwd();

        $process = new Process(['bash', '-c', $command], $workdir);
        $process->setTimeout($timeout);

        try {
            $process->run();
            $output = $process->getOutput();
            $error = $process->getErrorOutput();

            $result = '';
            if ($output) {
                $result .= $output;
            }
            if ($error) {
                $result .= ($result ? PHP_EOL . '--- STDERR ---' . PHP_EOL : '') . $error;
            }
            if (!$result) {
                $result = '[Command completed with no output]';
            }
            return $result;
        } catch (\Exception $e) {
            return 'Error: ' . $e->getMessage();
        }
    }
}
