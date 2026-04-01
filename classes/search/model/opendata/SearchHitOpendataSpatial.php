<?php

namespace Grav\Plugin;

readonly class SearchHitOpendataSpatial {

    public function __construct(
        public ?array $titles,
        public ?array $geometries,
        public ?array $wkts,
        public SearchHitOpendataSpatialAdministrative|false $administrative,
    )
    {
    }
}
