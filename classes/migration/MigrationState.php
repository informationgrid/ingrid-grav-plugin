<?php

declare(strict_types=1);

namespace Grav\Plugin\IngridGrav\Migration;

use Grav\Common\Grav;
use Grav\Common\File\CompiledYamlFile;

/**
 * Keeps the migration state of the plugin in
 * user/config/migrations.yaml.
 *
 * This way the state is also available through the Grav config:
 *   $grav['config']->get('migrations.version')
 */

class MigrationState
{
    private const string CONFIG_FILE = 'ingrid-migrations.yaml';

    private Grav $grav;
    private string $path;
    private array $data;

    public function __construct(?Grav $grav = null)
    {
        $this->grav = $grav ?? Grav::instance();

        /** @var UniformResourceLocator $locator */
        $locator = $this->grav['locator'];

        // true, true => build an absolute path even if the file does not exist yet
        $this->path = (string)$locator->findResource('user://config/' . self::CONFIG_FILE . '.yaml', true, true);
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $file = CompiledYamlFile::instance($this->path);
        $this->data = (array)$file->content();
        $file->free();

        $this->data += ['version' => '0', 'applied' => [], 'history' => []];
    }

    public function getVersion(): string
    {
        return (string)$this->data['version'];
    }

    /** @return string[] Liste bereits ausgeführter Versionen */
    public function getApplied(): array
    {
        return array_map('strval', (array)$this->data['applied']);
    }

    public function isApplied(string $version): bool
    {
        return in_array($version, $this->getApplied(), true);
    }

    public function markApplied(MigrationInterface $migration, float $duration, array $log = []): void
    {
        $version = $migration->getVersion();

        $applied = $this->getApplied();
        if (!in_array($version, $applied, true)) {
            $applied[] = $version;
        }
        sort($applied);

        $this->data['applied'] = $applied;
        $this->data['version'] = (string)end($applied);
        $this->data['history'][] = [
            'version' => $version,
            'description' => $migration->getDescription(),
            'direction' => 'up',
            'date' => gmdate('c'),
            'duration_ms' => (int)round($duration * 1000),
            'plugin_version' => $this->pluginVersion(),
            'changes' => $log,
        ];

        $this->save();
    }

    public function markReverted(MigrationInterface $migration): void
    {
        $version = $migration->getVersion();

        $applied = array_values(array_filter(
            $this->getApplied(),
            static fn (string $v): bool => $v !== $version
        ));
        sort($applied);

        $this->data['applied'] = $applied;
        $this->data['version'] = $applied ? (string)end($applied) : '0';
        $this->data['history'][] = [
            'version' => $version,
            'description' => $migration->getDescription(),
            'direction' => 'down',
            'date' => gmdate('c'),
            'plugin_version' => $this->pluginVersion(),
        ];

        $this->save();
    }

    public function getHistory(): array
    {
        return (array)$this->data['history'];
    }

    private function save(): void
    {
        $file = CompiledYamlFile::instance($this->path);
        $file->save($this->data);
        $file->free();
    }

    private function pluginVersion(): string
    {
        $blueprint = Grav::instance()['locator']
            ->findResource('plugins://ingrid-grav/blueprints.yaml', true);

        if ($blueprint && is_file($blueprint)) {
            $file = CompiledYamlFile::instance($blueprint);
            $version = (string)($file->content()['version'] ?? 'unknown');
            $file->free();

            return $version;
        }

        return 'unknown';
    }
}