<?php
/**
 * Lightweight PSR-4-like autoloader for the FF_WCS namespace.
 * Maps namespace \FFWCS\ to the includes/ directory.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

spl_autoload_register(function ($class) {
    // only load classes in our namespace
    $prefix = 'FFWCS\\';
    $base_dir = __DIR__ . '/includes/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
