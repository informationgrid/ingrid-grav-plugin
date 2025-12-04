<?php

namespace Grav\Plugin;

readonly class SearchHitOpendataContactCommunication {

    public function __construct(
        public string $type,
        public string $value,
    )
    {
    }
}
