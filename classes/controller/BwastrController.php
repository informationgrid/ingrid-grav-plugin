<?php

namespace Grav\Plugin;

use Grav\Common\Grav;
use PHPUnit\Framework\Exception;

class BwastrController
{
    public Grav $grav;
    public string $epsg;
    public string $urlInfo;
    public string $urlGeok;
    public int $limit;

    public function __construct(Grav $grav)
    {
        $this->grav = $grav;

        $theme = $this->grav['config']->get('system.pages.theme');
        $config = $this->grav['config']->get('themes.' . $theme . '.bwastr');
        $this->epsg = $config['epsg'] ?? 4326;
        $this->urlInfo = $config['info'];
        $this->urlGeok = $config['geok'];
        $this->limit = $config['get_data_lower'];
    }

    public function getContent(): string
    {
        $uri = $this->grav['uri'];

        $id = $uri->query('id') ?? "";
        $from = $uri->query('from') ?? "";
        $to = $uri->query('to') ?? "";
        $resp = '{}';

        if (intval($id) and intval($id) < $this->limit) {
            if (empty($from) and empty($to)) {
                if (($response = HttpHelper::getFileContent($this->urlInfo . $id)) !== false) {
                    $info = json_decode($response, true);
                    $result = $info['result'];
                    foreach ($result as $item) {
                        $from = $item['km_von'];
                        $to = $item['km_bis'];
                    }
                }
            }
            $data = '{' .
                '"limit":200,' .
                '"queries":[' .
                    '{' .
                        '"qid":1,' .
                        '"bwastrid":"' . $id . '",' .
                        '"stationierung":{' .
                            '"km_von":' . $from . ',' .
                            '"km_bis":' . $to .
                        '},' .
                        '"spatialReference":{' .
                            '"wkid":' . $this->epsg .
                        '}' .
                    '}' .
                ']' .
                "}";
            if ($id and $data and !empty($this->urlGeok)) {
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json',
                ));
                curl_setopt($curl, CURLOPT_URL, $this->urlGeok);
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
                $resp = curl_exec($curl);
                $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                if ($httpcode !== 200) {
                    $resp = false;
                }
                curl_close($curl);
                if ($resp) {
                    $geok = json_decode($resp, true);
                    if (isset($geok['result'])) {
                        return json_encode($geok['result'][0]);
                    }
                }
            }
        }
        return $resp;
    }
}