<?php

include_once(dirname(__DIR__) . '/GMB.php');

$GMB = new GMB();

$fp = fopen($GMB::LOCK_DIR  . "/getReviews_lock.txt", "c+");
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    echo 'locked';
    exit;
}

$GMB->currentLogDirectory = $GMB->logsDirs['reviews'];

include_once($GMB::PHARMACIES_DIR . '/config/database.php');
include_once($GMB::PHARMACIES_DIR . '/objects/reviews.php');

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

$reviewGMB = new ReviewGMB($db);



$reviewsToSave = [];
$reviews = [];

$today = date('Y-m-d');
$day1ago = date('Y-m-d', strtotime('-1 days'));
$day2ago = date('Y-m-d', strtotime('-2 days'));

$getLocationList = json_decode(file_get_contents($GMB::GMB_DIR . '/allLocations.json'), 1);
$accountLocationList = [];
foreach ($getLocationList as $id => $locationData) {
    $accountLocationList[$locationData['account']][] = $locationData['name'];
}
$getLocationList = '';

foreach ($accountLocationList as $account => $locationList) {

    $locationsGet = [];
    $locations = [];
    $placesCheck = [];
    $reviewsGet = [];
    $chunkedLocationList = array_chunk($locationList, 15, true);
    foreach ($chunkedLocationList as $chunkedElementList) {

        $chunkedElementList = array_values($chunkedElementList);
        do {
            $query = [];
            if ($reviewsGet['nextPageToken']) {
                $query['pageToken'] = $reviewsGet['nextPageToken'];
            }
            $reviewsGet = $GMB->gmbApiCall('accounts/' . $account . '/locations:batchGetReviews',
                'POST', $query, ['locationNames' => $chunkedElementList]);
            sleep(1);
            if (count($reviewsGet['locationReviews'])) {
                foreach ($reviewsGet['locationReviews'] as $oneReview) {
                    if (in_array(date('Y-m-d', strtotime($oneReview['review']['createTime'])), [$today, $day1ago, $day2ago])) {
                        if (!$oneReview['review']['comment']) {
                            $oneReview['review']['comment'] = '';
                        } else {
                            $explodedComment = explode('(Übersetzt von Google)', $oneReview['review']['comment']);
                            $oneReview['review']['comment'] = trim($explodedComment[0]);
                        }
                        $oneReview['createTime'] = date('Y-m-d H:i:s', strtotime($oneReview['review']['createTime']));
                        $oneReview['updateTime'] = date('Y-m-d H:i:s', strtotime($oneReview['review']['updateTime']));
                        $locationReview = $GMB->gmbApiCall($oneReview['name'], 'GET');
                        $oneReview['address'] = $locationReview['locationName'];
                        $oneReview['address'] .= $locationReview['address']['administrativeArea'] ? (', ' . $locationReview['address']['administrativeArea']) : '';
                        $oneReview['address'] .= ' ' . $locationReview['address']['locality'];
                        $oneReview['address'] .= ', ' . $locationReview['address']['addressLines'][(count($locationReview['address']['addressLines']) - 1)];
                        $reviews[] = $oneReview;
                    }

                }

            }
        } while ($reviewsGet['nextPageToken'] && count($reviewsGet['locationReviews']));

    }

}




if (count($reviews)) {
    $updated = $reviewGMB->insertReviews($reviews);
}

$syncLogsDir = $GMB->logsDirs['reviews'] . date('/Y-m-d');

if (!file_exists($syncLogsDir)) {
    @mkdir($syncLogsDir, 0777, true);
}
file_put_contents($syncLogsDir . '/reviewsSavingLog.txt',
                  date('Y-m-d H:i:s') . print_r(['updated' => $updated, 'countReviews' => count($reviews), 'reviews' => $reviews], 1) . "\n\n============================\n\n",
                  FILE_APPEND);
