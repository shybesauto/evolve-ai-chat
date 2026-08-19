<?php
declare(strict_types=1);

/**
 * PSR-4 autoloader for the ShopVoice\ namespace.
 *
 * Deliberately composer-free: cPanel shared hosting has no reliable shell for
 * `composer install`, and this project has no third-party runtime dependencies.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'ShopVoice\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
