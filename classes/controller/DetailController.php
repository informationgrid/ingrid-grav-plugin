<?php

namespace Grav\Plugin;

use Grav\Common\Grav;
use GuzzleHttp\Client;
use RocketTheme\Toolbox\Event\Event;

class DetailController
{
    public Grav $grav;
    public $log;
    public bool $isDebug;
    public string $configApi;
    public string $lang;
    public string $uuid;
    public string $type;
    public string $cswUrl;
    public string $theme;
    public string $timezone;
    public null|DetailMetadataISO|DetailAddressISO|DetailMetadataHTML|DetailMetadataUVP|SearchHitOpendata $hit;
    public \stdClass $esHit;
    public array $partners;
    public string $title;

    public function __construct(Grav $grav, string $api)
    {
        $this->grav = $grav;
        $this->configApi = $api;
        $this->lang = $grav['language']->getLanguage();
        $this->uuid = $this->grav['uri']->query('docuuid') ?? '';
        $this->type = $this->grav['uri']->query('isAddress') ? 'address' : 'metadata';
        $this->cswUrl = $this->grav['uri']->query('cswUrl') ?? '';
        $this->theme = $this->grav['config']->get('system.pages.theme');
        $this->timezone = $this->grav['config']->get('system.timezone') ?: 'Europe/Berlin';
    }

    public function getContent(): void
    {
        $response = null;
        $dataSourceName = null;
        $providers = [];
        $esHit = null;

        if ($this->uuid) {
            $responseContent = $this->getResponseContent($this->configApi, $this->uuid, $this->type);
            if ($responseContent) {
                $hits = json_decode($responseContent)->hits;
                if (count($hits) > 0) {
                    $this->esHit = $hits[0];
                    if ($this->esHit) {
                        $dataSourceName = ElasticsearchHelper::getValue($this->esHit, 't03_catalogue.cat_name') ?? ElasticsearchHelper::getValue($this->esHit, 'dataSourceName');
                        $this->partners = ElasticsearchHelper::getValueArray($this->esHit, 'partner');
                        $tmpProviders = ElasticsearchHelper::getValueArray($this->esHit, 'provider');
                        $this->title = ElasticsearchHelper::getValue($this->esHit, 'title');
                        foreach ($tmpProviders as $provider) {
                            $providers[] = CodelistHelper::getCodelistEntryByIdent(['111'], $provider, $this->lang) ?? $provider;
                        }
                        $response = ElasticsearchHelper::getValue($this->esHit, 'idf');
                    }
                }
            }
        } else if ($this->cswUrl) {
            try {
                $response = HttpHelper::getHttpContent($this->cswUrl);
            } catch (\Exception $e) {
                DebugHelper::error('Error loading detail with cswUrl "' . $this->cswUrl . '": ' . $e->getMessage());
            }
        }

        if ($response) {
            $content = simplexml_load_string($response);
            IdfHelper::registerNamespaces($content);

            if ($this->type == "address") {
                if ($this->uuid) {
                    $parser = new DetailAddress($this->theme);
                    $this->hit = $parser->parse($content, $this->uuid);
                }
            } else {
                $parser = new DetailMetadata($this->theme);
                $this->hit = $parser->parse($content, $this->uuid, $dataSourceName, $providers);
                if (isset($this->hit)) {
                    $event = new Event([
                        'hit' => $this->hit,
                        'esHit' => $this->esHit,
                        'content' => $content,
                        'lang' => $this->lang,
                    ]);
                    $this->grav->fireEvent('onThemeDetailMetadataEvent', $event);
                    if (isset($this->hit->langCode) && $this->hit->langCode == 'en') {
                        $this->lang = $this->hit->langCode;
                    }
                }
            }
        } elseif (isset($this->esHit)) {
            $this->hit = SearchHitParserOpendata::parseHits($this->esHit, $this->lang);
        }
    }

    public function createContentZipOutput(): string
    {
        $output = '';
        $responseContent = $this->getResponseContent($this->configApi, $this->uuid, $this->type);
        if ($responseContent) {
            $hits = json_decode($responseContent)->hits;
            $response = null;
            $plugId = null;
            $title = null;
            if (count($hits) > 0) {
                $this->esHit = $hits[0];
                $response = ElasticsearchHelper::getValue($this->esHit, 'idf');
                $plugId = ElasticsearchHelper::getValue($this->esHit, 'iPlugId');
                $title = ElasticsearchHelper::getValue($this->esHit,'title');
            }
            if (!empty($response)) {
                $parser = new DetailCreateZipUVPServiceImpl('downloads/zip', $title, $this->uuid, $plugId, $this->grav);
                $content = simplexml_load_string($response);
                IdfHelper::registerNamespaces($content);
                [$fileUrl, $fileSize] = $parser->parse($content);
                $twig = $this->grav['twig'];
                // Use the @theme notation to reference the template in the theme
                $theme_path = $twig->addPath($this->grav['locator']->findResource('theme://templates'));
                $output = $twig->twig()->render($theme_path . '/_rest/detail/createZip.html.twig', [
                    'fileUrl' => $fileUrl,
                    'fileSize' => $fileSize,
                ]);
            }
        }
        return $output;
    }

    public function getContentZipOutput(): void {
        $paramUuid = $this->grav['uri']->query('uuid');
        $paramPlugId = $this->grav['uri']->query('plugid');
        try {
            $locator = $this->grav['locator'];
            $folderPath = $locator->findResource('user-data://', true);
            $dir = $folderPath . '/downloads/zip/' . $paramPlugId . '/' . $paramUuid;
            $dirFiles = scandir($dir);
            $filename = '';
            foreach ($dirFiles as $dirFile) {
                if (str_ends_with($dirFile, '.zip')) {
                    $filename = $dirFile;
                }
            }
            if (file_exists($dir . '/' . $filename)) {
                header('Content-Type: application/zip');
                header('Content-Length: ' . filesize($dir . '/' . $filename));
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                readfile($dir . '/' . $filename);
            }
        } catch (\Exception $e) {
            DebugHelper::error($paramUuid . ': ' .$e->getMessage());
        }
    }

    private function getResponseContent(string $api, string $uuid, string $type): ?string
    {
        try {
            $client = new Client(['base_uri' => $api]);
            return $client->request('POST', 'portal/search', [
                'body' => $this->transformQuery($uuid, $type)
            ])->getBody()->getContents();
        } catch (\Exception $e) {
            DebugHelper::error('Error loading detail with uuid "' . $uuid . '": ' . $e->getMessage());
        }
        return null;
    }

    private function transformQuery(string $uuid, string $type): string
    {
        $theme = $this->grav['config']->get('system.pages.theme');
        $searchSettings = $this->grav['config']->get('themes.' . $theme . '.hit_detail');
        $queryStringOperator = $searchSettings['query_string_operator'] ?? 'AND';
        $sourceInclude = $searchSettings['source']['include'] ?? [];
        $sourceExclude = $searchSettings['source']['exclude'] ?? [];
        $requestedFields = $searchSettings['requested_fields'] ?? [];

        $indexField = 't01_object.obj_id';
        $datatype = '-datatype:address';
        if ($type == 'address') {
            $indexField = 't02_address.adr_id';
            $datatype = 'datatype:address';
        }
        $queryString = array("query_string" => array (
                "query" => '(' . $indexField . ':"' . $uuid . '" OR id:"' . $uuid . '") ' . $datatype,
                "default_operator" => $queryStringOperator,
            )
        );
        $source = [];
        if (!empty($sourceInclude)
            || !empty($sourceExclude)) {
            if (!empty($sourceInclude)) {
                $source['include'] = $sourceInclude;
            }
            if (!empty($sourceExclude)){
                $source['exclude'] = $sourceExclude;
            }
        } else {
            $source = true;
        }
        $query = json_encode(array(
            "query" => $queryString,
            "fields" => $requestedFields,
            "_source" => $source
        ));
        DebugHelper::debug('Elasticsearch query detail: ' . $query);
        return $query;
    }

}