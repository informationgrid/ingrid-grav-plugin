<?php

namespace Grav\Plugin;

readonly class FacetItem
{
    public function __construct(
        public string $value,
        public string $label,
        public int $docCount,
        public string $actionLink,
        public ?string $icon = null,
        public ?string $iconText = null,
        public ?bool $displayOnEmpty = false,
        public ?bool $displayLineAbove = false,
        public ?string $hiddenBy
    )
    {
    }
}
