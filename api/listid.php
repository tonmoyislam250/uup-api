<?php
/*
Copyright 2019 whatever127

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

   http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
*/

require_once dirname(__FILE__).'/shared/main.php';
require_once dirname(__FILE__).'/shared/cache.php';
require_once dirname(__FILE__).'/shared/fileinfo.php';

function uupApiPrivateInvalidateFileinfoCache() {
    $cache1 = new UupDumpCache('listid-0', false);
    $cache2 = new UupDumpCache('listid-1', false);
    $cache3 = new UupDumpCache('listid-remote-0', false);
    $cache4 = new UupDumpCache('listid-remote-1', false);

    $cache1->delete();
    $cache2->delete();
    $cache3->delete();
    $cache4->delete();
}

function uupApiPrivateGetFromRemote($search = null, $sortByDate = 0) {
    $params = [
        'sortByDate' => $sortByDate ? 1 : 0,
    ];

    if($search !== null && $search !== '') {
        $params['search'] = $search;
    }

    $url = 'https://uupdump.net/json-api/listid.php?'.http_build_query($params);

    $req = curl_init($url);
    curl_setopt($req, CURLOPT_HEADER, 0);
    curl_setopt($req, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($req, CURLOPT_ENCODING, '');
    curl_setopt($req, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($req, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($req, CURLOPT_TIMEOUT, 15);
    curl_setopt($req, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($req, CURLOPT_HTTPHEADER, [
        'User-Agent: UUP dump self-host listid proxy',
        'Accept: application/json',
    ]);

    $out = curl_exec($req);
    $code = curl_getinfo($req, CURLINFO_RESPONSE_CODE);
    curl_close($req);

    if($out === false || $code !== 200) {
        return false;
    }

    $data = json_decode($out, true);
    if(!is_array($data)) {
        return false;
    }

    if(isset($data['response']) && is_array($data['response'])) {
        $data = $data['response'];
    }

    if(isset($data['error'])) {
        return ['error' => $data['error']];
    }

    if(!isset($data['builds']) || !is_array($data['builds'])) {
        return false;
    }

    return array_values($data['builds']);
}

function uupApiPrivateNormalizeKnownQuery($search) {
    if($search === null) {
        return null;
    }

    if(!preg_match('/^category:/i', $search)) {
        return $search;
    }

    $category = strtolower(preg_replace('/^category:/i', '', $search));

    $map = [
        'canary' => 'regex:Insider.*(2((2(?!000|6[2-4][1-9])\d{3})|(5(?!398)\d{3})|[6-9]\d{3}))\.[1-9]|([3-9]\d{4})\.[1-9]',
        'dev' => 'regex:Insider.*(2[3-4]\d{3}|260[5-9]\d|26[1-2]\d{2})\.[1-9]',
        'w11-26h2-dev' => 'regex:Insider.*263\d0',
        'w11-26h2' => 'regex:^(?:(?!Insider|Server|HCI).)*26300',
        'w11-26h1' => 'regex:^(?:(?!Insider).)*28000',
        'w11-25h2-beta' => 'regex:Insider.*262\d0',
        'w11-25h2' => 'regex:^(?:(?!Insider|Server|HCI).)*26200',
        'w11-24h2-beta' => 'regex:Insider.*2612\d',
        'w11-24h2' => 'regex:^(?:(?!Insider|Server|HCI).)*26100',
        'w11-23h2' => 'regex:^(?:(?!Insider).)*22631',
        'w11-22h2' => 'regex:^(?:(?!Insider).)*22621',
        'w11-21h2' => 'regex:^(?:(?!Insider).)*22000',
        'ws-24h2' => 'regex:(Server|HCI).*26100',
        'ws-23h2' => '25398',
        'ws-22h2' => '20349',
        'ws-21h2' => '20348',
        'w10-22h2' => '19045',
        'w10-21h2' => '19044',
        'w10-1809' => 'regex:^(?:(?!Insider).)*17763',
    ];

    return isset($map[$category]) ? $map[$category] : $search;
}

function uupApiPrivateGetFromFileinfo($sortByDate = 0) {
    $dirs = uupApiGetFileinfoDirs();
    $fileinfo = $dirs['fileinfoData'];
    $fileinfoRoot = $dirs['fileinfo'];

    $files = scandir($fileinfo);
    $files = preg_grep('/\.json$/', $files);

    consoleLogger('Parsing database info...');

    $cacheFile = $fileinfoRoot.'/cache.json';
    $cacheV2Version = 1;

    $database = uupApiReadJson($cacheFile);

    if(isset($database['version'])) {
        $version = $database['version'];
    } else {
        $version = 0;
    }

    if($version == $cacheV2Version && isset($database['database'])) {
        $database = $database['database'];
    } else {
        $database = array();
    }

    if(empty($database)) $database = array();

    $newDb = array();
    $builds = array();
    foreach($files as $file) {
        if($file == '.' || $file == '..')
            continue;

        $uuid = preg_replace('/\.json$/', '', $file);

        if(!isset($database[$uuid])) {
            $info = uupApiReadFileinfoMeta($uuid);

            $title = isset($info['title']) ? $info['title'] : 'UNKNOWN';
            $build = isset($info['build']) ? $info['build'] : 'UNKNOWN';
            $arch = isset($info['arch']) ? $info['arch'] : 'UNKNOWN';
            $created = isset($info['created']) ? $info['created'] : null;

            $temp = array(
                'title' => $title,
                'build' => $build,
                'arch' => $arch,
                'created' => $created,
            );

            $newDb[$uuid] = $temp;
        } else {
            $title = $database[$uuid]['title'];
            $build = $database[$uuid]['build'];
            $arch = $database[$uuid]['arch'];
            $created = $database[$uuid]['created'];

            $newDb[$uuid] = $database[$uuid];
        }

        $temp = array(
            'title' => $title,
            'build' => $build,
            'arch' => $arch,
            'created' => $created,
            'uuid' => $uuid,
        );

        $tmp = explode('.', $build);
        if(isset($tmp[1])) {
            $tmp[0] = str_pad($tmp[0], 10, '0', STR_PAD_LEFT);
            $tmp[1] = str_pad($tmp[1], 10, '0', STR_PAD_LEFT);
            $tmp = $tmp[0].$tmp[1];
        } else {
            consoleLogger($uuid.'.json appears to be broken and may be useless.');
            $tmp = 0;
        }

        if($sortByDate) {
            $tmp = $created.$tmp;
        }

        $buildAssoc[$tmp][] = $arch.$title.$uuid;
        $builds[$tmp.$arch.$title.$uuid] = $temp;
    }

    if(empty($buildAssoc)) return [];

    krsort($buildAssoc);
    $buildsNew = array();

    foreach($buildAssoc as $key => $val) {
        sort($val);
        foreach($val as $id) {
            $buildsNew[] = $builds[$key.$id];
        }
    }

    $builds = $buildsNew;
    consoleLogger('Done parsing database info.');

    if($newDb != $database) {
        if(!file_exists('cache')) mkdir('cache');

        $cacheData = array(
            'version' => $cacheV2Version,
            'database' => $newDb,
        );

        $success = @file_put_contents(
            $cacheFile,
            json_encode($cacheData)."\n"
        );

        if(!$success) consoleLogger('Failed to update database cache.');
    }

    return $builds;
}

function uupListIds($search = null, $sortByDate = 0) {
    uupApiPrintBrand();

    $search = uupApiPrivateNormalizeKnownQuery($search);
    $sortByDate = $sortByDate ? 1 : 0;

    $cache = null;
    $builds = false;
    $cached = false;

    if($search === null || $search === '') {
        $res = "listid-remote-$sortByDate";
        $cache = new UupDumpCache($res, false);
        $builds = $cache->get();
        $cached = ($builds !== false);
    }

    if(!$cached) {
        $builds = uupApiPrivateGetFromRemote($search, $sortByDate);
        if(isset($builds['error'])) {
            return $builds;
        }

        if($builds === false) {
            $builds = uupApiPrivateGetFromFileinfo($sortByDate);
            if($builds === false) return ['error' => 'NO_FILEINFO_DIR'];

            if(count($builds) && $search != null) {
                if(!preg_match('/^regex:/', $search)) {
                    $searchSafe = preg_quote($search, '/');

                    if(preg_match('/^".*"$/', $searchSafe)) {
                        $searchSafe = preg_replace('/^"|"$/', '', $searchSafe);
                    } else {
                        $searchSafe = str_replace(' ', '.*', $searchSafe);
                    }
                } else {
                    $searchSafe = preg_replace('/^regex:/', '', $search);
                }

                @preg_match("/$searchSafe/", "");
                if(preg_last_error()) {
                    return array('error' => 'SEARCH_NO_RESULTS');
                }

                foreach($builds as $key => $val) {
                    $buildString[$key] = $val['title'].' '.$val['build'].' '.$val['arch'];
                }

                $remove = preg_grep('/.*'.$searchSafe.'.*/i', $buildString, PREG_GREP_INVERT);
                $removeKeys = array_keys($remove);

                foreach($removeKeys as $value) {
                    unset($builds[$value]);
                }

                if(empty($builds)) {
                    return array('error' => 'SEARCH_NO_RESULTS');
                }

                unset($remove, $removeKeys, $buildString);
            }
        }

        if($cache !== null) {
            $cache->put($builds, 60);
        }
    }

    return array(
        'apiVersion' => uupApiVersion(),
        'builds' => $builds,
    );
}
