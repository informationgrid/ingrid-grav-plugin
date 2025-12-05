<?php

namespace Grav\Plugin;

readonly class SearchHitOpendataKeyword {

    public function __construct(
        public string $term,
        public string $id,
        public string $source,
        public ?string $key,
    )
    {
    }
}
