<?php

namespace SimpleCode\Tool;

interface ToolInterface
{
    public function getName(): string;
    public function getDescription(): string;
    public function getSchema(): array;
    public function execute(array $params): string;
}
