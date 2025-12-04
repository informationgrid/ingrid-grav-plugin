<?php

namespace Grav\Plugin;

readonly class SearchHitOpendataMetdata {

    public function __construct(
        public string $issued,
        public string $modified,
    )
    {
    }
}
