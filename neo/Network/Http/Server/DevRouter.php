<?php

declare(strict_types=1);

/**
 * Роутер-скрипт для встроенного сервера PHP (`php -S`), запускаемого командой `php cli serve`.
 * Существующие статические файлы отдаются сервером PHP, всё остальное идёт в public/index.php.
 */

$root = (string) ($_SERVER['DOCUMENT_ROOT'] ?? getcwd());
$path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));

if ($path !== '/' && !str_contains($path, "\0") && !str_contains($path, '..')) {
    $file = $root . $path;

    if (is_file($file) && strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php' && basename($file)[0] !== '.') {
        return false;
    }
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require $root . '/index.php';
