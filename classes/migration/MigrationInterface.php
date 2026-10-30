<?php

declare(strict_types=1);

namespace Grav\Plugin\IngridGrav\Migration;

interface MigrationInterface
{
    /** Version identifier, must be sortable as a string. */
    public function getVersion(): string;

    /** Short human readable description used for logs and CLI output. */
    public function getDescription(): string;

    /** Applies the migration. */
    public function up(): void;

    /** Reverts the migration (optional, may be a no-op). */
    public function down(): void;
}