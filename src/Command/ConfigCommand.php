<?php

namespace SimpleCode\Command;

use SimpleCode\Util\Config;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'config')]
class ConfigCommand extends Command
{
    protected function configure(): void
    {
        $this->setDescription('Get or set configuration values')
            ->addArgument('key', InputArgument::OPTIONAL, 'Config key to get/set')
            ->addArgument('value', InputArgument::OPTIONAL, 'Value to set');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = new Config();

        $key = $input->getArgument('key');
        $value = $input->getArgument('value');

        if (!$key) {
            $io->writeln('Current configuration:');
            $io->writeln('  API Key: ' . ($config->getApiKey() ? '***' . substr($config->getApiKey(), -4) : 'Not set'));
            $io->writeln('  Base URL: ' . $config->getBaseUrl());
            $io->writeln('  Model: ' . $config->getModel());
            return Command::SUCCESS;
        }

        if (!$value) {
            $val = $config->get($key);
            if ($val === null) {
                $io->warning("Key not found: $key");
                return Command::FAILURE;
            }
            $io->writeln("$key = $val");
            return Command::SUCCESS;
        }

        $config->set($key, $value);
        $io->success("Set $key = $value");
        return Command::SUCCESS;
    }
}
