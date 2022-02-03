<?php


require (__DIR__ . '/GMB.php');

$GMB = new GMB();

if (!file_exists($GMB::AUTH_LOGS_DIR . date('/Y-m-d'))) {
    @mkdir($GMB::AUTH_LOGS_DIR . date('/Y-m-d'), 0777, true);
}
file_put_contents($GMB::AUTH_LOGS_DIR. date('/Y-m-d') . '/requestAuth2Log.txt', date('Y-m-d H:i:s') . "\n" . print_r($_REQUEST, 1) . "\n", FILE_APPEND);


if ($_REQUEST['access_token']) {
    echo 'get request';
    exit;
}

$result = $GMB->reAuth2($_REQUEST['code']);

if ($result) {
    echo 'Успешная повторная авторизация!';
    exit;
}

echo 'Произошла ошибка при повторной авторизации <br><br>Повторите ее, пожалкйста, перейдя по ссылке <br>' . $GMB::APP_AUTH_URL . '<br>Или свяжитесь с тех. поддержкой.';
