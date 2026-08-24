<?php
declare(strict_types=1);

namespace ReligionPlatform;

spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';
    if (!str_starts_with($class, $prefix)) return;
    $name = substr($class, strlen($prefix));
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name)) return;
    $path = __DIR__ . DIRECTORY_SEPARATOR . $name . '.php';
    if (is_file($path)) require_once $path;
});

set_exception_handler(static function (\Throwable $error): void {
    error_log('teacher-platform: ' . $error->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        Security::headers("default-src 'none'; frame-ancestors 'self'");
    }
    echo 'Die Lehrerplattform konnte die Anfrage nicht verarbeiten. Bitte später erneut versuchen.';
});

Database::connection();
