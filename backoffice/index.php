<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/src/Config.php';
Config::load(__DIR__);
require_once __DIR__ . '/src/App.php';

$isProd = (Config::get('APP_ENV', 'production') === 'production');

try {
    $app = new App();
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $app->handle($method, $uriPath);
} catch (Throwable $error) {
    error_log('[LivePro] ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine());
    http_response_code(500);

    if ($isProd) {
        echo 'Internal Server Error';
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo "LivePro Error\n";
    echo $error->getMessage() . "\n";
    echo $error->getFile() . ':' . $error->getLine() . "\n";
}
