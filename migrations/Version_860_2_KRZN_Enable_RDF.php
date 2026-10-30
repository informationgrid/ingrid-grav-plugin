<?php

declare(strict_types=1);

namespace Grav\Plugin\IngridGrav\Migration\Versions;

use Grav\Plugin\IngridGrav\Migration\AbstractMigration;

class Version_860_2_KRZN_Enable_RDF extends AbstractMigration
{

    public function getVersion(): string
    {
        return get_class($this);
    }

    public function getDescription(): string
    {
        return 'KRZN: Enable RDF';
    }

    public function up(): void
    {
        $this->setConfig('themes/krzn', 'hit_search.rdf_enabled', false);
        $this->setConfig('themes/krzn', 'hit_detail.rdf_enabled', false);
    }

    public function down(): void
    {
    }
}