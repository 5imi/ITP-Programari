<?php
declare(strict_types=1);

namespace Core;

/**
 * Incapsuleaza cererea HTTP curenta, normalizand ruta relativa la subdirectorul aplicatiei.
 */
final class Request
{
    private string $method;
    private string $path;
    private array $query;
    private array $headers;
    private array $cookies;
    private ?array $jsonBody = null;
    private string $rawBody;
    private string $clientIp;
    private string $userAgent;
    private bool $https;

    public function __construct(
        Config $config,
        ?string $method = null,
        ?string $path = null,
        array $query = [],
        array $headers = [],
        array $cookies = [],
        ?string $rawBody = null,
        ?string $remoteAddr = null
    ) {
        $this->method = strtoupper($method ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if ($path !== null) {
            $this->path = '/' . ltrim($path, '/');
        } else {
            $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
            $scriptDir = rtrim($scriptDir, '/');
            if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($uriPath, $scriptDir)) {
                $uriPath = substr($uriPath, strlen($scriptDir)) ?: '/';
            }
            $this->path = '/' . ltrim($uriPath, '/');
        }

        $this->query = $query !== [] ? $query : $_GET;
        $this->cookies = $cookies !== [] ? $cookies : $_COOKIE;
        $this->rawBody = $rawBody ?? ((string) file_get_contents('php://input'));

        $parsedHeaders = [];
        foreach ($_SERVER as $key => $val) {
            if (str_starts_with($key, 'HTTP_')) {
                $hName = str_replace('_', '-', strtolower(substr($key, 5)));
                $parsedHeaders[$hName] = (string) $val;
            }
        }
        foreach ($headers as $k => $v) {
            $parsedHeaders[strtolower((string) $k)] = (string) $v;
        }
        $this->headers = $parsedHeaders;

        $addr = $remoteAddr ?? (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $trusted = (array) $config->get('TRUSTED_PROXIES', ['127.0.0.1']);
        if (in_array($addr, $trusted, true) && isset($this->headers['x-forwarded-for'])) {
            $parts = array_map('trim', explode(',', $this->headers['x-forwarded-for']));
            if (!empty($parts[0])) {
                $addr = $parts[0];
            }
        }
        $this->clientIp = substr($addr, 0, 64);
        $this->userAgent = substr((string) ($this->headers['user-agent'] ?? ''), 0, 255);

        $cookieSecure = (string) $config->get('COOKIE_SECURE', 'auto');
        if ($cookieSecure === '1' || $cookieSecure === 'true') {
            $this->https = true;
        } elseif ($cookieSecure === '0' || $cookieSecure === 'false') {
            $this->https = false;
        } else {
            $srvHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['SERVER_PORT'] ?? '') === '443');
            $fwdProto = in_array($remoteAddr ?? ($_SERVER['REMOTE_ADDR'] ?? ''), $trusted, true)
                && (($this->headers['x-forwarded-proto'] ?? '') === 'https');
            $this->https = $srvHttps || $fwdProto;
        }
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return isset($this->cookies[$name]) ? (string) $this->cookies[$name] : $default;
    }

    public function json(): array
    {
        if ($this->jsonBody !== null) {
            return $this->jsonBody;
        }
        if (trim($this->rawBody) === '') {
            $this->jsonBody = [];
            return $this->jsonBody;
        }
        $decoded = json_decode($this->rawBody, true);
        if (!is_array($decoded)) {
            throw new HttpException(400, 'INVALID_JSON', 'Corpul cererii nu este un JSON valid.');
        }
        $this->jsonBody = $decoded;
        return $this->jsonBody;
    }

    public function clientIp(): string
    {
        return $this->clientIp;
    }

    public function userAgent(): string
    {
        return $this->userAgent;
    }

    public function isHttps(): bool
    {
        return $this->https;
    }
}
