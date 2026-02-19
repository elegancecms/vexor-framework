<?php

declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════
 *  Vexor Framework — Entry Point
 *  
 *  All HTTP requests are routed through this file.
 *  Keep this file minimal — bootstrap logic lives in Application.
 * ═══════════════════════════════════════════════════════════
 */

define('VEXOR_START', microtime(true));

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!file_exists($autoload)) {
    http_response_code(500);
    die('Please run: composer install');
}

require $autoload;

// Load .env file if exists
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

$app = new \Vexor\Core\Application(dirname(__DIR__));

$app->handle();
