<?php

require (__DIR__ . '/GMB.php');

$GMB = new GMB();

$redirectUrl = $GMB->getRedirectUrlAuth2();

header('Location: '.$redirectUrl);