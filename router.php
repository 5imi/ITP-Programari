<?php
declare(strict_types=1);

/**
 * Router pentru PHP built-in dev server (`php -S 127.0.0.1:8080 router.php`).
 * In productie pe cPanel/Apache/LiteSpeed regulile sunt aplicate direct de `.htaccess`.
 */
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Blocare acces direct la directoare si fisiere interne sensibile (echivalent .htaccess)
if (preg_match('#^/(\.git|\.env|config/|core/|modules/|backups/|tests/)#', $uriPath) === 1
    || preg_match('#\.(sql|md|log|db|sqlite|bak)$#i', $uriPath) === 1) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '403 Forbidden';
    return true;
}

// Fisiere statice publice reale (assets, references/ui-libs, robots.txt, manifest.json)
$fullPath = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $uriPath);
if ($uriPath !== '/' && is_file($fullPath) && !str_ends_with(strtolower($fullPath), '.php')) {
    return false;
}

require __DIR__ . '/index.php';
return true;
