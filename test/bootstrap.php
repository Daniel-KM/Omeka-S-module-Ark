<?php

$loader = require __DIR__ . '/../vendor/autoload.php';
$loader->addPsr4('ArkTest\\', __DIR__ . '/ArkTest/');

use OmekaTestHelper\Bootstrap;

Bootstrap::bootstrap(__DIR__);
Bootstrap::loginAsAdmin();
Bootstrap::enableModule('Ark');
