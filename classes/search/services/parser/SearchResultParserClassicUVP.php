<?php

namespace Grav\Plugin;
use Grav\Common\Plugin;
use Grav\Common\Utils;

class SearchResultParserClassicUVP
{

    public static function parseHits(\stdClass $esHit, string $lang): ?SearchResultHit
    {
        $uuid = null;
        $type = null;
        $type_name = null;
        $title = null;
        $time = null;
        $datatypes = ElasticsearchHelper::getValueArray($esHit, "datatype");

        if (in_array("address", $datatypes)) {
            $uuid = ElasticsearchHelper::getValue($esHit, "t02_address.adr_id");
            $type = ElasticsearchHelper::getValue($esHit, "t02_address.typ");
            $type_name = isset($type) ? CodelistHelper::getCodelistEntry(["505"], $type, $lang) : null;
            $title = self::getAddressTitle($esHit, $type);
        } else if (in_array("metadata", $datatypes)) {
            $uuid = ElasticsearchHelper::getValue($esHit, "t01_object.obj_id");
            $type = ElasticsearchHelper::getValue($esHit, "t01_object.obj_class");
            $type_name = isset($type) ? CodelistHelper::getCodelistEntry(["8001"], $type, $lang) : null;
            $title = ElasticsearchHelper::getValue($esHit, "title");
            $time = ElasticsearchHelper::getValueTime($esHit, "t01_object.mod_time");
        } else if (in_array("blp", $datatypes)) {
            $title = ElasticsearchHelper::getValue($esHit, "title");
            $additional_html_1 = ElasticsearchHelper::getValue($esHit, "additional_html_1");
        }
        if ($title) {
            $hit = new SearchResultHit($title);
            $hit->uuid = $uuid;
            $hit->type = $type;
            $hit->type_name = $type_name;
            $hit->url = in_array("www", $datatypes) ? ElasticsearchHelper::getValue($esHit, "url") : null;
            $hit->time = $time;
            $hit->summary = ElasticsearchHelper::getValue($esHit, "summary") ?? ElasticsearchHelper::getValue($esHit, "abstract");
            $hit->datatypes = $datatypes;
            $hit->partners = ElasticsearchHelper::getValueArray($esHit, "partner");
            $hit->addresses = ElasticsearchHelper::getValueArray($esHit, "uvp_address");
            $hit->categories = ElasticsearchHelper::getValueArray($esHit, "uvp_category");
            $hit->map_bboxes = ElasticsearchHelper::getBBoxes($esHit, $title);
            $hit->additional_html_1 = $additional_html_1 ?? null;
            return $hit;
        }
        return null;
    }
}