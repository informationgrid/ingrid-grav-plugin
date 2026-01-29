<?php

namespace Grav\Plugin;

readonly class SearchHitOpendata
{

    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public string $created,
        public string $legal_basis,
        public SearchHitOpendataMetdata|bool $metadata,
        public array $keywords,
        public array $distributions,
        public string $political_geocoding_level_uri,
        public string $modified,
        public ?string $issued,
        public SearchHitOpendataDCAT|bool $dcat,
        public SearchHitOpendataSpatial|bool $spatial,
        public string $parent_id,
        public array $contacts,
        public SearchHitOpendataTemporal|bool $temporal,
    )
    {
    }
}
