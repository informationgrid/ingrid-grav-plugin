<?php

namespace Grav\Plugin;

readonly class SearchHitOpendataDistributionLicense {

    public function __construct(
        public ?string $url,
        public ?string $name,
        public ?string $attribution_by_text,
    )
    {
    }
}
