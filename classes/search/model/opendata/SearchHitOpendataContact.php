<?php

namespace Grav\Plugin;

readonly class SearchHitOpendataContact
{

    public function __construct(
        public ?string $id,
        public ?string $role,
        public ?string $role_name,
        public ?string $name,
        public ?array $communications,
        public ?string $street,
        public ?string $code,
        public ?string $pocode,
        public ?string $pobox,
        public ?string $locality,
        public ?string $country,
        public ?string $administrative_area,
    )
    {
    }
}
