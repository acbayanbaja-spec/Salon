<?php
// Vercel Serverless Front Controller for PHP
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = ltrim($uri, '/');

if ($file === '' || $file === '/') {
    $file = 'index.php';
}

// Support URLs without .php extension (e.g. /login -> /login.php)
if (!pathinfo($file, PATHINFO_EXTENSION)) {
    if (file_exists(__DIR__ . '/../' . $file . '.php')) {
        $file .= '.php';
    }
}

$target = realpath(__DIR__ . '/../' . $file);
$rootDir = realpath(__DIR__ . '/..');

// Ensure target file is within root directory and exists
if ($target && strpos($target, $rootDir) === 0 && is_file($target)) {
    $_SERVER['SCRIPT_FILENAME'] = $target;
    $_SERVER['SCRIPT_NAME'] = '/' . $file;
    $_SERVER['PHP_SELF'] = '/' . $file;
    chdir($rootDir);
    require $target;
} else {
    chdir($rootDir);
    require $rootDir . '/index.php';
}
