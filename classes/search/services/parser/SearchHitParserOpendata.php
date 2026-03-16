<?php

namespace Grav\Plugin;
use Grav\Common\Grav;
use Grav\Common\Utils;

class SearchHitParserOpendata
{

    public static function parseHits(\stdClass $esHit, string $lang): SearchHitOpendata
    {
        $id = ElasticsearchHelper::getValue($esHit, 'id');
        $title = ElasticsearchHelper::getValue($esHit, 'title');
        $description = self::getSummary($esHit);
        $created = ElasticsearchHelper::getValue($esHit, 'metadata.created');
        $legal_basis = ElasticsearchHelper::getValue($esHit, 'legal_basis');
        $metadata = self::getMetadata($esHit, $lang);
        $keywords = self::getKeywords($esHit, $lang);
        $distributions = self::getDistributions($esHit, $lang);
        $political_geocoding_level_uri = ElasticsearchHelper::getValue($esHit, 'political_geocoding_level_uri');
        $modified = ElasticsearchHelper::getValue($esHit, 'metadata.modified');
        $issued = ElasticsearchHelper::getValue($esHit, 'metadata.issued');
        $dcat = self::getDCAT($esHit, $lang);
        $spatial = self::getSpatial($esHit, $lang);
        $parent_id = ElasticsearchHelper::getValue($esHit, 'parent_id') ?? "";
        $contacts = self::getContacts($esHit, $lang);
        $temporal = self::getTemporal($esHit, $lang);

        return new SearchHitOpendata(
            $id,
            $title,
            $description,
            $created,
            $legal_basis,
            $metadata,
            $keywords,
            $distributions,
            $political_geocoding_level_uri,
            $modified,
            $issued,
            $dcat,
            $spatial,
            $parent_id,
            $contacts,
            $temporal,
        );
    }

    private static function getSummary(\stdClass $esHit): ?string
    {
        $summary = ElasticsearchHelper::getValue($esHit, 'description');
        if (!empty($summary)) {
            $doc = new \DomDocument();
            $summary = \mb_convert_encoding($summary, 'HTML-ENTITIES', 'UTF-8');
            libxml_use_internal_errors(true);
            $doc->loadHTML($summary, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            foreach (libxml_get_errors() as $error) {
                DebugHelper::error('Error on load HTML: ' . $error->code . ':' . $error->message);
            }
            libxml_clear_errors();
            $summary = $doc->saveHTML();
            while(str_starts_with($summary, '<p>')) {
                $replace = '';
                $find = '<p>';
                $summary = preg_replace("@$find@", $replace, $summary, 1);
                $find = '</p>';
                $summary = preg_replace(strrev("@$find@"), strrev($replace), strrev($summary), 1);
                $summary = strrev($summary);
            }
        }
        return $summary;
    }

    private static function getContacts(\stdClass $esHit, string $lang): array
    {
        $array = [];

        $items = ElasticsearchHelper::getValueArray($esHit, 'contacts');
        foreach ($items as $item) {
            $role = $item->role ?? '';
            $name = $item->name ?? '';
            $street = $item->street ?? '';
            $code = $item->code ?? '';
            $pocode = $item->pocode ?? '';
            $pobox = $item->pobox ?? '';
            $locality = $item->locality ?? '';
            $country = $item->country ?? '';
            $administrative_area = $item->administrative_area ?? '';

            $communications = [];
            $tmpCommunications = $item->communications;
            foreach ($tmpCommunications as $tmpCommunication) {
                $communications[] = new SearchHitOpendataContactCommunication(
                    $tmpCommunication->type ?? '',
                    $tmpCommunication->value ?? '',
                );
            }
            $array[] = new SearchHitOpendataContact(
                '',
                $role,
                $role ? CodelistHelper::getCodelistEntry('505', $role, $lang) : '',
                $name,
                $communications,
                $street,
                $code,
                $pocode,
                $pobox,
                $locality,
                $country ? CountryHelper::getNameFromNumber($country, $lang) : '',
                $administrative_area,
            );
        }
        return $array;
    }

    private static function getMetadata(\stdClass $esHit, string $lang): SearchHitOpendataMetdata|false
    {
        $item = ElasticsearchHelper::getValue($esHit, 'metadata');
        if ($item) {
            $issued = ElasticsearchHelper::getValue($esHit, 'metadata.issued') ?? '';
            $modified = ElasticsearchHelper::getValue($esHit, 'metadata.modified') ?? '';
            return new SearchHitOpendataMetdata(
                $issued,
                $modified,
            );
        }
        return false;
    }

    private static function getTemporal(\stdClass $esHit, string $lang): SearchHitOpendataTemporal|false
    {
        $item = ElasticsearchHelper::getValue($esHit, 'temporal');
        if ($item) {
            $accrual_periodicity = ElasticsearchHelper::getValue($esHit, 'temporal.accrual_periodicity') ?? '';
            $gte = ElasticsearchHelper::getValue($esHit, 'temporal.gte') ?? '';
            $lte = ElasticsearchHelper::getValue($esHit, 'temporal.lte') ?? '';
            return new SearchHitOpendataTemporal(
                $accrual_periodicity,
                $gte,
                $lte
            );
        }
        return false;
    }

    private static function getDCAT(\stdClass $esHit, string $lang): SearchHitOpendataDCAT|false
    {
        $item = ElasticsearchHelper::getValue($esHit, 'dcat');
        if ($item) {
            $landing_page = ElasticsearchHelper::getValue($esHit, 'dcat.landingPage') ?? '';
            if (!empty($landing_page)) {
                return new SearchHitOpendataDCAT(
                    $landing_page,
                    null,
                    null
                );
            }
        }
        return false;
    }

    private static function getSpatial(\stdClass $esHit, string $lang): SearchHitOpendataSpatial|false
    {
        $item = ElasticsearchHelper::getValue($esHit, 'spatial');
        if ($item) {
            $titles = ElasticsearchHelper::getValueArray($esHit, 'spatial.title') ?? [];
            $geometries = [];
            $wkts = [];
            $tmpGeometries = ElasticsearchHelper::getValueArray($esHit, 'spatial.geometries');
            foreach ($tmpGeometries as $tmpGeometrie) {
                $geojson = json_encode((array)$tmpGeometrie);
                $geometries[] = $geojson;
                $wkts[] = GeoHelper::transformGeojsonToWKT($geojson);
            }
            $administrative = new SearchHitOpendataSpatialAdministrative(
                ElasticsearchHelper::getValueArray($esHit, 'spatial.administrative.regional_key')
            ) ?? false;
            if (!empty($geometries)) {
                return new SearchHitOpendataSpatial(
                    $titles,
                    $geometries,
                    $wkts,
                    $administrative
                );
            }
        }
        return false;
    }

    private static function getKeywords(\stdClass $esHit, string $lang): array
    {
        $array = [];

        $items = ElasticsearchHelper::getValueArray($esHit, 'keywords');
        foreach ($items as $item) {
            $term = $item->term ?? '';
            $id = $item->id ?? '';
            $source = $item->source ?? '';
            $key = '';
            if (!empty($term) && !empty($source)) {
                if (!empty($id) && $source === "THEMES") {
                    $key = CodelistHelper::getCodelistEntryData('6400', $id);
                }
                $array[] = new SearchHitOpendataKeyword(
                    $term,
                    $id,
                    $source,
                    $key,
                );
            }
        }
        return $array;
    }

    private static function getDistributions(\stdClass $esHit, string $lang): array
    {
        $array = [];

        $items = ElasticsearchHelper::getValueArray($esHit, 'distributions');
        foreach ($items as $item) {
            $formats = $item->format ?? [];
            $access_url = $item->access_url ?? '';
            $modified = $item->modified ?? '';
            $title = $item->title ?? '';
            $description = $item->description ?? '';
            $languages = $item->languages ?? [];
            $availability = $item->availability ?? '';
            $license = false;
            $tmpLicense = $item->license;
            if($tmpLicense) {
                $license = new SearchHitOpendataDistributionLicense(
                    $tmpLicense->url ?? '',
                    $tmpLicense->name ?? '',
                    $tmpLicense->attribution_by_text ?? '',
                );
            }
            $array[] = new SearchHitOpendataDistribution(
                $formats,
                $access_url,
                $modified,
                $title,
                $description,
                $license,
                $languages,
                $availability,
            );
        }
        return $array;
    }
}