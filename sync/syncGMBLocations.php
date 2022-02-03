<?php


include_once(dirname(__DIR__) . '/GMB.php');

$GMB = new GMB();



$fp = fopen($GMB::LOCK_DIR  . "/syncGMB_lock.txt", "c+");
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    echo 'locked';
    exit;
}

$GMB->currentLogDirectory = $GMB->logsDirs['syncLocations'];

include_once($GMB::PHARMACIES_DIR . '/config/database.php');
include_once($GMB::PHARMACIES_DIR . '/objects/places.php');

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    $GMB->setLog(['error' => 'Can not connection to db'], '-dbError');
    exit;
}

if (!$GMB->checkAuth()) {
    $GMB->setLog(['error' => 'UNAUTHENTICATED in Google API'], '-UNAUTHENTICATED');
    exit;
}

$places = new Places($db);



$toDelete = [

];

$cannotUpdate = [];
$cannotToCreate = [];
$notFound = [];
$emptyStoreCode = [];

$placesPrev = [];


$locationListToSaveForLocalPosts = [];

$placesToDelete = [];
$placesToCreate = [];
$placesToUpdate = [];

$locations = [];
$getAllLocationFromGMB = true;

/*GET LOCATIONS FROM DB*/
$newPlaces = $places->getForGoogleMyBuziness();


/*GET LOCATIONS FROM GOOGLE*/
foreach ($GMB->accounts as $tm => $account) {
    $locationsGet = [];

    do {

        $query = [];
        if ($locationsGet['nextPageToken']) {
            $query['pageToken'] = $locationsGet['nextPageToken'];
        }

        $locationsGet = $GMB->gmbApiCall($account . '/locations', 'GET', $query);
        if ($locationsGet == 'UNAUTHENTICATED') {
            exit;
        }
        sleep(1);

        if ($locationsGet['HttpCode'] >= 400) $getAllLocationFromGMB = false;

        if (count($locationsGet['locations'])) {
            $locations = array_merge($locationsGet['locations'], $locations);
        }

    } while ($locationsGet['nextPageToken'] && $locationsGet['locations'] && count($locationsGet['locations']));

}

if (empty($locations)) {
    $GMB->setLog(['error' => 'Empty locations'], '-emptyLocations');
    exit;
}



/* STANDARTIZATION OF GET LOCATIONS PARAMETERS FROM GOOGLE TO DB PARAMETERS*/

foreach ($locations as $location) {
    $explodedLocationName = explode('/', $location['name']);
    $googleId = $explodedLocationName[3];

    $modifyLocation = [
        'account'            => $explodedLocationName[1],
        'name'               => $location['name'],
        'locationName'       => $location['locationName'],
        'storeCode'          => $location['storeCode'],
        'streetAddress'      => $location['address']['addressLines'][(count($location['address']['addressLines']) - 1)],
        'city'               => $location['address']['locality'],
        'administrativeArea' => $location['address']['administrativeArea'],
        'postalCode'         => $location['address']['postalCode'],
        'lat'                => $location['latlng']['latitude'],
        'lng'                => $location['latlng']['longitude'],
        'googleId'           => $googleId,
        'tm'                 => $location['locationName'],
        'phone'              => $location['primaryPhone'],
        'timeWork'           => []
    ];
    foreach ($location['regularHours']['periods'] as $period) {
        if ($period['openDay'] == 'MONDAY') {
            $modifyLocation['timeWork']['1'] = [
                'from' => $period['openTime'],
                'to'   => $period['closeTime']
            ];
        } elseif ($period['openDay'] == 'TUESDAY') {
            $modifyLocation['timeWork']['2'] = [
                'from' => $period['openTime'],
                'to'   => $period['closeTime']
            ];
        } elseif ($period['openDay'] == 'WEDNESDAY') {
            $modifyLocation['timeWork']['3'] = [
                'from' => $period['openTime'],
                'to'   => $period['closeTime']
            ];
        } elseif ($period['openDay'] == 'THURSDAY') {
            $modifyLocation['timeWork']['4'] = [
                'from' => $period['openTime'],
                'to'   => $period['closeTime']
            ];
        } elseif ($period['openDay'] == 'FRIDAY') {
            $modifyLocation['timeWork']['5'] = [
                'from' => $period['openTime'],
                'to'   => $period['closeTime']
            ];
        } elseif ($period['openDay'] == 'SATURDAY') {
            $modifyLocation['timeWork']['6'] = [
                'from' => $period['openTime'],
                'to'   => $period['closeTime']
            ];
        } elseif ($period['openDay'] == 'SUNDAY') {
            $modifyLocation['timeWork']['7'] = [
                'from' => $period['openTime'],
                'to'   => $period['closeTime']
            ];
        }
    }

    if (!$location['storeCode']) {
        $emptyStoreCode[] = $modifyLocation;
        $placesToDelete[] = $location['name'];
    } else {

        $locationListToSaveForLocalPosts[$location['storeCode']] = [
            'city'    => $location['address']['locality'],
            'name'    => $location['name'],
            'account' => $explodedLocationName[1]
        ];


        $placesPrev[$location['storeCode']] = $modifyLocation;
    }

}



/*SAVE LIST OF ALL LOCATIONS TO USE IN LOCAL POSTS ON GOOGLE MB*/
$getLocationListForLocalPosts = json_decode(file_get_contents($GMB::GMB_DIR . '/allLocations.json'), 1);
if (empty($getLocationListForLocalPosts)) {
    file_put_contents($GMB::GMB_DIR . '/allLocations.json', json_encode($locationListToSaveForLocalPosts, JSON_UNESCAPED_UNICODE));
} else {
    $locationListToSaveForLocalPosts = $getLocationListForLocalPosts + $locationListToSaveForLocalPosts;
    file_put_contents($GMB::GMB_DIR . '/allLocations.json', json_encode($locationListToSaveForLocalPosts, JSON_UNESCAPED_UNICODE));
}


/*CLEAR*/
$locationListToSaveForLocalPosts = '';
$getLocationListForLocalPosts = '';



foreach ($newPlaces as $newPlace) {

    /*FIX AND CHANGE SOME PARAMETERS*/
    $newPlace['primaryPhone'] = 'some phone';

    $exploded = explode(':', $newPlace['timeWork']['5']['from']);
    if (strlen($exploded[0]) && (strlen($exploded[0]) < 2)) $newPlace['timeWork']['5']['from'] = '0' . $newPlace['timeWork']['5']['from'];
    $newPlace['timeWork']['5']['from'] = substr($newPlace['timeWork']['5']['from'], 0, 5);
    $exploded = explode(':', $newPlace['timeWork']['5']['to']);
    if (strlen($exploded[0]) && (strlen($exploded[0]) < 2)) $newPlace['timeWork']['5']['to'] = '0' . $newPlace['timeWork']['5']['to'];
    $newPlace['timeWork']['5']['to'] = substr($newPlace['timeWork']['5']['to'], 0, 5);
    if ($newPlace['timeWork']['5']['to'] == '23:59') $newPlace['timeWork']['5']['to'] = '24:00';

    $exploded = explode(':', $newPlace['timeWork']['6']['from']);
    if (strlen($exploded[0]) && (strlen($exploded[0]) < 2)) $newPlace['timeWork']['6']['from'] = '0' . $newPlace['timeWork']['6']['from'];
    $newPlace['timeWork']['6']['from'] = substr($newPlace['timeWork']['6']['from'], 0, 5);
    $exploded = explode(':', $newPlace['timeWork']['6']['to']);
    if (strlen($exploded[0]) && (strlen($exploded[0]) < 2)) $newPlace['timeWork']['6']['to'] = '0' . $newPlace['timeWork']['6']['to'];
    $newPlace['timeWork']['6']['to'] = substr($newPlace['timeWork']['6']['to'], 0, 5);
    if ($newPlace['timeWork']['6']['to'] == '23:59') $newPlace['timeWork']['6']['to'] = '24:00';

    $exploded = explode(':', $newPlace['timeWork']['7']['from']);
    if (strlen($exploded[0]) && (strlen($exploded[0]) < 2)) $newPlace['timeWork']['7']['from'] = '0' . $newPlace['timeWork']['7']['from'];
    $newPlace['timeWork']['7']['from'] = substr($newPlace['timeWork']['7']['from'], 0, 5);
    $exploded = explode(':', $newPlace['timeWork']['7']['to']);
    if (strlen($exploded[0]) && (strlen($exploded[0]) < 2)) $newPlace['timeWork']['7']['to'] = '0' . $newPlace['timeWork']['7']['to'];
    $newPlace['timeWork']['7']['to'] = substr($newPlace['timeWork']['7']['to'], 0, 5);
    if ($newPlace['timeWork']['7']['to'] == '23:59') $newPlace['timeWork']['7']['to'] = '24:00';



    $newPlace['lat'] = substr($newPlace['lat'], 0, 7);
    $newPlace['lng'] = substr($newPlace['lng'], 0, 7);

    $newPlace['account'] = $GMB->accounts[$newPlace['tm']];
    $newPlace['path'] = $newPlace['account'] . '/locations';
    $newPlace['locationName'] = $newPlace['tm'];

    if ($placesPrev[$newPlace['storeCode']] && ($newPlace['account'] != 'accounts/' . $placesPrev[$newPlace['storeCode']]['account'])) {
        $placesToDelete[] = $placesPrev[$newPlace['storeCode']]['name'];
        unset($placesPrev[$newPlace['storeCode']]);
    }


    /*CHECK PLACE IS NEED TO CREATE OR UPDATE*/

    if (!$placesPrev[$newPlace['storeCode']]) {

        /*CHECK EXISTANCE (REQUIRED) DATA NEEDED TO CREATE IN FIELD ADDRESS*/
        if (!$newPlace['postalCode'] || !$newPlace['city'] || !$newPlace['administrativeArea'] || (strlen($newPlace['streetAddress']) < 5)) {
            $cannotToCreate[] = $newPlace;
            continue;
        }
        else {
            /*GENERATE OBJECT TO CREATE*/
            $toCreate = [
                'body'  => [
                    'locationName'         => $newPlace['tm'],
                    'primaryPhone'         => $newPlace['primaryPhone'],
                    'regularHours'         => [
                        'periods' => []
                    ],
                    'latlng'               => [
                        'latitude'  => $newPlace['lat'],
                        'longitude' => $newPlace['lng']
                    ],
                    'languageCode'         => $GMB->languageCode,
                    'address'              => [
                        'postalCode'   => $newPlace['postalCode'],
                        'languageCode' => $GMB->languageCode,
                        'regionCode'   => $GMB->regionCode,
                        'locality'     => $newPlace['city'],
                        'administrativeArea' => $newPlace['administrativeArea'],
                        'addressLines' => [$newPlace['streetAddress']]
                    ],
                    'profile'              => [
                        'description' => $GMB->prifileDescription
                    ],
                    'primaryCategory'      => $GMB->primaryCategory,
                    'additionalCategories' => $GMB->additionalCategories,
                    'storeCode'            => $newPlace['storeCode'],
                    'websiteUrl'           => $GMB->websiteUrl
                ],
                'path'  => $newPlace['path'],
                'photo' => $newPlace['photo']
            ];

            if ($newPlace['timeWork']['5']['from'] && $newPlace['timeWork']['5']['to']) {
                $toCreate['body']['regularHours']['periods'] = [
                    [
                        'openDay'   => 'MONDAY',
                        'closeDay'  => 'MONDAY',
                        'openTime'  => $newPlace['timeWork']['5']['from'],
                        'closeTime' => $newPlace['timeWork']['5']['to']
                    ],
                    [
                        'openDay'   => 'TUESDAY',
                        'closeDay'  => 'TUESDAY',
                        'openTime'  => $newPlace['timeWork']['5']['from'],
                        'closeTime' => $newPlace['timeWork']['5']['to']
                    ],
                    [
                        'openDay'   => 'WEDNESDAY',
                        'closeDay'  => 'WEDNESDAY',
                        'openTime'  => $newPlace['timeWork']['5']['from'],
                        'closeTime' => $newPlace['timeWork']['5']['to']
                    ],
                    [
                        'openDay'   => 'THURSDAY',
                        'closeDay'  => 'THURSDAY',
                        'openTime'  => $newPlace['timeWork']['5']['from'],
                        'closeTime' => $newPlace['timeWork']['5']['to']
                    ],
                    [
                        'openDay'   => 'FRIDAY',
                        'closeDay'  => 'FRIDAY',
                        'openTime'  => $newPlace['timeWork']['5']['from'],
                        'closeTime' => $newPlace['timeWork']['5']['to']
                    ],
                ];
            }
            if ($newPlace['timeWork']['6']['from'] && $newPlace['timeWork']['6']['to']) {
                $toCreate['body']['regularHours']['periods'][] = [
                    'openDay'   => 'SATURDAY',
                    'closeDay'  => 'SATURDAY',
                    'openTime'  => $newPlace['timeWork']['6']['from'],
                    'closeTime' => $newPlace['timeWork']['6']['to']
                ];
            }
            if ($newPlace['timeWork']['7']['from'] && $newPlace['timeWork']['7']['to']) {
                $toCreate['body']['regularHours']['periods'][] = [
                    'openDay'   => 'SUNDAY',
                    'closeDay'  => 'SUNDAY',
                    'openTime'  => $newPlace['timeWork']['7']['from'],
                    'closeTime' => $newPlace['timeWork']['7']['to']
                ];
            }

            $placesToCreate[] = $toCreate;
        }



    } else {
        $prevPlace = $placesPrev[$newPlace['storeCode']];
        $newPlace['name'] = $prevPlace['name'];


        /*CHECK AND GENERATE OBJECT TO UPDATE*/
        $updateMask = [];
        $updateFields = [];
        $changeAddress = false;

        if ($prevPlace['locationName'] != $newPlace['locationName']) {
            $updateMask[] = 'locationName';
            $updateFields['locationName'] = $newPlace['locationName'];
        }

        if ($newPlace['lat'] && $newPlace['lng'] &&
            (($prevPlace['lat'] != $newPlace['lat']) || ($prevPlace['lng'] != $newPlace['lng']))) {
            $updateMask[] = 'latlng.latitude';
            $updateMask[] = 'latlng.longitude';
            $updateFields['latlng']['latitude'] = $newPlace['lat'];
            $updateFields['latlng']['longitude'] = $newPlace['lng'];
        }

        if ($newPlace['postalCode'] && ($prevPlace['postalCode'] != $newPlace['postalCode'])) {
            $changeAddress = true;
            $updateMask[] = 'address.postalCode';
            $updateFields['address']['postalCode'] = $newPlace['postalCode'];
        }
        if ($newPlace['administrativeArea'] && ($prevPlace['administrativeArea'] != $newPlace['administrativeArea'])) {
            $changeAddress = true;
            $updateMask[] = 'address.administrativeArea';
            $updateFields['address']['administrativeArea'] = $newPlace['administrativeArea'];
        }
        if ($newPlace['city'] && ($prevPlace['city'] != $newPlace['city'])) {
            $changeAddress = true;
            $updateMask[] = 'address.locality';
            $updateFields['address']['locality'] = $newPlace['city'];
        }
        if ((strlen($newPlace['streetAddress']) > 5) && ($prevPlace['streetAddress'] != $newPlace['streetAddress'])) {
            $changeAddress = true;
            $updateMask[] = 'address.addressLines';
            $updateFields['address']['addressLines'][] = $newPlace['streetAddress'];
        }
        if (($prevPlace['timeWork']['1'] != $newPlace['timeWork']['5']) ||
            ($prevPlace['timeWork']['2'] != $newPlace['timeWork']['5']) ||
            ($prevPlace['timeWork']['3'] != $newPlace['timeWork']['5']) ||
            ($prevPlace['timeWork']['4'] != $newPlace['timeWork']['5']) ||
            ($prevPlace['timeWork']['5'] != $newPlace['timeWork']['5']) ||
            ($prevPlace['timeWork']['6'] != $newPlace['timeWork']['6']) ||
            ($prevPlace['timeWork']['7'] != $newPlace['timeWork']['7'])) {
            $updateMask[] = 'regularHours.periods';
            $updateFields['regularHours']['periods'] = [];
            if ($newPlace['timeWork']['5']['from'] && $newPlace['timeWork']['5']['to']) {
                $updateFields['regularHours']['periods'][] = [
                    'openDay'   => 'MONDAY',
                    'closeDay'  => 'MONDAY',
                    'openTime'  => $newPlace['timeWork']['5']['from'],
                    'closeTime' => $newPlace['timeWork']['5']['to']
                ];
                $updateFields['regularHours']['periods'][] = [
                    'openDay'   => 'TUESDAY',
                    'closeDay'  => 'TUESDAY',
                    'openTime'  => $newPlace['timeWork']['5']['from'],
                    'closeTime' => $newPlace['timeWork']['5']['to']
                ];
                $updateFields['regularHours']['periods'][] = [
                    'openDay'   => 'WEDNESDAY',
                    'closeDay'  => 'WEDNESDAY',
                    'openTime'  => $newPlace['timeWork']['5']['from'],
                    'closeTime' => $newPlace['timeWork']['5']['to']
                ];
                $updateFields['regularHours']['periods'][] = [
                    'openDay'   => 'THURSDAY',
                    'closeDay'  => 'THURSDAY',
                    'openTime'  => $newPlace['timeWork']['5']['from'],
                    'closeTime' => $newPlace['timeWork']['5']['to']
                ];
                $updateFields['regularHours']['periods'][] = [
                    'openDay'   => 'FRIDAY',
                    'closeDay'  => 'FRIDAY',
                    'openTime'  => $newPlace['timeWork']['5']['from'],
                    'closeTime' => $newPlace['timeWork']['5']['to']
                ];
            }
            if ($newPlace['timeWork']['6']['from'] && $newPlace['timeWork']['6']['to']) {
                $updateFields['regularHours']['periods'][] = [
                    'openDay'   => 'SATURDAY',
                    'closeDay'  => 'SATURDAY',
                    'openTime'  => $newPlace['timeWork']['6']['from'],
                    'closeTime' => $newPlace['timeWork']['6']['to']
                ];
            }
            if ($newPlace['timeWork']['7']['from'] && $newPlace['timeWork']['7']['to']) {
                $updateFields['regularHours']['periods'][] = [
                    'openDay'   => 'SUNDAY',
                    'closeDay'  => 'SUNDAY',
                    'openTime'  => $newPlace['timeWork']['7']['from'],
                    'closeTime' => $newPlace['timeWork']['7']['to']
                ];
            }
        }
        /*Error from Google - This field cannot be updated at this time*/
        //if ($newPlace['primaryPhone'] && (preg_replace('/\D/', '', $prevPlace['primaryPhone']) != preg_replace('/\D/', '', $newPlace['primaryPhone']))) {
        //    $updateMask[] = 'primaryPhone';
        //    $updateFields['primaryPhone'] = $newPlace['primaryPhone'];
        //}

        if (!empty($updateFields)) {
            if ($changeAddress) {

                /*CHECK EXISTANCE (REQUIRED) DATA NEEDED TO UPDATE ADDRESS*/
                if (!$newPlace['postalCode'] || !$newPlace['city'] || !$newPlace['administrativeArea'] || (strlen($newPlace['streetAddress']) < 5)) {
                    $cannotUpdate[] = [
                        'query' => implode(',', $updateMask),
                        'body'  => $updateFields,
                        'path'  => $newPlace['name']
                    ];
                    continue;
                }

                $updateFields['address']['region_code'] = $GMB->regionCode;
                if (!$updateFields['address']['postalCode']) $updateFields['address']['postalCode'] = $newPlace['postalCode'] ? $newPlace['postalCode'] : $prevPlace['postalCode'];
                if (!$updateFields['address']['administrativeArea'])  $updateFields['address']['administrativeArea'] = $newPlace['administrativeArea'];
                if (!$updateFields['address']['locality']) $updateFields['address']['locality'] = $newPlace['city'];
                if (!$updateFields['latlng']['latitude']) {
                    $updateFields['latlng']['latitude'] = $newPlace['lat'];
                    $updateMask[] = 'latlng.latitude';
                }
                if (!$updateFields['latlng']['longitude']) {
                    $updateFields['latlng']['longitude'] = $newPlace['lng'];
                    $updateMask[] = 'latlng.longitude';
                }
                if (!$updateFields['address']['addressLines']) $updateFields['address']['addressLines'][] = $newPlace['streetAddress'];


            }

            $updateMask = implode(',', $updateMask);
            $placesToUpdate[] = [
                'query' => $updateMask,
                'body'  => $updateFields,
                'path'  => $newPlace['name']
            ];
        }
    }


}


/*CHECK AND GENERATE OBJECT TO DELETE*/
foreach ($placesPrev as $code => $prevPlace) {
    $updateMask = [];
    $updateFields = [];
    if (!$newPlaces[$prevPlace['storeCode']]) {
        $notFound[] = $prevPlace;

        $placesToDelete[] = $prevPlace['name'];

    }
    if (!$prevPlace['storeCode']) {
        $placesToDelete[] = $prevPlace['name'];
    }
}





/*-----------------------MAKE CHANGES ON GOOGLE MB-----------------------*/


/*DELETE*/
foreach ($placesToDelete as $delName) {
    $deleted[] = $GMB->deleteLocation($delName);
    usleep(200000);
}

$newMediaForPost = [];
/*CREATE*/
if ($getAllLocationFromGMB) {
    $placesToCreate2 = array_slice($placesToCreate, 0, 100);
    foreach ($placesToCreate2 as $createPlace) {

        $res = $GMB->createLocation($createPlace['path'], $createPlace['body']);

        $resMedia = [];
        if ($res['name']) {
            $newMediaForPost[] = ['path' => $res['name'] . '/media', 'storeCode' => $createPlace['body']['storeCode']];
        }
        $created[] = ['createResult' => $res, 'mediaResult' => $resMedia];
        sleep(2);
    }
}


/*UPDATE*/
foreach ($placesToUpdate as $updatePlace) {
    $updated[] = $GMB->updateLocation($updatePlace['path'], $updatePlace['query'], $updatePlace['body']);
    sleep(1);
}



/*CREATE MEDIA*/

$oldMediaForPost = json_decode(file_get_contents($GMB::GMB_DIR . '/sync/mediaForPost.txt'), 1);
$allMediaForPost = array_merge($oldMediaForPost, $newMediaForPost);
$deniedMediaPost = [];
$addMedia = [];
foreach ($allMediaForPost as $media) {
    $media['photo'] = $places->getPhotoByStoreCode($media['storeCode']);
    $resultMediaPost = [];
    if ($media['photo']) {
        $resultMediaPost = $GMB->postLocationMedia($media['path'], $media['photo']);
        if (!$resultMediaPost['name']) {
            $deniedMediaPost[] = $media;
        }
    }
    else $deniedMediaPost[] = $media;
    $addMedia[] = ['media' => $media, 'result' => $resultMediaPost];


}

file_put_contents($GMB::GMB_DIR . '/sync/mediaForPost.txt', json_encode($deniedMediaPost));



/*-----------------------------*/



/*------------SAVE RESULTS TO LOGS-------------------------*/

$syncLogsDir = $GMB->logsDirs['syncLocations'] . date('/Y-m-d');

if (!file_exists($syncLogsDir)) {
    @mkdir($syncLogsDir, 0777, true);
}

file_put_contents($syncLogsDir . '/placesToDelete.txt',
                  date('Y-m-d H:i:s') . print_r($placesToDelete, 1) . "\n\n============================\n\n",
                  FILE_APPEND);
file_put_contents($syncLogsDir . '/placesToUpdate.txt',
                  date('Y-m-d H:i:s') . print_r($placesToUpdate, 1) . "\n\n============================\n\n",
                  FILE_APPEND);
file_put_contents($syncLogsDir . '/placesToCreate.txt',
                  date('Y-m-d H:i:s') . print_r($placesToCreate, 1) . "\n\n============================\n\n",
                  FILE_APPEND);
file_put_contents($syncLogsDir . '/placesToCreate2.txt',
                  date('Y-m-d H:i:s') . print_r($placesToCreate2, 1) . "\n\n============================\n\n",
                  FILE_APPEND);
file_put_contents($syncLogsDir . '/syncDataLog_updated.txt',
                  date('Y-m-d H:i:s') . print_r($updated, 1) . "\n\n============================\n\n",
                  FILE_APPEND);
file_put_contents($syncLogsDir . '/syncDataLog_created.txt',
                  date('Y-m-d H:i:s') . print_r($created, 1) . "\n\n============================\n\n",
                  FILE_APPEND);
file_put_contents($syncLogsDir . '/syncDataLog_deleted.txt',
                  date('Y-m-d H:i:s') . print_r($deleted, 1) . "\n\n============================\n\n",
                  FILE_APPEND);

file_put_contents($syncLogsDir . '/syncDataLog_addMedia.txt',
                  date('Y-m-d H:i:s') . print_r($addMedia, 1) . "\n\n============================\n\n",
                  FILE_APPEND);

file_put_contents($syncLogsDir . '/cannotUpdate.txt',
                  date('Y-m-d H:i:s') . print_r($cannotUpdate, 1) . "\n\n============================\n\n",
                  FILE_APPEND);

file_put_contents($syncLogsDir . '/cannotCreate.txt',
                  date('Y-m-d H:i:s') . print_r($cannotToCreate, 1) . "\n\n============================\n\n",
                  FILE_APPEND);


file_put_contents($syncLogsDir . '/notFoundLog.txt', print_r($notFound, 1));
file_put_contents($syncLogsDir . '/emptyStoreCode.txt', print_r($emptyStoreCode, 1));

//$toSave = [];
//foreach ($notFound as $place) {
//    $toSave[] = [
//        'storeCode' => $place['storeCode'],
//        'address'   => $place['administrativeArea'] . ' ' . $place['city'] . ' ' . $place['streetAddress'],
//        'tm'        => $place['tm'],
//        //'emptyStoreCode' => $emptyStoreCode
//    ];
//}
//$tableFolder = __DIR__ . '/notFoundTable.xls';
//require 'createTable.php';

