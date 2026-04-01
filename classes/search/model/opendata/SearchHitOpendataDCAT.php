<?php

namespace Grav\Plugin;

readonly class SearchHitOpendataDCAT {

    public function __construct(
        public ?string $landing_page,
        public ?string $applicable_legislation,
        public ?string $availability,
    )
    {
    }
}
