<?php
/**
 * Plugin Name: LivePro Connector
 * Description: Conecta WooCommerce con el backoffice de LivePro e inyecta el widget de live shopping.
 * Version: 0.7.0
 * Author: LivePro
 */

defined('ABSPATH') || exit;

spl_autoload_register(static function (string $class): void {
    $prefix = 'LiveProConnector\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $path = __DIR__ . '/includes/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_readable($path)) {
        require_once $path;
    }
});

(new LiveProConnector\Plugin(__FILE__))->boot();
