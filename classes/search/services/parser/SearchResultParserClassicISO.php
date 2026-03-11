<?php

namespace Grav\Plugin;
use Grav\Common\Grav;
use Grav\Common\Utils;
use RocketTheme\Toolbox\Event\Event;

class SearchResultParserClassicISO
{

    public static function parseHits(\stdClass $esHit, string $lang): ?SearchResultHit
    {
        $uuid = null;
        $type = null;
        $type_name = null;
        $title = null;
        $time = null;
        $serviceTypes = [];
        $datatypes = ElasticsearchHelper::getValueArray($esHit, "datatype");

        if (in_array("address", $datatypes)) {
            $uuid = ElasticsearchHelper::getValue($esHit, "t02_address.adr_id");
            $type = ElasticsearchHelper::getValue($esHit, "t02_address.typ");
            $type_name = isset($type) ? CodelistHelper::getCodelistEntry(["505"], $type, $lang) : "";
            $title = self::getAddressTitle($esHit, $type);
        } else if (in_array("metadata", $datatypes)) {
            $uuid = ElasticsearchHelper::getValue($esHit, "t01_object.obj_id");
            $type = ElasticsearchHelper::getValue($esHit, "t01_object.obj_class");
            $type_name = isset($type) ? CodelistHelper::getCodelistEntry(["8000"], $type, $lang) : "";
            $title = ElasticsearchHelper::getValue($esHit, "title");
            $time = self::getTime($esHit);
        } else if (in_array("www", $datatypes)) {
            $title = ElasticsearchHelper::getValue($esHit, "title");
        }
        $searchTerms = ElasticsearchHelper::getValueArray($esHit, "t04_search.searchterm");
        $isInspire = ElasticsearchHelper::getValue($esHit, "t01_object.is_inspire_relevant");
        if (empty($isInspire)) {
            $isInspire = "N";
        }
        if ($isInspire == "N") {
            if (in_array("inspire", $searchTerms) || in_array("inspireidentifiziert", $searchTerms)) {
                $isInspire = "Y";
            }
        }
        $isOpendata = ElasticsearchHelper::getValue($esHit, "t01_object.is_open_data");
        if (empty($isOpendata)) {
            $isOpendata = "N";
        }
        if ($isOpendata == "N") {
            if (in_array("opendata", $searchTerms) || in_array("opendataident", $searchTerms)) {
                $isOpendata = "Y";
            }
        }
        $hasAccessConstraint = ElasticsearchHelper::getValue($esHit, "t011_obj_serv.has_access_constraint");
        if (empty($hasAccessConstraint)) {
            $hasAccessConstraint = "N";
        }
        $servType = ElasticsearchHelper::getFirstValue($esHit, "t011_obj_serv.type");
        if (!$servType) {
            $servType = ElasticsearchHelper::getFirstValue($esHit, "refering.object_reference.type");
        }
        $servTypeVersion = ElasticsearchHelper::getFirstValue($esHit, "t011_obj_serv_version.version_value");
        if (!$servTypeVersion) {
            $servTypeVersion = ElasticsearchHelper::getFirstValue($esHit, "refering.object_reference.version");
        }
        $obj_serv_type = $servType;
        $capUrl = ElasticsearchHelper::getFirstValue($esHit, "capabilities_url");
        $datasource_uuid = ElasticsearchHelper::getValue($esHit, "t011_obj_geo.datasource_uuid");

        if ($title) {
            $hit = new SearchResultHit($title);
            $hit->uuid = $uuid;
            $hit->type = $type;
            $hit->type_name = $type_name;
            $hit->url = in_array("www", $datatypes) ? ElasticsearchHelper::getValue($esHit, "url") : null;
            $hit->time = $time;
            $hit->summary = self::getSummary($esHit);
            $hit->datatypes = $datatypes;
            $hit->partners = ElasticsearchHelper::getValueArray($esHit, "partner");
            $hit->providers = array_map(function ($provider) use ($lang) {
                return CodelistHelper::getCodelistEntryByIdent('111', $provider, $lang);
            },
                ElasticsearchHelper::getValueArray($esHit, "provider")
            );
            $hit->data_source = ElasticsearchHelper::getValue($esHit, "dataSourceName");
            $hit->searchterms = $searchTerms;
            $hit->map_bboxes = ElasticsearchHelper::getBBoxes($esHit, $title);
            $hit->t011_obj_serv = ElasticsearchHelper::getValue($esHit, "t011_obj_serv.type");
            $hit->t011_obj_serv = ElasticsearchHelper::getValue($esHit, "t011_obj_serv.type_key");
            $hit->license = self::getLicense($esHit, $lang);
            $hit->links = isset($type) ? self::getLinks($esHit, $type, $servType, $servTypeVersion, $serviceTypes) : [];
            $hit->serviceTypes = $serviceTypes;
            $hit->additional_html_1 = self::getPreviews($esHit, "additional_html_1");
            $hit->isInspire = !($isInspire == "N");
            $hit->isOpendata = !($isOpendata == "N");
            $hit->hasAccessConstraint = !($hasAccessConstraint == "N");
            $hit->isHVD = ElasticsearchHelper::getValue($esHit, "is_hvd") ?? false;
            $hit->obj_serv_type = $obj_serv_type ? CodelistHelper::getCodelistEntryByIso('5100', $obj_serv_type, $lang) : null;
            $hit->mapUrl = $capUrl ? CapabilitiesHelper::getMapUrl($capUrl, $servTypeVersion, $servType, $datasource_uuid) : null;
            $hit->mapUrlClient = ElasticsearchHelper::getFirstValue($esHit, "capabilities_url_with_client");
            $hit->wkts = ElasticsearchHelper::getValueArray($esHit, "wkt_geo_text");
            $hit->y1 = ElasticsearchHelper::getValueArray($esHit, "y1");
            $hit->x1 = ElasticsearchHelper::getValueArray($esHit, "x1");
            $hit->y2 = ElasticsearchHelper::getValueArray($esHit, "y2");
            $hit->x2 = ElasticsearchHelper::getValueArray($esHit, "x2");
            $hit->bwastr_name = ElasticsearchHelper::getValueArray($esHit, "bwstr-bwastr_name");
            $hit->bwastrs = self::getBwaStrs($esHit);
            $hit->bawauftragsnummer = ElasticsearchHelper::getValue($esHit, "bawauftragsnummer");
            $hit->bawauftragstitel = ElasticsearchHelper::getValue($esHit, "bawauftragstitel");
            $hit->data_category = ElasticsearchHelper::getValue($esHit, "data_category");
            $hit->citation = ElasticsearchHelper::getValue($esHit, "additional_html_citation_quote");
            $hit->folderNames = ElasticsearchHelper::getValue($esHit, "object_node.tree_path.name");

            if (in_array("metadata", $datatypes)) {
                $event = new Event([
                    'hit' => $hit,
                    'content' => $esHit,
                    'lang' => $lang,
                ]);
                Grav::instance()->fireEvent('onThemeSearchHitMetadataEvent', $event);
            }
            return $hit;
        }
        return null;
    }

    private static function getSummary(\stdClass $esHit): ?string
    {
        $summary = ElasticsearchHelper::getValue($esHit, 'summary') ?? ElasticsearchHelper::getValue($esHit, 'abstract');
        if (!empty($summary) && str_contains($summary, '<')) {
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

    private static function getPreviews(\stdClass $esHit, string $type): array
    {
        $array = [];

        $previews = ElasticsearchHelper::getValueArray($esHit, $type);
        foreach ($previews as $preview) {
            $url = preg_replace("/.* src='/i", "", $preview);
            $url = preg_replace("/'.*/i", "", $url);

            $title = preg_replace("/.* alt='/i", "", $preview);
            $title = preg_replace("/'.*/i", "", $title);

            $img = $preview;
            $array[] = [
                "url" => $url,
                "title" => $title,
                "img" => $img,
            ];
        }

        return $array;
    }

    private static function getAddressTitle(\stdClass $esHit, string $type): string
    {
        $title = ElasticsearchHelper::getValue($esHit, "title");
        if ($type == "2" or $type == "3") {
            $title = "";
            $title = $title . (ElasticsearchHelper::getValue($esHit, "t02_address.firstname") ?? "");
            if (!empty($title)) {
                $title .= " ";
            }
            $title = $title . (ElasticsearchHelper::getValue($esHit, "t02_address.lastname") ?? "");
        }
        if ($type !== "0") {
            $parents = ElasticsearchHelper::getValueArray($esHit, "t02_address.parents.title");
            if (!empty($parents)) {
                foreach ($parents as $parent) {
                    if (!empty($parent)) {
                        $title = $parent . ', ' . $title;
                    }
                }
            }
        }
        return $title . ' ';
    }

    private static function getLicense(\stdClass $esHit, string $lang): mixed
    {
        $licenseKey = ElasticsearchHelper::getFirstValue($esHit, "object_use_constraint.license_key");
        $licenseValue = ElasticsearchHelper::getFirstValue($esHit, "object_use_constraint.license_value");

        if ($licenseKey || $licenseValue) {
            if ($licenseKey) {
                $item = json_decode(CodelistHelper::getCodelistEntryData(["6500"], $licenseKey));
                if ($item) {
                    return $item;
                }
                $item = CodelistHelper::getCodelistEntry(["6500"], $licenseKey, $lang);
                if ($item) {
                    return array(
                        "name" => $item
                    );
                }
            }
            if ($licenseValue) {
                if (str_starts_with($licenseValue, '{')) {
                    return json_decode($licenseValue);
                } else {
                    return array(
                        "name" => $licenseValue
                    );
                }
            }
        }
        return null;
    }


    private static function getLinks(\stdClass $esHit, string $type, ?string $serviceTyp, ?string $serviceTypeVersion, array &$serviceTypes): array
    {
        $referenceAllUUID = [];
        $referenceAllName = [];
        $referenceAllClass = [];
        $referenceAllClassName = [];
        $referenceAllServiceVersion = [];
        $referenceAllServiceType = [];

        $array = array ();
        $referingObjRefUUID = ElasticsearchHelper::getValueArray($esHit, "refering.object_reference.obj_uuid");
        $referingObjRefName = ElasticsearchHelper::getValueArray($esHit, "refering.object_reference.obj_name");
        $referingObjRefClass = ElasticsearchHelper::getValueArray($esHit, "refering.object_reference.obj_class");
        $referingObjRefType = ElasticsearchHelper::getValueArray($esHit, "refering.object_reference.type");
        $referingObjRefVersion = ElasticsearchHelper::getValueArray($esHit, "refering.object_reference.version");

        foreach ($referingObjRefUUID as $count => $objUuid) {
            if (str_starts_with($objUuid, "http")) {
                $array[] = [
                    "url" => $objUuid,
                    "title" => !empty($referingObjRefName[$count]) ? $referingObjRefName[$count] : $objUuid,
                    "kind" => "other",
                ];
            } else {
                if (!in_array($objUuid, $referenceAllUUID)) {
                    $referenceAllUUID[] = $objUuid;
                    $referenceAllName[] = $referingObjRefName[$count];
                    $referenceAllClass[] = $referingObjRefClass[$count];
                    $referenceAllClassName[] = CodelistHelper::getCodelistEntry(['8000'], $referingObjRefClass[$count], 'de');
                    if ($referingObjRefClass[$count] == "3") {
                        $referenceAllServiceVersion[] = count($referingObjRefVersion) > $count ? $referingObjRefVersion[$count] : "";
                    } else {
                        $referenceAllServiceVersion[] = "";
                    }
                    if (count($referingObjRefType) > $count) {
                        $referenceAllServiceType[] = $referingObjRefType[$count];
                        $tmpObjRefVersion = $referingObjRefVersion[$count];
                        $tmpObjRefVersion = CapabilitiesHelper::extractServiceFromServiceTypeVersion($tmpObjRefVersion) ?? $tmpObjRefVersion;
                        if (!in_array($tmpObjRefVersion, $serviceTypes) and !empty($tmpObjRefVersion)) {
                            $serviceTypes[] = $tmpObjRefVersion;
                        }
                    } else {
                        $referenceAllServiceType[] = "";
                    }
                }
            }
        }

        $objRefUUID = ElasticsearchHelper::getValueArray($esHit, "object_reference.obj_uuid");
        $objRefName = ElasticsearchHelper::getValueArray($esHit, "object_reference.obj_name");
        $objRefClass = ElasticsearchHelper::getValueArray($esHit, "object_reference.obj_class");
        $objRefType = ElasticsearchHelper::getValueArray($esHit, "object_reference.type");
        $objRefVersion = ElasticsearchHelper::getValueArray($esHit, "object_reference.version");

        foreach ($objRefUUID as $count => $objUuid) {
            if (str_starts_with($objUuid, "http")) {
                $array[] = [
                    "url" => $objUuid,
                    "title" => !empty($objRefName[$count]) ? $objRefName[$count] : $objUuid,
                    "kind" => "other",
                ];
            } else {
                if(!empty($objRefName[$count])) {
                    if (!in_array($objUuid, $referenceAllUUID)) {
                        $referenceAllUUID[] = $objUuid;
                        $referenceAllName[] = $objRefName[$count];
                        $referenceAllClass[] = $objRefClass[$count];
                        $referenceAllClassName[] = CodelistHelper::getCodelistEntry(['8000'], $objRefClass[$count], 'de');
                        if ($objRefClass[$count] == "3") {
                            $referenceAllServiceVersion[] = count($objRefVersion) > $count ? $objRefVersion[$count] : "";
                        } else {
                            $referenceAllServiceVersion[] = "";
                        }
                        if (count($objRefType) > $count) {
                            $referenceAllServiceType[] = $objRefType[$count];
                            if (!in_array($objRefType[$count], $serviceTypes) and !empty($objRefType[$count])) {
                                $serviceTypes[] = $objRefType[$count];
                            }
                        } else {
                            $referenceAllServiceType[] = "";
                        }
                    }
                }
            }
        }

        $urlReferenceLink = ElasticsearchHelper::getValueArray($esHit, "t017_url_ref.url_link");
        $urlReferenceContent = ElasticsearchHelper::getValueArray($esHit, "t017_url_ref.content");
        $urlReferenceSpecialRef = ElasticsearchHelper::getValueArray($esHit, "t017_url_ref.special_ref");
        $urlReferenceDatatype = ElasticsearchHelper::getValueArray($esHit, "t017_url_ref.datatype");

        foreach ($urlReferenceLink as $count => $url) {
            if(!empty($url)) {
                $format = !empty($urlReferenceSpecialRef[$count]) ? $urlReferenceSpecialRef[$count] : null;
                $kind = "other";
                if ($format == "9990") {
                    $kind = "download";
                } else if ($format == "3600") {
                    $kind = "reference";
                }
                $array[] = [
                    "url" => $url,
                    "title" => !empty($urlReferenceContent[$count]) ? $urlReferenceContent[$count] : $url,
                    "serviceType" => $format == "9990" && count($urlReferenceDatatype) > $count ? $urlReferenceDatatype[$count] : "",
                    "type" => $format == "3600" ? "1" : null,
                    "typeName" => $format == "3600" ? CodelistHelper::getCodelistEntry('8000', '1', 'de') : null,
                    "kind" => $kind,
                ];
                // Link zur Verordnung
                if ($format == "9980") {
                    $kind = "regulation";
                    $array[] = [
                        "url" => $url,
                        "title" => !empty($urlReferenceContent[$count]) ? $urlReferenceContent[$count] : $url,
                        "kind" => $kind,
                    ];
                }
                if ($kind == "other") {
                    $array[] = [
                        "url" => $url,
                        "title" => !empty($urlReferenceContent[$count]) ? $urlReferenceContent[$count] : $url,
                        "serviceType" => $format == "9990" && count($urlReferenceDatatype) > $count ? $urlReferenceDatatype[$count] : "",
                        "type" => $format == "3600" ? "1" : null,
                        "typeName" => $format == "3600" ? CodelistHelper::getCodelistEntry('8000', '1', 'de') : null,
                        "kind" => "other_exclude_regulation",
                    ];
                }
                if (count($urlReferenceDatatype) > $count) {
                    if (!in_array($urlReferenceDatatype[$count], $serviceTypes) and !empty($urlReferenceDatatype[$count])) {
                        $serviceTypes[] = $urlReferenceDatatype[$count];
                    }
                }
            }
        }

        foreach($referenceAllUUID as $count => $uuid) {
            $array[] = [
                "uuid" => $uuid,
                "title" => $referenceAllName[$count],
                "type" => $referenceAllClass[$count],
                "typeName" => $referenceAllClassName[$count],
                "serviceType" => CapabilitiesHelper::getHitServiceType($referenceAllServiceVersion[$count], $referenceAllServiceType[$count]),
                "kind" => "reference",
            ];
        }

        // URL des Zugangs
        if ($type == "3") {
            $connectPointLink = ElasticsearchHelper::getFirstValue($esHit, "capabilities_url");
            if (empty($connectPointLink)) {
                $connectPointLink = ElasticsearchHelper::getFirstValue($esHit, "t011_obj_serv_op_connpoint.connect_point");
            }
            if ($connectPointLink) {
                $capURL = CapabilitiesHelper::getCapabilitiesUrl($connectPointLink, $serviceTypeVersion, $serviceTyp);
                $array[] = [
                    "url" => $capURL,
                    "title" => $capURL,
                    "kind" => "access",
                ];
            }
        } else if ($type == "6") {
            $connectPointLink = ElasticsearchHelper::getValueArray($esHit, "t011_obj_serv_url.url");
            $connectPointLinkName = ElasticsearchHelper::getValueArray($esHit, "t011_obj_serv_url.name");
            foreach ($connectPointLink as $count => $url) {
                $array[] = [
                    "url" => $url,
                    "title" => !empty($connectPointLinkName[$count]) ? $connectPointLinkName[$count] : $url,
                    "kind" => "access",
                ];
            }
        }
        $config = Grav::instance()['config'];
        $theme = $config->get('system.pages.theme');
        $sortLinksASC = $config->get('themes.' . $theme . '.hit_search.link_sort_asc') ?? true;
        if ($sortLinksASC) {
            return Utils::sortArrayByKey($array, "title", SORT_ASC);
        }
        return $array;
    }

    private static function getTime($esHit): array
    {
        return [
            "type" => ElasticsearchHelper::getValue($esHit, "t01_object.time_type"),
            "t0" => ElasticsearchHelper::getValueTime($esHit, "t0"),
            "t1" => ElasticsearchHelper::getValueTime($esHit, "t1"),
            "t2" => ElasticsearchHelper::getValueTime($esHit, "t2"),
        ];
    }

    private static function getBwaStrs(\stdClass $esHit): array
    {
        $array = [];
        $ids = ElasticsearchHelper::getValueArray($esHit, "bwstr-bwastr-id");
        $froms = ElasticsearchHelper::getValueArray($esHit, "bwstr-strecken_km_von");
        $tos = ElasticsearchHelper::getValueArray($esHit, "bwstr-strecken_km_bis");
        if (!empty($ids) && !empty($froms) && !empty($tos)) {
            for ($i = 0; $i < count($ids); $i++) {
                $id = $ids[$i];
                if (str_ends_with($id, '00')) {
                    $id = substr($id, 0, -2);
                    $id = $id . '01';
                }
                $array[] = [
                    "id" => $id,
                    "from" => $froms[$i],
                    "to" => $tos[$i],
                ];
            }
        }
        return $array;
    }
}