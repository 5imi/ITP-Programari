<?php
declare(strict_types=1);

namespace Core;

/**
 * Emite raspunsuri JSON, erori structurate, fisiere CSV (UTF-8 BOM) sau continut HTML.
 */
/* Generator Raspunsuri HTTP (Response): Constructie si emitere raspunsuri JSON, erori structurate, CSV si HTML. */
final class Response
{
    public static function json(array $payload, int $status = 200): array
    {
        return [
            'type' => 'json',
            'status' => $status,
            'payload' => $payload,
        ];
    }

    public static function error(string $code, string $message, int $status = 400, array $extra = []): array
    {
        return self::json(array_merge([
            'ok' => false,
            'error' => $code,
            'message' => $message,
        ], $extra), $status);
    }

    public static function csv(string $filename, array $headers, array $rows): array
    {
        return [
            'type' => 'csv',
            'status' => 200,
            'filename' => $filename,
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    public static function html(string $html, int $status = 200): array
    {
        return [
            'type' => 'html',
            'status' => $status,
            'body' => $html,
        ];
    }

    public static function emit(array $result): void
    {
        $status = (int) ($result['status'] ?? 200);
        http_response_code($status);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');

        $type = $result['type'] ?? 'json';

        if ($type === 'html') {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            echo (string) ($result['body'] ?? '');
            return;
        }

        if ($type === 'csv') {
            $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) ($result['filename'] ?? 'export.csv'));
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $safeName . '"');
            header('Cache-Control: no-store');

            $out = fopen('php://output', 'wb');
            if ($out !== false) {
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, (array) ($result['headers'] ?? []));
                foreach ((array) ($result['rows'] ?? []) as $row) {
                    $sanitized = array_map(static function ($cell): string {
                        $val = (string) $cell;
                        if ($val !== '' && preg_match('/^[=+\-@\t\r]/', $val) === 1) {
                            return "'" . $val;
                        }
                        return $val;
                    }, (array) $row);
                    fputcsv($out, $sanitized);
                }
                fclose($out);
            }
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($result['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
