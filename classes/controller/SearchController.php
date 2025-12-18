<?php

namespace Grav\Plugin;

use Grav\Common\Grav;
use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Exception;

class SearchController
{

    public Grav $grav;
    public string $configApi;
    public string $lang;
    public string $theme;
    public ?SearchResult $results;
    public string $query;
    public array $selectedFacets;
    public int $hitsNum;
    public string $ranking;
    public int $page;
    public array $facetConfig;

    public function __construct(Grav $grav, string $api)
    {
        $this->grav = $grav;
        $this->configApi = $api;
        $this->lang = $grav['language']->getLanguage();
        $this->theme = $this->grav['config']->get('system.pages.theme');
        $this->results = null;
        $this->query = $this->grav['uri']->query('q') ?? '';
        $this->selectedFacets = [];
        $this->hitsNum = 0;
        $this->ranking = '';
    }


    public function getContent(): void
    {
        $uri = $this->grav['uri'];
        $this->page = $uri->query('page') ?: 1;
        $this->ranking = $uri->query('ranking') ?: '';
        $query = $uri->query('q') ?? '';

        // Theme config
        $searchSettings = $this->grav['config']->get('themes.' . $this->theme . '.hit_search') ?? [];
        $this->facetConfig = $searchSettings['facet_config'] ?? [];
        $this->hitsNum = $searchSettings['hits_num'] ?? 0;

        if (empty($query)) {
            $sortByDate = $searchSettings['sort']['emptyQuerySortByDate'] ?? false;
        } else {
            $sortByDate = $searchSettings['sort']['sortByDate'] ?? false;
        }

        if (empty($this->ranking)) {
            if ($sortByDate) {
                $this->ranking = 'date';
            } else {
                $this->ranking = 'score';
            }
        }

        $this->addFacetCatalog($this->facetConfig);
        $this->addFacetsBySelection($this->facetConfig);
        $this->selectedFacets = $this->getSelectedFacets($this->facetConfig);
        $service = new SearchServiceImpl($this->grav, $this->grav['uri'], $this->facetConfig, $searchSettings);
        $this->results = $service->getSearchResults($this->query, $this->page, $this->selectedFacets, $this->grav['uri'], $this->lang, $this->theme);
    }

    public function getContentMapLegend(): void
    {
        $this->hitsNum = 0;
        // Theme config
        $searchSettings = $this->grav['config']->get('themes.' . $this->theme . '.map.leaflet.legend') ?? [];
        $facetConfig = $searchSettings['facet_config'] ?? [];
        if ($this->theme === 'uvp') {
            $service = new SearchServiceImpl($this->grav, $this->grav['uri'], $facetConfig, $searchSettings);
            $results = $service->getSearchResults("", 1, [], $this->grav['uri'], $this->lang);
            if ($results) {
                $this->results = $results;
            }
        }
    }

    public function getContentMapMarkers(): array
    {
        $output = [];

        if ($this->theme === 'uvp') {
            $this->page = $this->grav['uri']->query('page') ?: '';
            $searchSettings = $this->grav['config']->get('themes.' . $this->theme . '.map.leaflet.legend') ?? [];
            $facetConfig = $searchSettings['facet_config'] ?? [];
            $this->selectedFacets = $this->getSelectedFacets($facetConfig);

            $service = new SearchServiceImpl($this->grav, $this->grav['uri'], $facetConfig, $searchSettings);
            [$hits, $facets] = $service->getSearchResultsUnparsed('', $this->page, $this->selectedFacets, $this->grav['uri'], $this->lang);
            if ($hits) {
                $output = $this->getMapMarkers($hits);
            }
        }
        return $output;
    }

    public function getContentSearchMarkers(): array
    {
        $output = [];

        if ($this->theme === 'uvp') {
            $this->page = $this->grav['uri']->query('page') ?: '';
            $this->query = $this->grav['uri']->query('q') ?: '';
            $searchSettings = $this->grav['config']->get('themes.' . $this->theme . '.hit_search') ?: [];
            $facetConfig = $searchSettings['facet_config'] ?? [];
            $this->selectedFacets = $this->getSelectedFacets($facetConfig);

            $searchSettings['hits_num'] = 100;
            $service = new SearchServiceImpl($this->grav, $this->grav['uri'], $facetConfig, $searchSettings);
            [$hits, $facets] = $service->getSearchResultsUnparsed($this->query ?? '', $this->page, $this->selectedFacets, $this->grav['uri'], $this->lang);
            if ($hits) {
                $output = $this->getMapMarkers($hits);
            }
        }
        return $output;
    }

    public function getContentDownloadCSV(array &$output)
    {

        $searchSettings = $this->grav['config']->get('themes.' . $this->theme . '.hit_search') ?: [];
        $this->facetConfig = $this->facetConfig ?? $searchSettings['facet_config'];
        $this->addFacetCatalog($this->facetConfig);
        $this->selectedFacets = $this->getSelectedFacets($this->facetConfig);

        $searchSettings['hits_num'] = 100;
        $requestedFields = $_POST['requestedFields'];
        $codelists = $_POST['codelists'];
        $this->query = $_POST['query'] ?? '';
        if (!empty($requestedFields)) {
            $service = new SearchServiceImpl($this->grav, $this->grav['uri'], $this->facetConfig, $searchSettings);
            $page = 0;
            $doSearch = true;
            while ($doSearch) {
                $page = $page + 1;
                [$hits, $facets] = $service->getSearchResultsUnparsed($this->query, $page, $this->selectedFacets, $this->grav['uri'], $this->lang);
                if ($hits) {
                    $this->getSearchResultDownloadWithRequestedFields($hits, $requestedFields, $codelists, $this->lang, $facets, $output);
                } else {
                    $doSearch = false;
                }
            }
        }
        return $output;
    }
    private function addFacetsBySelection(array &$facetConfig): void
    {
        $queryParams = $this->grav['uri']->query(null, true);
        $extendedFacets = array_filter($facetConfig, function ($facet) {
            return isset($facet['extend_facet_selection_config']);
        });
        if (!empty($extendedFacets)) {
            foreach ($facetConfig as $facetKey => $facet) {
                if (isset($queryParams[$facet['id']])) {
                    $addFacet = $facet['extend_facet_selection_config'] ?? null;
                    if ($addFacet) {
                        $field = $addFacet['field'] ?? null;
                        if ($field === 'provider') {
                            $listLimit = $addFacet['list_limit'] ?? null;
                            $sort = $addFacet['sort'] ?? null;
                            $partners = CodelistHelper::getCodelistPartnerProviders();
                            $paramValues = array_reverse(explode(',', $queryParams[$facet['id']]));
                            foreach ($paramValues as $value) {
                                $items = array_filter($partners, function ($partner) use ($value) {
                                    return $partner['ident'] === $value;
                                });
                                foreach ($items as $item) {
                                    if ($item['ident'] == $value) {
                                        $providers = $item['providers'];
                                        $newFacets = [];
                                        foreach ($providers as $provider) {
                                            $newFacets[$provider['ident']] = array(
                                                "label" => $provider['name'],
                                                "query" => array(
                                                    "filter" => array(
                                                        "term" => array(
                                                            $field => $provider['ident']
                                                        )
                                                    )
                                                )
                                            );
                                        }
                                        if (!empty($newFacets)) {
                                            array_splice($facetConfig, $facetKey + 1, 0, array(
                                                array(
                                                    "id" => $value,
                                                    "label" => $item['name'],
                                                    "list_limit" => $listLimit,
                                                    "sort" => $sort,
                                                    "facets" => $newFacets
                                                )
                                            ));
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    private function getSelectedFacets(array &$facetConfig): array
    {
        $queryParams = $this->grav['uri']->query(null, true);
        foreach ($queryParams as $key => $param) {
            $list = array_filter($facetConfig, function ($facet) use ($key) {
                $found = $facet['id'] === $key;
                if (!$found and isset($facet['toggle'])) {
                    $toggle = $facet['toggle'];
                    $found = $toggle['id'] === $key;
                }
                return $found;
            });
            if (empty($list)) {
                unset($queryParams[$key]);
            }
        }
        $this->getSelectedFacetsFromConfig($facetConfig, $queryParams, null);
        return $queryParams;
    }

    public function getPagingUrl(mixed $uri): string
    {
        $url = "";
        $query_params = $uri->query(null, true);

        if (isset($query_params['more'])) {
            unset($query_params['more']);
        }
        if (isset($query_params['page'])) {
            unset($query_params['page']);
        }
        $query_string[] = http_build_query($query_params);

        $url .= '?' . join('&', $query_string);
        return $url;
    }

    public function getFacetResetActionUrl(mixed $uri): string
    {
        $query_params = $uri->query(null, true);
        $this->facetConfig = $this->facetConfig ?? $this->grav['config']->get('themes.' . $this->theme . '.hit_search.facet_config');
        foreach ($this->facetConfig as $facet) {
            $hasActive =  false;
            if ($facet['id'] === 'bbox') {
                unset($query_params[$facet['id']]);
            } elseif ($facet['id'] === 'timeref') {
                unset($query_params[$facet['id']]);
            } else {
                if (isset($facet['facets'])) {
                    foreach ($facet['facets'] as $subFacet) {
                        $hasActive = $subFacet['active'] ?? false;
                        if ($hasActive) {
                            break;
                        }
                    }
                    if ($hasActive) {
                        $query_params[$facet['id']] = '';
                    } else {
                        unset($query_params[$facet['id']]);
                    }
                }
                if (isset($facet['toggle'])) {
                    $hasActive = $facet['toggle']['active'] ?? false;
                    if ($hasActive) {
                        $query_params[$facet['toggle']['id']] = '';
                    } else {
                        unset($query_params[$facet['toggle']['id']]);
                    }
                }
            }
        }
        $query_string[] = http_build_query($query_params);

        return '?' . join('&', $query_string);
    }

    private function getSelectedFacetsFromConfig(array &$facets, array &$params, ?string $parentId): void
    {
        $values = [];
        foreach ($facets as $key => $facet) {
            $id = $key;
            if (isset($facet['id'])) {
                $id = $facet['id'];
            }

            if (isset($facet['toggle'])) {
                $toggle = $facet['toggle'];
                $toggleId = $toggle['id'];
                $isToggleActive = false;
                if (isset($params[$toggleId])) {
                    $isToggleActive = !empty($params[$toggleId]);
                } else {
                    $isToggleActive = $toggle['active'] ?? false;
                }
                $facets[$key]['toggle']['active'] = $isToggleActive;
                if (isset($toggle['active']) and $isToggleActive) {
                    if (!isset($params[$toggle['id']])) {
                        $params[$toggle['id']] = $parentId ?? $id;
                    }
                }
            }
            if ($parentId) {
                if (isset($facet['active']) and $facet['active']) {
                    if (!isset($params[$id])) {
                        $values[] = $id;
                    }
                }
            }

            if (isset($facet['facets'])) {
                $this->getSelectedFacetsFromConfig($facet['facets'], $params, $id);
            }
        }
        if ($parentId and !empty($values)) {
            if (!isset($params[$parentId])) {
                $params[$parentId] = implode(ElasticsearchService::$FACET_ENTRIES_SEPARATOR, $values);
            }
        }
    }

    private function getMapMarkers(array $hits): array
    {
        $items = [];
        foreach ($hits as $source) {

            $y1 = ElasticsearchHelper::getValue($source, 'y1');
            $x1 = ElasticsearchHelper::getValue($source, 'x1');
            $y2 = ElasticsearchHelper::getValue($source, 'y2');
            $x2 = ElasticsearchHelper::getValue($source, 'x2');
            $item = [];
            $item['title'] = ElasticsearchHelper::getValue($source, 'blp_name') ?? ElasticsearchHelper::getValue($source, 'title');
            $item['lat'] = ElasticsearchHelper::getValue($source, 'lat_center') ?? $y1;
            $item['lon'] = ElasticsearchHelper::getValue($source, 'lon_center') ?? $x1;
            $item['iplug'] = ElasticsearchHelper::getValue($source, 'iPlugId');
            $item['uuid'] = ElasticsearchHelper::getValue($source, 't01_object.obj_id');
            if (in_array('blp', ElasticsearchHelper::getValueArray($source, 'datatype'))) {
                $item['isBLP'] = true;
                $bbox = [];
                $bbox[] = [$y1, $x1];
                $bbox[] = [$y2, $x2];
                $item['bbox'] = $bbox;
                $item['bpInfos'] = [];
                $blpUrlFinished = ElasticsearchHelper::getValue($source, 'blp_url_finished');
                $blpUrlProgress = ElasticsearchHelper::getValue($source, 'blp_url_in_progress');
                $fnpUrlFinished = ElasticsearchHelper::getValue($source, 'fnp_url_finished');
                $fnpUrlProgress = ElasticsearchHelper::getValue($source, 'fnp_url_in_progress');
                $bpUrlFinished = ElasticsearchHelper::getValue($source, 'bp_url_finished');
                $bpUrlProgress = ElasticsearchHelper::getValue($source, 'bp_url_in_progress');

                if (!empty($blpUrlProgress)) {
                    $itemInfo = [];
                    $itemInfo["url"] = $blpUrlProgress;
                    $itemInfo["tags"] = "p";
                    $item['bpInfos'][] = $itemInfo;
                }
                if (!empty($blpUrlFinished)) {
                    $itemInfo = [];
                    $itemInfo["url"] = $blpUrlFinished;
                    $itemInfo["tags"] = "v";
                    $item['bpInfos'][] = $itemInfo;
                }
                if (!empty($fnpUrlProgress)) {
                    $itemInfo = [];
                    $itemInfo["url"] = $fnpUrlProgress;
                    $itemInfo["tags"] = "p";
                    $item['bpInfos'][] = $itemInfo;
                }
                if (!empty($fnpUrlFinished)) {
                    $itemInfo = [];
                    $itemInfo["url"] = $fnpUrlFinished;
                    $itemInfo["tags"] = "v";
                    $item['bpInfos'][] = $itemInfo;
                }
                if (!empty($bpUrlProgress)) {
                    $itemInfo = [];
                    $itemInfo["url"] = $bpUrlProgress;
                    $itemInfo["tags"] = "p";
                    $item['bpInfos'][] = $itemInfo;
                }
                if (!empty($bpUrlFinished)) {
                    $itemInfo = [];
                    $itemInfo["url"] = $bpUrlFinished;
                    $itemInfo["tags"] = "v";
                    $item['bpInfos'][] = $itemInfo;
                }
                $item['descr'] = ElasticsearchHelper::getValue($source, 'blp_description');
            } else {
                $bbox = [];
                $bbox[] = [$y1, $x1];
                $bbox[] = [$y2, $x2];
                $item['bbox'] = $bbox;
                $item['procedure'] = CodelistHelper::getCodelistEntry(['8001'], ElasticsearchHelper::getValue($source, 't01_object.obj_class'), 'de');
                $categories = ElasticsearchHelper::getValueArray($source, 'uvp_category');
                foreach ($categories as $category) {
                    $tmpArray = [];
                    $tmpArray['id'] = $category;
                    $tmpArray['name'] = $this->grav['language']->translate('SEARCH_RESULT.CATEGORIES_UVP_' . strtoupper($category));
                    $item['categories'][] = $tmpArray;
                }
                $steps = ElasticsearchHelper::getValueArray($source, 'uvp_steps');
                foreach ($steps as $step) {
                    $item['steps'][] = $this->grav['language']->translate('SEARCH_DETAIL.STEPS_UVP_' . strtoupper($step));
                }
            }
            $items[] = $item;
        }
        return $items;
    }

    private function getSearchResultDownloadWithRequestedFields(array $hits, array $requestedFields, array $codelists, string $lang, ?array $facetConfig, array &$items): void
    {
        foreach ($hits as $hit) {
            $source = $hit->_source;
            $item = [];
            foreach ($requestedFields as $field) {
                if (property_exists($source, $field)) {
                    $value = $source->$field;
                    if ($codelists) {
                        if (isset($codelists[$field])) {
                            $tmpValue = CodelistHelper::getCodelistEntry($codelists[$field], $value , $lang);
                            if ($tmpValue) {
                                $value = $tmpValue;
                            }
                        }
                    }
                    $item[$field] = $value;
                }
            }
            if (!empty($item)) {
                if (!empty($facetConfig) && !empty($this->selectedFacets)) {
                    $facets = [];
                    foreach ($facetConfig as $facet) {
                        foreach ($this->selectedFacets as $key => $value) {
                            if ($facet->id == $key) {
                                if (isset($facet->items)) {
                                    foreach ($facet->items as $subItem) {
                                        if (in_array($subItem->value, explode(';', $value))) {
                                            $labelKey = $this->grav['language']->translate($facet->label ?? $facet->id);
                                            $labelValue = $this->grav['language']->translate($subItem->label ?? $subItem->value);
                                            $facets[] = $labelKey . ' = ' . $labelValue;
                                        }
                                    }
                                }
                            }
                        }
                    }
                    if (!empty($facets)) {
                        $item['facets'] = $facets;
                    }
                }
                $items[] = $item;
            }
        }
    }

    public function isSortHitsEnable(): bool
    {
        $facetConfig = $this->grav['config']->get('themes.' . $this->theme . '.hit_search.facet_config') ?: [];
        foreach ($this->selectedFacets as $key => $param) {
            foreach ($facetConfig as $facet) {
                if ($facet['id'] === $key) {
                    if (isset($facet['display_sort_hits_on_selection']) && $facet['display_sort_hits_on_selection']) {
                        return true;
                    }
                    if (isset($facet['facets'])) {
                        foreach ($facet['facets'] as $subFacet) {
                            if (isset($subFacet['display_sort_hits_on_selection']) && $subFacet['display_sort_hits_on_selection']) {
                                return true;
                            }
                        }
                    }
                }
            }
        }
        return false;
    }

    private function addFacetCatalog(array &$facetConfig): void
    {
        foreach ($facetConfig as $key => $facet) {
            if (property_exists((object)$facet, 'catalog')) {
                $configApiUrlCatalog = $this->configApi . '/portal/catalogs';
                $catalog = new CatalogController($this->grav, $configApiUrlCatalog);
                $items = $catalog->getContent();
                if(!empty($items)) {
                    $catalogConfig = $facet['catalog'];
                    foreach ($items as $item) {
                        if ($catalogConfig['ident'] == $item['ident']) {
                            $partnerChildren = $item['children'];
                            foreach ($partnerChildren as $partnerChild) {
                                $catalogChildren = $partnerChild['children'];
                                foreach ($catalogChildren as $catalogChild) {
                                    $typeChildren = $catalogChild['children'];
                                    foreach ($typeChildren as $childKey => $typeChild) {
                                        $newLabel = $typeChild['name'];
                                        $newParentId = $facet['id'];
                                        $newChildId = $typeChild['id'];
                                        $facetConfig[$key]['facets'][$newChildId] = array(
                                            "label" => $newLabel,
                                            "query" => array("filter" => array("term" => array("object_node.tree_path.uuid" => $typeChild['uuid']))),
                                            "parentId" => $newParentId
                                        );
                                        if ($typeChild['hasChildren']) {
                                            $this->addFacetCatalogChild($facetConfig, $facet, $typeChild, $typeChild['partner'], $typeChild['ident']);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    private function addFacetCatalogChild(array &$facetConfig, array $facet, array $childFacet, string $partner, string $ident): void
    {
        $configApiUrlCatalog = $this->configApi . '/portal/catalogs';
        $newLabel = $childFacet['name'];
        $newParentId = $facet['id'] ?? $partner . '-' . substr(md5($ident . '-' . $facet['uuid']), 0, 8);
        $newChildId = $childFacet['id'] ?? $partner . '-' . substr(md5($ident . '-' . $childFacet['uuid']), 0, 8);
        $queryParams = $this->grav['uri']->query(null, true);
        if (isset($queryParams[$newParentId])) {
            if (in_array($newChildId, explode(ElasticsearchService::$FACET_ENTRIES_SEPARATOR, $queryParams[$newParentId])) !== false) {
                $catalog_api = $configApiUrlCatalog . '/' . $ident . '/hierarchy?parent=' . $childFacet['uuid'];
                if (($response = HttpHelper::getHttpContent($catalog_api)) !== false) {
                    $selectedSubItems = json_decode($response, true);
                    if (!empty($selectedSubItems)) {
                        $newFacets = [];
                        foreach ($selectedSubItems as $selectedSubItem) {
                            if ($selectedSubItem['hasChildren']) {
                                $newSubParentId = $newChildId;
                                $newSubChildId = $partner . '-' . substr(md5($ident . '-' . $selectedSubItem['uuid']), 0, 8);
                                $newFacets[$newSubChildId] = array(
                                    "label" => $selectedSubItem['name'],
                                    "query" => array("filter" => array("term" => array("object_node.tree_path.uuid" => $selectedSubItem['uuid']))),
                                    "parentId" => $newSubParentId
                                );
                            }
                        }
                        if (!empty($newFacets)) {
                            $facetConfig[] = array(
                                "id" => $newChildId,
                                "label" => $newLabel,
                                "facets" => $newFacets,
                            );
                            foreach ($selectedSubItems as $selectedSubItem) {
                                $this->addFacetCatalogChild($facetConfig, $childFacet, $selectedSubItem, $partner, $ident);
                            }
                        }
                    }
                }
            }
        }
    }
}