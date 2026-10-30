<?php

declare(strict_types=1);

namespace Grav\Plugin\IngridGrav\Migration;

use Grav\Common\Grav;
use RuntimeException;

class MigrationRunner
{
    private Grav $grav;
    private MigrationState $state;
    private bool $dryRun;

    /** @var callable|null */
    private $output;

    public function __construct(?Grav $grav = null, bool $dryRun = false, ?callable $output = null)
    {
        $this->grav = $grav ?? Grav::instance();
        $this->state = new MigrationState($this->grav);
        $this->dryRun = $dryRun;
        $this->output = $output;
    }

    public function getState(): MigrationState
    {
        return $this->state;
    }

    /**
     * Applies all pending migrations.
     *
     * @param string|null $target Optional target version (inclusive)
     * @return string[] List of versions that have been applied
     */
    public function migrate(?string $target = null): array
    {
        $lock = $this->acquireLock();
        $executed = [];

        try {
            foreach ($this->getPending($target) as $migration) {
                $this->write(sprintf(
                    '<info>%s</info> %s',
                    $migration->getVersion(),
                    $migration->getDescription()
                ));

                if ($migration instanceof AbstractMigration) {
                    $migration->setDryRun($this->dryRun);
                }

                $start = microtime(true);
                $migration->up();
                $duration = microtime(true) - $start;

                $log = $migration instanceof AbstractMigration ? $migration->getLog() : [];
                foreach ($log as $line) {
                    $this->write('    - ' . $line);
                }

                if (!$this->dryRun) {
                    $this->state->markApplied($migration, $duration, $log);
                }

                $executed[] = $migration->getVersion();
            }

            if ($executed && !$this->dryRun) {
                $this->clearCache();
            }
        } finally {
            $this->releaseLock($lock);
        }

        return $executed;
    }

    /** Reverts a single migration. */
    public function rollback(string $version): bool
    {
        if (!$this->state->isApplied($version)) {
            $this->write(sprintf('<comment>Version %s is not activ.</comment>', $version));
            return false;
        }

        foreach ($this->loadMigrations() as $migration) {
            if ($migration->getVersion() !== $version) {
                continue;
            }

            if ($migration instanceof AbstractMigration) {
                $migration->setDryRun($this->dryRun);
            }

            $migration->down();

            foreach (($migration instanceof AbstractMigration ? $migration->getLog() : []) as $line) {
                $this->write('    - ' . $line);
            }

            if (!$this->dryRun) {
                $this->state->markReverted($migration);
                $this->clearCache();
            }

            return true;
        }

        throw new RuntimeException(sprintf('Migration "%s" not found.', $version));
    }

    /** @return MigrationInterface[] */
    public function getPending(?string $target = null): array
    {
        $pending = [];
        foreach ($this->loadMigrations() as $migration) {
            if ($this->state->isApplied($migration->getVersion())) {
                continue;
            }
            if ($target !== null && strcmp($migration->getVersion(), $target) > 0) {
                continue;
            }
            $pending[] = $migration;
        }

        return $pending;
    }

    /**
     * Loads all migration classes from plugins://ingrid-grav/migrations,
     * ordered by version.
     *
     * @return MigrationInterface[]
     */
    public function loadMigrations(): array
    {
        $dir = $this->grav['locator']->findResource('plugins://ingrid-grav/migrations', true);
        if (!$dir || !is_dir($dir)) {
            return [];
        }

        $migrations = [];
        foreach (glob($dir . '/Version_*.php') ?: [] as $path) {
            require_once $path;

            $class = __NAMESPACE__ . '\\Versions\\' . basename($path, '.php');
            if (!class_exists($class)) {
                throw new RuntimeException(sprintf('Migration class "%s" missing in %s.', $class, $path));
            }

            /** @var MigrationInterface $migration */
            $migration = new $class($this->grav);
            $migrations[$migration->getVersion()] = $migration;
        }

        ksort($migrations, SORT_STRING);

        return array_values($migrations);
    }

    private function clearCache(): void
    {
        $this->grav['config']->reload();
        $this->grav['cache']->delete('ingrid-grav');
        \Grav\Common\Cache::clearCache('standard');
        $this->write('<info>Cache geleert.</info>');
    }

    /** @return resource */
    private function acquireLock()
    {
        $path = (string)$this->grav['locator']
            ->findResource('user://config/ingrid-migrations.lock', true, true);

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $handle = fopen($path, 'cb');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Migration run exists (Lock activ).');
        }

        return $handle;
    }

    private function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function write(string $message): void
    {
        if ($this->output) {
            ($this->output)($message);
        }
    }
}