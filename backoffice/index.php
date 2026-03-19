<?php

declare(strict_types=1);

session_start();

$debugBoot = isset($_GET['__debug_boot']);
$lastBootCheckpoint = 'session_start';

if ($debugBoot) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
    header('Content-Type: text/plain; charset=utf-8');
    echo "[LivePro] boot: session_start\n";
}

register_shutdown_function(static function () use ($debugBoot, &$lastBootCheckpoint): void {
    if (!$debugBoot) {
        return;
    }

    $error = error_get_last();
    if (!$error) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int) ($error['type'] ?? 0), $fatalTypes, true)) {
        return;
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }

    echo "[LivePro] shutdown fatal after checkpoint: " . $lastBootCheckpoint . "\n";
    echo ($error['message'] ?? 'Fatal error') . "\n";
    echo ($error['file'] ?? '') . ':' . ($error['line'] ?? '') . "\n";
});

$isProd = true;

try {
    $lastBootCheckpoint = 'before_config_require';
    require_once __DIR__ . '/src/Config.php';
    $lastBootCheckpoint = 'config_loaded';
    Config::load(__DIR__);
    if ($debugBoot) {
        echo "[LivePro] boot: config_loaded\n";
    }

    $lastBootCheckpoint = 'before_app_require';
    require_once __DIR__ . '/src/App.php';
    if ($debugBoot) {
        echo "[LivePro] boot: app_loaded\n";
    }

    $isProd = (Config::get('APP_ENV', 'production') === 'production');

    $lastBootCheckpoint = 'before_app_construct';
    $app = new App();
    if ($debugBoot) {
        echo "[LivePro] boot: app_constructed\n";
    }

    $lastBootCheckpoint = 'before_route_parse';
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($debugBoot) {
        echo "[LivePro] boot: dispatching {$method} {$uriPath}\n";
    }

    $lastBootCheckpoint = 'before_handle';
    $app->handle($method, $uriPath);
    $lastBootCheckpoint = 'handle_completed';
} catch (Throwable $error) {
    error_log('[LivePro] ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine());
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    echo "LivePro Error\n";
    echo 'Checkpoint: ' . $lastBootCheckpoint . "\n";
    echo $error->getMessage() . "\n";
    echo $error->getFile() . ':' . $error->getLine() . "\n";
}
