<?php
/**
 * PSR-4 autoloader for WorkforceOne\ classes in src/ (no Composer needed at runtime).
 */
if (!defined('ABSPATH')) exit;

spl_autoload_register(function ($class) {
    $prefix = 'WorkforceOne\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});
