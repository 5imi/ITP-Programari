<?php
declare(strict_types=1);

namespace Core;

/**
 * Incarca configuratia din config/env.php si expune helper-e de timp pe fusul Europe/Bucharest.
 */
final class Config
{
    private array $data;
    private string $baseDir;
    private \DateTimeZone $tz;

    public function __construct(string $baseDir, ?array $override = null)
    {
        $this->baseDir = rtrim($baseDir, '/\\');
        $envFile = $this->baseDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'env.php';

        if ($override !== null) {
            $this->data = $override;
        } elseif (is_file($envFile)) {
            $loaded = require $envFile;
            $this->data = is_array($loaded) ? $loaded : [];
        } else {
            throw new HttpException(
                503,
                'CONFIG_MISSING',
                'Lipsește fișierul config/env.php. Copiază config/env.example.php în config/env.php și completează datele bazei de date MariaDB.'
            );
        }

        $tzName = (string) ($this->data['APP_TZ'] ?? 'Europe/Bucharest');
        $this->tz = new \DateTimeZone($tzName);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function baseDir(): string
    {
        return $this->baseDir;
    }

    public function timezone(): \DateTimeZone
    {
        return $this->tz;
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->tz);
    }

    public function nowString(): string
    {
        return $this->now()->format('Y-m-d H:i:s');
    }

    public function todayIso(): string
    {
        return $this->now()->format('Y-m-d');
    }

    public function formatOffset(): string
    {
        return $this->now()->format('P');
    }
}
