<?php
declare(strict_types=1);

/**
 * Configuratie de baza pentru productie (Romarg cPanel / Laragon / XAMPP).
 * Copiaza acest fisier in `config/env.php` si completeaza datele MariaDB.
 * `config/env.php` este exclus din Git prin `.gitignore` si blocat din web prin `.htaccess`.
 */
return [
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_NAME' => 'acw_itp',
    'DB_USER' => 'root',
    'DB_PASS' => '',

    'APP_TZ' => 'Europe/Bucharest',
    'APP_NAME' => 'ACW - Fălticeni • Stație ITP',

    // 'auto' detecteaza HTTPS automat (inclusiv AutoSSL pe cPanel sau Cloudflare)
    'COOKIE_SECURE' => 'auto',
    'SESSION_HOURS' => 12,
    'SESSION_IDLE_HOURS' => 4,
    'TRUSTED_PROXIES' => ['127.0.0.1'],

    // Optional pe domeniul public la prima instalare: parola temporara ceruta in First-Start
    // Dupa crearea Adminului principal, ruta /api/setup/first-start se blocheaza permanent.
    'FIRST_START_TOKEN' => '',
];
