<?php

namespace Grav\Plugin;

use Grav\Common\Grav;

class SearchResponseTransformerClassic
{
    public static function parseHits(array $hits, string $lang, string $theme): array
    {
        return array_map(
            function($hit) use ($lang, $theme) { return self::parseHit($hit, $lang, $theme); },
            $hits
        );
    }


    /**
     * @param object $aggregations
     * @param FacetConfig[] $config
     * @return FacetResult[]
     */
    public static function parseAggregations(object $aggregations, array $config, $uri, string $lang): array
    {
        $result = array();

        foreach ($config as $facetConfig) {
            $items = array();
            if (property_exists((object)$facetConfig, 'facets')) {
                foreach ($facetConfig['facets'] as $key => $query) {
                    if (isset($key) && $key !== '') {
                        $label = $query['label'] ?? strtoupper('FACETS.' . $facetConfig['id'] . '.' . $key);
                        if (isset($facetConfig['codelist']) or isset($query['codelist'])) {
                            $codelistValue = CodelistHelper::getCodelistEntryByIdent(
                                $query['codelist'] ?? $facetConfig['codelist'],
                                    $query['codelist_entry_id'] ?? $facetConfig['codelist_entry_id'] ?? $key,
                                $lang);
                            if ($codelistValue) {
                                $label = $codelistValue;
                            }
                        }
                        if (isset($query['query'])) {
                            $items[] = new FacetItem(
                                $key,
                                $label,
                                ((array)$aggregations)[$key]->doc_count,
                                SearchResponseTransformerClassic::createActionUrl($uri, $query['parent'] ?? $facetConfig["id"], $key, $config, $facetConfig["link_to_search"] ?? false),
                                $query['icon'] ?? null,
                                $query['icon_text'] ?? null,
                                $query['display_on_empty'] ?? false,
                                $query['display_line_above'] ?? false,
                                $query['hidden_by'] ?? null,
                            );
                        } else if (isset($query['facets'])) {
                            $splitFacets = $query['facets'];
                            $multiFacets = [];
                            foreach ($splitFacets as $splitFacetId => $splitFacetValue) {
                                $newKey = $key . '_' . $splitFacetId;
                                $item = [];
                                $item['label'] = $splitFacetValue['label'];
                                $item['count'] = ((array)$aggregations)[$newKey]->doc_count;
                                $item['actionLink'] = SearchResponseTransformerClassic::createActionUrl($uri, $facetConfig["id"], $key, $config);
                                if (isset($splitFacetValue['extend_href'])) {
                                    $item['actionLink'] = $item['actionLink'] . $splitFacetValue['extend_href'];
                                }
                                $multiFacets[] = $item;
                            }
                            $items[] = new FacetItemMulti(
                                $key,
                                $label,
                                $multiFacets,
                                $query['icon'] ?? null,
                                $query['display_on_empty'] ?? false,
                            );
                        }
                    }
                }
            } else if (property_exists((object)$facetConfig, 'query')) {
                $buckets = ((array)$aggregations)[$facetConfig['id']]->buckets;
                foreach ($buckets as $bucket) {
                    $key = $bucket->key;
                    if (isset($key) && $key !== '') {
                        $transLabel = strtoupper('FACETS.' . $facetConfig['id'] . '.' . $key);
                        $label = $transLabel !== Grav::instance()['language']->translate($transLabel) ? $transLabel : $key;
                        if (isset($facetConfig['codelist'])) {
                            $codelistValue = CodelistHelper::getCodelistEntryByIdent([$facetConfig['codelist']], $key, $lang);
                            if ($codelistValue) {
                                $label = $codelistValue;
                            }
                        }
                        $items[] = new FacetItem(
                            $key,
                            $label,
                            $bucket->final->doc_count ?? $bucket->doc_count,
                            SearchResponseTransformerClassic::createActionUrl($uri, $facetConfig["id"], $key, $config),
                            $facetConfig['icon'] ?? null,
                            $facetConfig['icon_text'] ?? null,
                            $facetConfig['display_on_empty'] ?? false,
                            $facetConfig['display_line_above'] ?? false,
                            $facetConfig['hidden_by'] ?? null,
                        );
                    }
                }
            }
            $label = $facetConfig['label'] ?? 'FACETS.FACET_LABEL.' . strtoupper($facetConfig['id']);
            $listLimit = $facetConfig['list_limit'] ?? null;
            $info = $facetConfig['info'] ?? null;
            $toggle = $facetConfig['toggle'] ?? null;
            $open = $facetConfig['open'] ?? false;
            $openBy = $facetConfig['open_by'] ?? null;
            $sort = $facetConfig['sort'] ?? null;
            switch ($sort) {
                case 'name':
                    usort($items, function ($a, $b) {
                        return strcasecmp($a->label, $b->label);
                    });
                    break;
                case 'count':
                    sort($items, function ($a, $b) {
                        return strcasecmp($a->docCount, $b->docCount);
                    });
                    break;
                default:
                    break;
            }
            $displayDependOn = $facetConfig['display_depend_on'] ?? null;
            $selectionSingle = $facetConfig['selection_single'] ?? null;
            $result[] = new FacetResult(
                $facetConfig['id'],
                $label,
                $items,
                $listLimit,
                $info,
                $toggle,
                $open,
                $openBy,
                $displayDependOn,
                $selectionSingle
            );
        }

        return $result;
    }

    private static function createActionUrl($uri, $facetConfigId, $key, array $facetConfig, bool $linkToSearch = false): string {
        $query_params = $uri->query(null, true);

        if ($linkToSearch) {
            $config = Grav::instance()['config'];
            $theme = $config->get('system.pages.theme');
            $searchSettings = $config->get('themes.' . $theme . '.hit_search') ?? [];
            $facetSearchConfig = $searchSettings['facet_config'] ?? [];
            $searchFacets = array_filter($facetSearchConfig, function ($facet) {
                $hasActive =  false;
                if (isset($facet['facets'])) {
                    foreach ($facet['facets'] as $subFacet) {
                        $hasActive = $subFacet['active'] ?? false;
                        if ($hasActive) {
                            break;
                        }
                    }
                }
                return $hasActive;
            });
        }

        $query_string = array();
        if (isset($query_params[$facetConfigId])) {

            if ($facetConfigId == 'bbox') {
                unset($query_params[$facetConfigId]);
            } elseif ($facetConfigId == 'timeref') {
                unset($query_params[$facetConfigId]);
            } else {
                $filteredObjects = ElasticsearchService::findByFacetId($facetConfig, $facetConfigId);
                $foundObject = reset($filteredObjects);
                $isSelectionSingle = false;
                if ($foundObject) {
                    $isSelectionSingle = $foundObject['selection_single'] ?? false;
                }
                if ($isSelectionSingle) {
                    $found = array_search($key, explode(ElasticsearchService::$FACET_ENTRIES_SEPARATOR, $query_params[$facetConfigId]));
                    if ($found !== false) {
                        unset($query_params[$facetConfigId]);
                    } else {
                        $query_params[$facetConfigId] = $key;
                    }
                } else {
                    $valueAsArray = explode(ElasticsearchService::$FACET_ENTRIES_SEPARATOR, $query_params[$facetConfigId]);
                    $found = array_search($key, $valueAsArray);
                    if ($found !== false) {
                        array_splice($valueAsArray, $found, 1);
                    } else {
                        $valueAsArray[] = $key;
                    }
                    if (count($valueAsArray) > 0) {
                        $query_params[$facetConfigId] = implode(ElasticsearchService::$FACET_ENTRIES_SEPARATOR, $valueAsArray);
                    } else {
                        if (isset($foundObject['facets'])) {
                            $activatableFacets = array_filter($foundObject['facets'], function ($facet) {
                                return isset($facet['active']);
                            });
                            if (empty($activatableFacets)) {
                                unset($query_params[$facetConfigId]);
                            } else {
                                $query_params[$facetConfigId] = '';
                            }
                        } else {
                            unset($query_params[$facetConfigId]);
                        }
                    }
                }
            }
            $filteredObjects = ElasticsearchService::findDependedFacetById($facetConfig, $facetConfigId, $key);
            foreach ($filteredObjects as $filteredObject){
                unset($query_params[$filteredObject['id']]);
            }
        } else {
            if (isset($searchFacets)) {
                foreach ($searchFacets as $searchFacet) {
                    $query_params[$searchFacet['id']] = '';
                }
            }
            foreach ($facetConfig as $facet) {
                if ($facet['id'] === $facetConfigId) {
                    if (isset($facet['select_other'])) {
                        foreach ($facet['select_other'] as $otherKey => $otherParam) {
                            if (!isset($query_params[$otherKey])) {
                                $query_params[$otherKey] = $otherParam;
                            } else {
                                $paramValues = [];
                                if (!empty($query_params[$otherKey])) {
                                    $paramValues = explode(ElasticsearchService::$FACET_ENTRIES_SEPARATOR, $query_params[$otherKey]);
                                }
                                if (!in_array($otherParam, $paramValues)) {
                                    $paramValues[] = $otherParam;
                                    $query_params[$otherKey] = implode(ElasticsearchService::$FACET_ENTRIES_SEPARATOR, $paramValues);
                                }
                            }
                        }
                    } else if (isset($facet['facets'])) {
                        foreach ($facet['facets'] as $subFacetKey => $subFacet) {
                            $otherActiveFacets = [];
                            foreach ($facet['facets'] as $subFacetKey => $subFacet) {
                                if (isset($subFacet['active']) && $subFacet['active']) {
                                    if ($key !== $subFacetKey) {
                                        $otherActiveFacets[] = $subFacetKey;
                                    }
                                }
                            }
                            if (!empty($otherActiveFacets)) {
                                $query_params[$facetConfigId] = implode(ElasticsearchService::$FACET_ENTRIES_SEPARATOR, $otherActiveFacets);
                            }
                        }
                    }
                    break;
                }
            }
            if (!isset($query_params[$facetConfigId])) {
                $query_params[$facetConfigId] = $key;
            }
        }

        if (isset($query_params['more'])) {
            unset($query_params['more']);
        }
        if (isset($query_params['page'])) {
            unset($query_params['page']);
        }

        $query_string[] = http_build_query($query_params);

        // Construct the new URL with the updated query string
        return '?' . join('&', $query_string);
    }

    private static function parseHit($esHit, string $lang, string $theme): ?SearchResultHit
    {
        switch ($theme) {
            case 'uvp':
            case 'uvp-ni':
                return SearchResultParserClassicUVP::parseHits($esHit, $lang);
            default:
                return SearchResultParserClassicISO::parseHits($esHit, $lang);
        }
    }

}
