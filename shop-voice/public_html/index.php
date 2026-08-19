<?php
declare(strict_types=1);

/**
 * Front controller — the only PHP file inside the web root.
 *
 * Everything it needs sits one level up, outside public_html: the source tree,
 * the .env holding the Shopmonkey token, and the logs. If this file is ever
 * moved such that `../src` lands inside the web root, the token becomes one
 * server misconfiguration away from being served as plain text (§3).
 */

$root = dirname(__DIR__);

require $root . '/src/autoload.php';

use ShopVoice\App;
use ShopVoice\Http\Request;
use ShopVoice\Http\Response;
use ShopVoice\Http\Router;
use ShopVoice\Http\Routes;

$app = App::boot($root . '/.env');

// Errors are logged, never rendered: a stack trace on a tablet in a bay is both
// useless to the tech and a disclosure risk.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(static function (\Throwable $e) use ($app): void {
    $app->logger()->error('http.fatal', [
        'message' => $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
    ]);
    Response::error('Something went wrong on our end.', 500, 'server')->send();
});

$router = new Router($app->logger());
Routes::register($router, $app);

$router->dispatch(Request::fromGlobals())->send();
