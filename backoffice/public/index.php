<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../src/Config.php';
Config::load(dirname(__DIR__));
require_once __DIR__ . '/../src/App.php';

$app = new App();
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$app->handle($method, $uriPath);
