<?php

declare(strict_types=1);

use OpenGeo\Config;

ini_set('display_errors', '0');
error_reporting(E_ALL);

define('OPEN_GEO_ROOT', dirname(__DIR__));
require OPEN_GEO_ROOT . '/app/Core.php';
require OPEN_GEO_ROOT . '/app/Crawler.php';
require OPEN_GEO_ROOT . '/app/Auditor.php';
require OPEN_GEO_ROOT . '/app/Api.php';

Config::load(OPEN_GEO_ROOT . '/.env');
Config::assertProductionSecurity();
date_default_timezone_set(Config::get('APP_TIMEZONE', 'Asia/Shanghai'));
