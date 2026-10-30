<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\IngridGrav\Migration\MigrationRunner;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

class MigrateCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('migrate')
            ->setAliases(['migration'])
            ->setDescription('Performs pending migrations for settings and pages.')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Only shows what would happen')
            ->addOption('status', 's', InputOption::VALUE_NONE, 'Shows the current migration status')
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, 'Migrate only up to this version')
            ->addOption('rollback', 'r', InputOption::VALUE_REQUIRED, 'Reverts to the specified version');
    }

    protected function serve(): int
    {
        // Grav wurde von ConsoleCommand bereits initialisiert.
        // Nur noch den Plugin-Autoloader sicherstellen.
        require_once __DIR__ . '/../vendor/autoload.php';

        $io = $this->io();
        $runner = new MigrationRunner(
            Grav::instance(),
            (bool)$this->input->getOption('dry-run'),
            static fn (string $line) => $io->writeln($line)
        );

        if ($this->input->getOption('status')) {
            return $this->showStatus($runner);
        }

        $version = $this->input->getOption('rollback');
        if ($version) {
            $runner->rollback((string)$version);
            $io->success('Rollback done.');

            return 0;
        }

        $target = $this->input->getOption('target');
        $executed = $runner->migrate($target !== null ? (string)$target : null);

        if (!$executed) {
            $io->success('No migration necessary.');

            return 0;
        }

        $io->success(sprintf('%d migration executed.', count($executed)));

        return 0;
    }

    private function showStatus(MigrationRunner $runner): int
    {
        $io = $this->io();
        $io->title('ingrid-grav – Migration status');
        $io->writeln('Actual version: <info>' . $runner->getState()->getVersion() . '</info>');

        $pending = $runner->getPending();
        if (!$pending) {
            $io->success('No outstanding migrations.');

            return 0;
        }

        $io->writeln('Pending migrations:');
        foreach ($pending as $migration) {
            $io->writeln(sprintf(
                '  <comment>%s</comment> %s',
                $migration->getVersion(),
                $migration->getDescription()
            ));
        }

        return 0;
    }

    private function io(): SymfonyStyle
    {
        if ($this->style === null) {
            $this->style = new SymfonyStyle($this->input, $this->output);
        }

        return $this->style;
    }
}