<?php

namespace NextSafar;

if (!defined('NEXTSAFAR_PATH')) exit;

/**
 * PSR-4 Autoloader for NextSafar plugin
 * Converts class names to file paths using kebab-case naming convention
 */
spl_autoload_register(function ($class) {
    if (strpos($class, 'NextSafar') !== 0) return;

    $class = str_replace('NextSafar', '', $class);
    $class = trim($class, '\\');

    if (empty($class)) return;

    $parts = explode('\\', $class);
    $className = array_pop($parts);

    $subPath = '';

    if (!empty($parts)) {
        $subPath = implode(DIRECTORY_SEPARATOR, array_map(function($part) {
            return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $part));
        }, $parts));
    }

    $fileName = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $className)) . '.php';

    $file = NEXTSAFAR_PATH . 'inc' . DIRECTORY_SEPARATOR;

    if (!empty($subPath)) {
        $file .= $subPath . DIRECTORY_SEPARATOR;
    }

    $file .= $fileName;

    if (file_exists($file)) {
        require_once $file;
        return;
    }

    $fallback = NEXTSAFAR_PATH . 'inc' . DIRECTORY_SEPARATOR . $fileName;

    if (file_exists($fallback)) {
        require_once $fallback;
    }
});