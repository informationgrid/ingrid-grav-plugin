<?php

namespace Grav\Plugin;

readonly class SearchHitOpendataMetadata {

    public function __construct(
        public ?string $issued,
        public ?string $modified,
    )
    {
    }
}
