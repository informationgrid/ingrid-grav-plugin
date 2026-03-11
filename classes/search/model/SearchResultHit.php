<?php

namespace Grav\Plugin;

use AllowDynamicProperties;

#[AllowDynamicProperties] class SearchResultHit
{
    public string $title;
    public ?string $uuid;

    public function __construct(string $title)
    {
        $this->title = $title;
    }
}
