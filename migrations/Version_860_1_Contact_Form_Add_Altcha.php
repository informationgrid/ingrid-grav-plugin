<?php

declare(strict_types=1);

namespace Grav\Plugin\IngridGrav\Migration\Versions;

use Grav\Plugin\IngridGrav\Migration\AbstractMigration;

class Version_860_1_Contact_Form_Add_Altcha extends AbstractMigration
{
    private const string FOLDER = 'contact/form';
    private const string TEMPLATE = 'default';

    public function getVersion(): string
    {
        return get_class($this);
    }

    public function getDescription(): string
    {
        return 'Update: Altcha-Captcha under user/pages/contact/form';
    }

    public function up(): void
    {

        $this->removeFormsField(self::FOLDER, self::TEMPLATE, 'contact-form', 'message');
        $this->removeFormsField(self::FOLDER, self::TEMPLATE, 'contact-form', 'name');
        $this->removeFormsField(self::FOLDER, self::TEMPLATE, 'contact-form', 'email');

        $this->upsertFormsField(self::FOLDER, self::TEMPLATE, [
            'name' => 'altcha',
            'type' => 'altcha',
        ], 'contact-form');

        $this->modifyPageHeader(self::FOLDER, self::TEMPLATE, static function (array $header): ?array {
            $process = $header['forms']['contact-form']['process'] ?? [];

            array_splice($process, 0, 0, [['altcha' => true]]);

            $header['forms']['contact-form']['process'] = $process;

            return $header;
        });
    }

    public function down(): void
    {
    }
}