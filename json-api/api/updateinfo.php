<?php
/*
Copyright 2022 UUP dump API authors

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
require_once dirname(__FILE__).'/listid.php';

function uupApiPrivateBuildInfoFromKnownList($updateId) {
    $ids = uupListIds();

    if(isset($ids['error']) || empty($ids['builds'])) {
        return false;
    }

    foreach($ids['builds'] as $val) {
        if(!isset($val['uuid']) || $val['uuid'] != $updateId) {
            continue;
        }

        $title = isset($val['title']) ? $val['title'] : 'Unknown update: '.$updateId;
        $build = isset($val['build']) ? $val['build'] : null;
        $arch = isset($val['arch']) ? $val['arch'] : 'amd64';
        $created = isset($val['created']) ? $val['created'] : time();

        $ring = 'RETAIL';
        $flight = 'Active';

        if(preg_match('/Insider/i', $title)) {
            $ring = 'WIF';

            if(preg_match('/Beta/i', $title)) {
                $ring = 'WIS';
            } elseif(preg_match('/Release Preview|RP/i', $title)) {
                $ring = 'RP';
            }
        }

        $checkBuild = $build;
        if($checkBuild && !preg_match('/^\d+\.\d+\.\d+\.\d+$/', $checkBuild)) {
            $checkBuild = '10.0.'.$checkBuild;
        }

        return [
            'title' => $title,
            'build' => $build,
            'arch' => $arch,
            'checkBuild' => $checkBuild,
            'ring' => $ring,
            'flight' => $flight,
            'sku' => 48,
            'created' => $created,
        ];
    }

    return false;
}

function uupUpdateInfo($updateId, $onlyInfo = 0, $ignoreFiles = false) {
    $info = uupApiReadFileinfo($updateId, $ignoreFiles);

    if($info === false) {
        $info = uupApiPrivateBuildInfoFromKnownList($updateId);

        if($info !== false) {
            uupApiWriteFileinfoMeta($updateId, $info);
        }
    }

    if($info === false) {
        return ['error' => 'UPDATE_INFORMATION_NOT_EXISTS'];
    }

    $parsedInfo = uupParseUpdateInfo($info, $onlyInfo);
    if(isset($parsedInfo['error'])) {
        return $parsedInfo['error'];
    }

    return array(
        'apiVersion' => uupApiVersion(),
        'info' => $parsedInfo['info'],
    );
}

function uupParseUpdateInfo($info, $onlyInfo = 0) {
    if(empty($info)) {
        return ['error' => 'UPDATE_INFORMATION_NOT_EXISTS'];
    }

    if($onlyInfo) {
        if(isset($info[$onlyInfo])) {
            $returnInfo = $info[$onlyInfo];
        } else {
            return array('error' => 'KEY_NOT_EXISTS');
        }
    } else {
        $returnInfo = $info;
    }

    return array(
        'apiVersion' => uupApiVersion(),
        'info' => $returnInfo,
    );
}
