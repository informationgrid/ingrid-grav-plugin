<?php

namespace Grav\Plugin;

readonly class SearchHitOpendataDistribution {

    public function __construct(
        public array $formats,
        public string $access_url,
        public string $modified,
        public string $title,
        public string $description,
        public SearchHitOpendataDistributionLicense|false $license,
        public array $languages,
        public string $availability,
    )
    {
    }
}
