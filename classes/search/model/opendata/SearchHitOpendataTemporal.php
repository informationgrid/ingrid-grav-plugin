<?php

namespace Grav\Plugin;

readonly class SearchHitOpendataTemporal {

    public function __construct(
        public string $accrual_periodicity,
        public string $gte,
        public string $lte,
    )
    {
    }
}
