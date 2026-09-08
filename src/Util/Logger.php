<?php

namespace SimpleCode\Util;

class Logger
{
    private string $logDir;

    public function __construct(string $logDir = 'logs')
    {
        $this->logDir = __DIR__ . '/../../' . trim($logDir, '/');
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0777, true);
        }
    }

    /**
     * 追加一行到 logs/<name>_<date>.log
     * Append a line to logs/<name>_<date>.log
     */
    public function log(string $name, string $message): void
    {
        $file = $this->logDir . '/' . $name . '_' . date('Y-m-d') . '.log';
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}