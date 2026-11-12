<?php

declare(strict_types=1);

namespace Grav\Plugin\IngridGrav\Migration\Versions;

use Grav\Plugin\IngridGrav\Migration\AbstractMigration;

class Version_860_3_ADVMIS_Enable_Sort extends AbstractMigration
{

    public function getVersion(): string
    {
        return (new \ReflectionClass($this))->getShortName();
    }

    public function getDescription(): string
    {
        return 'AdV_MIS: Enable search sort';
    }

    public function up(): void
    {
        $this->setConfig('themes/bkg_adv_mis', 'hit_search.sort.enabled', true);
    }

    public function down(): void
    {
    }
}