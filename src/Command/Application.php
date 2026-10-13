<?php

namespace ScieloScrapping\Command;

use ScieloScrapping\Command\Ojs\ImportCommand;
use ScieloScrapping\Command\Ojs\SetupOjsCommand;
use ScieloScrapping\Command\Scielo\DownloadAllCommand;
use ScieloScrapping\Command\Scielo\DownloadBinaryCommand;
use ScieloScrapping\Command\Scielo\DownloadMetadataCommand;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The console application that handles the commands
 */
class Application extends BaseApplication
{
    /**
     * {@inheritDoc}
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        if (PHP_VERSION_ID < 70302) {
            $output->writeln('<error>Suporte apenas para PHP 7.3.2 ou maior.</error>');
        }

        parent::doRun($input, $output);
    }

    public function getHelp(): string
    {
        return <<<HELP
            SciELO downloader

            Download all publications of a journal from SciELO.
            HELP;
    }

    /**
     * Initializes all commands.
     */
    protected function getDefaultCommands(): array
    {
        $commands = array_merge(parent::getDefaultCommands(), [
            new DownloadMetadataCommand(),
            new DownloadBinaryCommand(),
            new DownloadAllCommand(),
            new ImportCommand(),
            new SetupOjsCommand()
        ]);


        return $commands;
    }
}
