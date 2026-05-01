<?php

namespace SimpleCode\Command;

use SimpleCode\Tool\ToolRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'tools')]
class ToolsCommand extends Command
{
    protected function configure(): void
    {
        $this->setDescription('List all available tools');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tools = new ToolRegistry();

        $io->title('Available Tools');
        foreach ($tools->getAll() as $tool) {
            $io->writeln('  <info>' . $tool->getName() . '</info>: ' . $tool->getDescription());
        }

        return Command::SUCCESS;
    }
}
