<?php
/*
Copyright 2020 whatever127

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

$updateId = isset($_GET['id']) ? $_GET['id'] : 0;
$getPacks = isset($_GET['packs']) ? $_GET['packs'] : 0;

require_once 'api/listlangs.php';
require_once 'api/fetchupd.php';
require_once 'api/updateinfo.php';
require_once 'shared/style.php';
require_once 'shared/utils.php';
require_once dirname(__FILE__).'/sta/main.php';
require_once dirname(__FILE__).'/sta/genpack.php';

function uupApiPrivateEnsureBuildFileinfo($updateId, $updateInfo) {
    if(uupApiFileInfoExists($updateId)) {
        return true;
    }

    if(empty($updateInfo['build'])) {
        return false;
    }

    $params = [
        'arch' => isset($updateInfo['arch']) ? $updateInfo['arch'] : 'amd64',
        'ring' => isset($updateInfo['ring']) ? $updateInfo['ring'] : 'RETAIL',
        'flight' => isset($updateInfo['flight']) ? $updateInfo['flight'] : 'Active',
        'branch' => 'auto',
        'build' => $updateInfo['build'],
        'minor' => 0,
        'sku' => isset($updateInfo['sku']) ? intval($updateInfo['sku']) : 48,
        'type' => isset($updateInfo['releasetype']) ? $updateInfo['releasetype'] : 'Production',
        'flags' => isset($updateInfo['flags']) && is_array($updateInfo['flags']) ? $updateInfo['flags'] : [],
    ];

    $fetched = uupFetchUpd2($params, 1);
    if(!isset($fetched['error']) && uupApiFileInfoExists($updateId)) {
        return true;
    }

    return uupApiPrivateImportRemoteFileinfo($updateId, $updateInfo);
}

function uupApiPrivateResolvePackSourceId($updateId, $updateInfo) {
    if(empty($updateInfo['build'])) {
        return $updateId;
    }

    $builds = uupListIds($updateInfo['build']);
    if(isset($builds['error']) || empty($builds['builds'])) {
        return $updateId;
    }

    $arch = isset($updateInfo['arch']) ? $updateInfo['arch'] : null;
    $title = isset($updateInfo['title']) ? $updateInfo['title'] : '';

    $fallbackId = $updateId;
    $preferredSibling = null;
    $secondarySibling = null;

    foreach($builds['builds'] as $val) {
        if(!isset($val['uuid']) || !isset($val['title'])) {
            continue;
        }

        if($arch && isset($val['arch']) && $val['arch'] != $arch) {
            continue;
        }

        if($val['uuid'] == $updateId) {
            $fallbackId = $val['uuid'];
            continue;
        }

        if(preg_match('/Cumulative Update/i', $val['title'])) {
            return $val['uuid'];
        }

        if(uupApiPacksExist($val['uuid'])) {
            return $val['uuid'];
        }

        if(preg_match('/^Update for Windows|^Security Update for|^Update for Microsoft server operating system/i', $val['title'])) {
            $preferredSibling = $val['uuid'];
            continue;
        }

        if(preg_match('/^Feature update to/i', $val['title'])) {
            $secondarySibling = $val['uuid'];
        }
    }

    if(preg_match('/version\s+\d{2}H\d|version\s+\d+\.\d+/i', $title)) {
        if($preferredSibling) {
            return $preferredSibling;
        }

        if($secondarySibling) {
            return $secondarySibling;
        }
    }

    return $fallbackId;
}

function uupApiPrivateImportRemoteFileinfo($updateId, $updateInfo) {
    $url = 'https://uupdump.net/json-api/get.php?id='.rawurlencode($updateId);

    $req = curl_init($url);
    curl_setopt($req, CURLOPT_HEADER, 0);
    curl_setopt($req, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($req, CURLOPT_ENCODING, '');
    curl_setopt($req, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($req, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($req, CURLOPT_TIMEOUT, 25);
    curl_setopt($req, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($req, CURLOPT_HTTPHEADER, [
        'User-Agent: UUP dump self-host fileinfo import',
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

    if(isset($data['error']) || !isset($data['files']) || !is_array($data['files'])) {
        return false;
    }

    $files = [];
    $sha256ready = true;

    foreach($data['files'] as $name => $fileInfo) {
        if(!isset($fileInfo['sha1'])) {
            continue;
        }

        $sha1 = strtolower($fileInfo['sha1']);
        $sha256 = isset($fileInfo['sha256']) ? strtolower($fileInfo['sha256']) : null;
        if(!$sha256) {
            $sha256ready = false;
        }

        $files[$sha1] = [
            'name' => $name,
            'size' => isset($fileInfo['size']) ? intval($fileInfo['size']) : 0,
            'sha256' => $sha256,
        ];
    }

    if(empty($files)) {
        return false;
    }

    $build = isset($data['build']) ? $data['build'] : (isset($updateInfo['build']) ? $updateInfo['build'] : null);
    $checkBuild = $build;
    if($checkBuild && !preg_match('/^\d+\.\d+\.\d+\.\d+$/', $checkBuild)) {
        $checkBuild = '10.0.'.$checkBuild;
    }

    $fullInfo = [
        'title' => isset($data['updateName']) ? $data['updateName'] : (isset($updateInfo['title']) ? $updateInfo['title'] : 'Unknown update: '.$updateId),
        'ring' => isset($updateInfo['ring']) ? $updateInfo['ring'] : 'RETAIL',
        'flight' => isset($updateInfo['flight']) ? $updateInfo['flight'] : 'Active',
        'arch' => isset($data['arch']) ? $data['arch'] : (isset($updateInfo['arch']) ? $updateInfo['arch'] : 'amd64'),
        'fetchArch' => isset($data['arch']) ? $data['arch'] : (isset($updateInfo['arch']) ? $updateInfo['arch'] : 'amd64'),
        'build' => $build,
        'checkBuild' => $checkBuild,
        'sku' => isset($data['sku']) ? intval($data['sku']) : (isset($updateInfo['sku']) ? intval($updateInfo['sku']) : 48),
        'created' => isset($updateInfo['created']) ? intval($updateInfo['created']) : time(),
        'files' => $files,
    ];

    if($sha256ready) {
        $fullInfo['sha256ready'] = true;
    }

    if(preg_match('/Cumulative Update/i', $fullInfo['title'])) {
        $fullInfo['containsCU'] = 1;
    }

    uupApiWriteFileinfo($updateId, $fullInfo);
    return uupApiFileInfoExists($updateId);
}

function getLangs($updateId, $s) {
    $langs = uupListLangs($updateId);
    $langsTemp = array();

    foreach($langs['langList'] as $lang) {
        if(isset($s["lang_$lang"])) {
            $langsTemp[$lang] = $s["lang_$lang"];
        } else {
            $langsTemp[$lang] = $langs['langFancyNames'][$lang];
        }
    }

    $langs = $langsTemp;
    locasort($langs, $s['code']);

    return $langs;
}

if(!$updateId) {
    fancyError('UNSPECIFIED_UPDATE', 'downloads');
    die();
}

if(!checkUpdateIdValidity($updateId)) {
    fancyError('INCORRECT_ID', 'downloads');
    die();
}

$updateInfo = uupUpdateInfo($updateId, ignoreFiles: true);
$updateInfo = isset($updateInfo['info']) ? $updateInfo['info'] : array();

$packSourceId = uupApiPrivateResolvePackSourceId($updateId, $updateInfo);
if($packSourceId != $updateId) {
    $updateId = $packSourceId;
    $updateInfo = uupUpdateInfo($updateId, ignoreFiles: true);
    $updateInfo = isset($updateInfo['info']) ? $updateInfo['info'] : array();
}

if($getPacks || !uupApiPacksExist($updateId)) {
    uupApiPrivateEnsureBuildFileinfo($updateId, $updateInfo);
    generatePack($updateId);
    $updateInfo = uupUpdateInfo($updateId, ignoreFiles: true);
    $updateInfo = isset($updateInfo['info']) ? $updateInfo['info'] : array();
}

if(!isset($updateInfo['title'])) {
    $updateTitle = 'Unknown update: '.$updateId;
} else {
    $updateTitle = $updateInfo['title'];
}

if(!isset($updateInfo['arch'])) {
    $updateArch = '';
} else {
    $updateArch = $updateInfo['arch'];
}

if(!isset($updateInfo['build'])) {
    $build = $s['unknown'];
    $buildNum = false;
} else {
    $build = $updateInfo['build'];
    $buildNum = @explode('.', $build)[0];
}

if(!isset($updateInfo['ring'])) {
    $ring = null;
} else {
    $ring = $updateInfo['ring'];
}

if(!isset($updateInfo['flight'])) {
    $flight = null;
} else {
    $flight = $updateInfo['flight'];
}

if(!isset($updateInfo['created'])) {
    $created = null;
} else {
    $created = $updateInfo['created'];
}

$updateTitle = $updateTitle.' '.$updateArch;

$updateBlocked = isUpdateBlocked($buildNum, $updateTitle);
$langs = $updateBlocked ? [] : getLangs($updateId, $s);

if(in_array(strtolower($s['code']), array_keys($langs))) {
    $defaultLang = strtolower($s['code']);
} else {
    $defaultLang = 'en-us';
}

//Set fancy name for channel and flight of build
if($ring == 'CANARY' && $flight == 'Active') {
    $fancyChannelName = $s['channel_canary'];
} elseif($ring == 'WIF' && $flight == 'Skip') {
    $fancyChannelName = $s['channel_skipAhead'];
} elseif($ring == 'WIF' && $flight == 'Active') {
    $fancyChannelName = $s['channel_dev'];
} elseif($ring == 'WIS' && $flight == 'Active') {
    $fancyChannelName = $s['channel_beta'];
} elseif($ring == 'RP' && $flight == 'Current') {
    $fancyChannelName = $s['channel_releasepreview'];
} elseif($ring == 'RETAIL') {
    $fancyChannelName = $s['channel_retail'];
} else {
    if($ring && $flight) {
        $fancyChannelName = "$ring, $flight";
    } elseif($ring) {
        $fancyChannelName = "$ring";
    } else {
        $fancyChannelName = $s['unknown'];
    }
}

$findFilesUrl = "findfiles.php?id=".htmlentities($updateId);

$langsAvailable = count($langs) > 0;
$packsAvailable = uupApiPacksExist($updateId);

$noLangsIcon = 'times circle outline';
$noLangsCause = $s['updateIsBlocked'];
$generatePacksButton = false;

if(!$packsAvailable) {
    $noLangsIcon = 'industry';
    $noLangsCause = 'Metadata for this update is not generated.';
    $updateBlocked = true;
	$generatePacksButton = true;
} else if(!$updateBlocked && !$langsAvailable) {
    $noLangsIcon = 'info';
    $noLangsCause = $s['noLangsAvailable'];
    $updateBlocked = true;
}

$templateOk = true;

styleUpper('downloads', sprintf($s['selectLangFor'], $updateTitle));
require 'templates/selectlang.php';
styleLower();
