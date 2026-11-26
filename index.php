<?php
declare(strict_types=1);

/**
 * Front Controller unic pentru aplicatia ACW - Falticeni • Statie ITP (PHP 8.2+ + MariaDB).
 */

require_once __DIR__ . '/core/Autoloader.php';
\Core\Autoloader::register(__DIR__);

use Core\App;
use Core\Config;
use Core\HttpException;
use Core\Request;
use Core\Response;

try {
    $config = new Config(__DIR__);
    $req = new Request($config);
    $app = new App(__DIR__);
    $result = $app->handle($req);
    Response::emit($result);
} catch (HttpException $e) {
    $isApi = str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/')
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    if (!$isApi && $e->errorCode() === 'CONFIG_MISSING') {
        Response::emit(Response::html(
            '<!doctype html><html lang="ro"><head><meta charset="utf-8"><title>Configurare necesară</title>'
            . '<link rel="stylesheet" href="references/ui-libs/tabler/tabler.min.css"></head>'
            . '<body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh;">'
            . '<div class="card p-4 shadow-sm" style="max-width:520px;">'
            . '<h3 class="fw-bold text-danger mb-2">Configurare bază de date necesară</h3>'
            . '<p class="text-muted mb-2">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<div class="small bg-light border rounded p-2">1. Copiază <code>config/env.example.php</code> în <code>config/env.php</code><br>2. Completează <code>DB_NAME</code>, <code>DB_USER</code>, <code>DB_PASS</code><br>3. Reîncarcă pagina pentru rularea automată a migrărilor MariaDB.</div>'
            . '</div></body></html>',
            503
        ));
    } else {
        Response::emit(Response::error($e->errorCode(), $e->getMessage(), $e->statusCode(), $e->extra()));
    }
} catch (\Throwable $e) {
    Response::emit(Response::error(
        'INTERNAL_ERROR',
        'A apărut o eroare internă la procesarea cererii.',
        500
    ));
}
