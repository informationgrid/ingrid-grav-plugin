<?php

declare(strict_types=1);

namespace Grav\Plugin\IngridGrav\Migration;

use Grav\Common\Grav;
use Grav\Common\Utils;
use Grav\Common\File\CompiledYamlFile;
use RocketTheme\Toolbox\File\MarkdownFile;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

abstract class AbstractMigration implements MigrationInterface
{
    protected Grav $grav;
    protected UniformResourceLocator $locator;
    protected bool $dryRun = false;

    /** @var string[] Log of all single steps performed by this migration */
    protected array $log = [];

    public function __construct(?Grav $grav = null)
    {
        $this->grav = $grav ?? Grav::instance();
        $this->locator = $this->grav['locator'];
    }

    public function setDryRun(bool $dryRun): void
    {
        $this->dryRun = $dryRun;
    }

    public function getLog(): array
    {
        return $this->log;
    }

    public function down(): void
    {
        $this->note('Kein Rollback für diese Migration definiert.');
    }

    // ------------------------------------------------------------------
    // Settings (user/config/**.yaml)
    // ------------------------------------------------------------------

    /**
     * Sets a configuration value using dot notation.
     *
     * @param string $file      Config file without extension, e.g. "plugins/ingrid-grav" or "system"
     * @param string $key       Key in dot notation, e.g. "search.facets.default_limit"
     * @param mixed  $value     New value
     * @param bool   $overwrite Whether an existing value may be replaced
     */
    protected function setConfig(string $file, string $key, mixed $value, bool $overwrite = true): void
    {
        $path = $this->configPath($file);
        $yaml = CompiledYamlFile::instance($path);
        $data = (array)$yaml->content();

        if (empty($data)) {
            $this->note('File "' . $path . '" is empty or does not exist. Skipping.');
            $yaml->free();
            return;
        }
        $current = Utils::getDotNotation($data, $key, null);
        if (!$overwrite && $current !== null) {
            $this->note(sprintf('%s: "%s" existiert bereits (%s) – übersprungen.', $file, $key, json_encode($current)));
            $yaml->free();
            return;
        }
        if ($current === $value) {
            $yaml->free();
            return;
        }

        Utils::setDotNotation($data, $key, $value);
        $this->writeYaml($yaml, $data, sprintf('%s: "%s" = %s', $file, $key, json_encode($value)));
    }

    /** Renames a configuration key while keeping its value. */
    protected function renameConfigKey(string $file, string $oldKey, string $newKey): void
    {
        $path = $this->configPath($file);
        $yaml = CompiledYamlFile::instance($path);
        $data = (array)$yaml->content();

        $value = Utils::getDotNotation($data, $oldKey, null);
        if ($value === null) {
            $this->note(sprintf('%s: "%s" not exists.', $file, $oldKey));
            $yaml->free();
            return;
        }

        Utils::setDotNotation($data, $newKey, $value);
        $data = $this->unsetDotNotation($data, $oldKey);
        $this->writeYaml($yaml, $data, sprintf('%s: "%s" -> "%s"', $file, $oldKey, $newKey));
    }

    /** Removes a configuration key. */
    protected function removeConfigKey(string $file, string $key): void
    {
        $path = $this->configPath($file);
        $yaml = CompiledYamlFile::instance($path);
        $data = (array)$yaml->content();

        if (Utils::getDotNotation($data, $key, null) === null) {
            $yaml->free();
            return;
        }

        $data = $this->unsetDotNotation($data, $key);
        $this->writeYaml($yaml, $data, sprintf('%s: "%s" entfernt', $file, $key));
    }

    // ------------------------------------------------------------------
    // Pages (user/pages/**)
    // ------------------------------------------------------------------

    /**
     * Creates a page unless it already exists.
     *
     * @param string      $folder   Folder relative to user/pages, e.g. "05.hilfe/01.suche"
     * @param string      $template Template / file name without extension, e.g. "help"
     * @param array       $header   Front matter
     * @param string      $content  Markdown content
     * @param string|null $language Language code, e.g. "de" results in help.de.md
     */
    protected function createPage(
        string $folder,
        string $template,
        array $header = [],
        string $content = '',
        ?string $language = null
    ): void {
        $file = $this->pageFile($folder, $template, $language);

        if ($file->exists()) {
            $this->note(sprintf('Page "%s" already exists. Skipping.', $folder));
            return;
        }

        if ($this->dryRun) {
            $this->note(sprintf('[dry-run] Page "%s" was created.', $folder));
            return;
        }

        $file->header($header);
        $file->markdown($content);
        $file->save();
        $file->free();

        $this->note(sprintf('Page "%s" was created.', $folder));
    }

    /** Updates a single front matter value of an existing page (dot notation). */
    protected function setPageHeader(
        string $folder,
        string $template,
        string $key,
        mixed $value,
        ?string $language = null
    ): void {
        $file = $this->pageFile($folder, $template, $language);
        if (!$file->exists()) {
            $this->note(sprintf('Page "%s" not exists. Skipping.', $folder));
            return;
        }

        $header = (array)$file->header();
        if (Utils::getDotNotation($header, $key) === $value) {
            $file->free();
            return;
        }

        Utils::setDotNotation($header, $key, $value);

        if ($this->dryRun) {
            $this->note(sprintf('[dry-run] %s: header "%s" set.', $folder, $key));
            $file->free();
            return;
        }

        $file->header($header);
        $file->save();
        $file->free();

        $this->note(sprintf('%s: header "%s" = %s', $folder, $key, json_encode($value)));
    }

    /**
     * Modifies the whole front matter through a callback.
     * The callback receives the header as an array and returns the new header.
     * Returning null means nothing will be written.
     */
    protected function modifyPageHeader(
        string $folder,
        string $template,
        callable $modifier,
        ?bool $updateInGridTheme = false,
        ?string $language = null
    ): void {
        $file = $this->pageFile($folder, $template, $language);
        if (!$file->exists()) {
            $this->note(sprintf('Page "%s" not exists. Skipping.', $folder));
            return;
        }

        $config = $this->grav['config'];
        $theme = $config->get('system.pages.theme');

        if (!$updateInGridTheme && $theme === 'ingrid') {
            $this->note(sprintf('%s: no changes on theme "%s" necessary.', $folder, $theme));
            $file->free();
            return;
        }

        $header = (array)$file->header();
        $modified = $modifier($header);

        if ($modified === null || $modified === $header) {
            $this->note(sprintf('%s: no changes necessary.', $folder));
            $file->free();
            return;
        }

        if ($this->dryRun) {
            $this->note(sprintf('[dry-run] %s: Header changed.', $folder));
            $file->free();
            return;
        }

        $file->header($modified);
        $file->save();
        $file->free();

        $this->note(sprintf('%s: Header updated.', $folder));
    }

    /**
     * Inserts or updates a form field in form.fields, matched by its "name".
     *
     * @param array       $field  Field definition including "name"
     * @param string|null $before Name of the field to insert before (appended otherwise)
     */
    protected function upsertFormField(
        string $folder,
        string $template,
        array $field,
        ?string $before = null,
        ?string $language = null
    ): void {
        $this->modifyPageHeader($folder, $template, function (array $header) use ($field, $before): ?array {
            $fields = $header['form']['fields'] ?? [];
            $isAssoc = $fields !== [] && array_keys($fields) !== range(0, count($fields) - 1);

            if ($isAssoc) {
                // Variant: fields defined as a map (name => definition)
                $name = $field['name'];
                unset($field['name']);
                $header['form']['fields'][$name] = array_replace((array)($fields[$name] ?? []), $field);

                return $header;
            }

            // Variant: fields defined as a list
            foreach ($fields as $index => $existing) {
                if (($existing['name'] ?? null) === $field['name']) {
                    $header['form']['fields'][$index] = array_replace($existing, $field);
                    return $header;
                }
            }

            $position = null;
            if ($before !== null) {
                foreach ($fields as $index => $existing) {
                    if (($existing['name'] ?? null) === $before) {
                        $position = $index;
                        break;
                    }
                }
            }

            if ($position === null) {
                $fields[] = $field;
            } else {
                array_splice($fields, $position, 0, [$field]);
            }

            $header['form']['fields'] = $fields;

            return $header;
        }, $language);
    }

    /**
     * Fügt ein Formularfeld in form.fields ein bzw. aktualisiert es anhand von "name".
     *
     * @param array $field  Felddefinition inkl. "name"
     * @param string|null $before Name des Feldes, vor dem eingefügt wird (sonst angehängt)
     */
    protected function upsertFormsField(
        string $folder,
        string $template,
        array $field,
        string $formsName,
        ?string $before = null,
        ?string $language = null
    ): void {
        $this->modifyPageHeader($folder, $template, function (array $header) use ($field, $formsName, $before): ?array {
            $fields = $header['forms'][$formsName]['fields'] ?? [];
            $isAssoc = $fields !== [] && array_keys($fields) !== range(0, count($fields) - 1);

            if ($isAssoc) {
                // Variante: fields als Map (name => definition)
                $name = $field['name'];
                unset($field['name']);
                $header['forms'][$formsName]['fields'][$name] = array_replace((array)($fields[$name] ?? []), $field);

                return $header;
            }

            // Variante: fields als Liste
            foreach ($fields as $index => $existing) {
                if (($existing['name'] ?? null) === $field['name']) {
                    $header['forms'][$formsName]['fields'][$index] = array_replace($existing, $field);
                    return $header;
                }
            }

            $position = null;
            if ($before !== null) {
                foreach ($fields as $index => $existing) {
                    if (($existing['name'] ?? null) === $before) {
                        $position = $index;
                        break;
                    }
                }
            }

            if ($position === null) {
                $fields[] = $field;
            } else {
                array_splice($fields, $position, 0, [$field]);
            }

            $header['forms'][$formsName]['fields'] = $fields;

            return $header;
        }, $language);
    }

    /** Removes a form field identified by its name. */
    protected function removeFormField(
        string $folder,
        string $template,
        string $name,
        ?string $language = null
    ): void {
        $this->modifyPageHeader($folder, $template, function (array $header) use ($name): ?array {
            $fields = $header['form']['fields'] ?? [];
            $isAssoc = $fields !== [] && array_keys($fields) !== range(0, count($fields) - 1);

            if ($isAssoc) {
                if (!isset($fields[$name])) {
                    return null;
                }
                unset($header['form']['fields'][$name]);

                return $header;
            }

            $filtered = array_values(array_filter(
                $fields,
                static fn (array $f): bool => ($f['name'] ?? null) !== $name
            ));

            if (count($filtered) === count($fields)) {
                return null;
            }

            $header['form']['fields'] = $filtered;

            return $header;
        }, $language);
    }

    /** Removes a form field identified by its name. */
    protected function removeFormsField(
        string $folder,
        string $template,
        string $formName,
        string $name,
        ?bool $updateInGridTheme = false,
        ?string $language = null
    ): void {
        $this->modifyPageHeader($folder, $template, function (array $header) use ($formName, $name): ?array {
            $fields = $header['forms'][$formName]['fields'] ?? [];
            $isAssoc = $fields !== [] && array_keys($fields) !== range(0, count($fields) - 1);

            if ($isAssoc) {
                if (!isset($fields[$name])) {
                    return null;
                }
                unset($header['forms'][$formName][$name]);

                return $header;
            }

            $filtered = array_values(array_filter(
                $fields,
                static fn (array $f): bool => ($f['name'] ?? null) !== $name
            ));

            if (count($filtered) === count($fields)) {
                return null;
            }

            $header['forms'][$formName]['fields'] = $filtered;

            return $header;
        }, $updateInGridTheme, $language);
    }

    /** Renames the template of a page, e.g. detail.md -> detail-zdm.md. */
    protected function renamePageTemplate(string $folder, string $from, string $to, ?string $language = null): void
    {
        $source = $this->pagePath($folder, $from, $language);
        $target = $this->pagePath($folder, $to, $language);

        if (!is_file($source) || is_file($target)) {
            $this->note(sprintf('Rename template "%s" skipped.', $folder));
            return;
        }

        if ($this->dryRun) {
            $this->note(sprintf('[dry-run] %s: %s -> %s', $folder, $from, $to));
            return;
        }

        rename($source, $target);
        $this->note(sprintf('%s: %s -> %s', $folder, $from, $to));
    }

    /** Moves a page folder, which changes the route including its ordering prefix. */
    protected function movePage(string $fromFolder, string $toFolder): void
    {
        $source = $this->pagesRoot() . '/' . trim($fromFolder, '/');
        $target = $this->pagesRoot() . '/' . trim($toFolder, '/');

        if (!is_dir($source) || is_dir($target)) {
            $this->note(sprintf('Move page "%s" -> "%s" skipped.', $fromFolder, $toFolder));
            return;
        }

        if ($this->dryRun) {
            $this->note(sprintf('[dry-run] "%s" moved to "%s".', $fromFolder, $toFolder));
            return;
        }

        @mkdir(dirname($target), 0775, true);
        rename($source, $target);
        $this->note(sprintf('"%s" -> "%s" moved.', $fromFolder, $toFolder));
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    protected function note(string $message): void
    {
        $this->log[] = $message;
    }

    private function writeYaml(CompiledYamlFile $yaml, array $data, string $message): void
    {
        if ($this->dryRun) {
            $this->note('[dry-run] ' . $message);
            $yaml->free();
            return;
        }

        $yaml->save($data);
        $yaml->free();
        $this->note($message);
    }

    private function configPath(string $file): string
    {
        $file = trim($file, '/');
        $path = $this->locator->findResource('user://config/' . $file . '.yaml', true, true);

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $path;
    }

    private function pagesRoot(): string
    {
        return rtrim((string)$this->locator->findResource('page://', true, true), '/');
    }

    private function pagePath(string $folder, string $template, ?string $language = null): string
    {
        $name = $language ? sprintf('%s.%s.md', $template, $language) : $template . '.md';

        return $this->pagesRoot() . '/' . trim($folder, '/') . '/' . $name;
    }

    private function pageFile(string $folder, string $template, ?string $language = null): MarkdownFile
    {
        $path = $this->pagePath($folder, $template, $language);
        $dir = dirname($path);
        if (!$this->dryRun && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return MarkdownFile::instance($path);
    }

    private function unsetDotNotation(array $data, string $key): array
    {
        $parts = explode('.', $key);
        $last = array_pop($parts);
        $ref = &$data;

        foreach ($parts as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                return $data;
            }
            $ref = &$ref[$part];
        }

        unset($ref[$last]);

        return $data;
    }
}
