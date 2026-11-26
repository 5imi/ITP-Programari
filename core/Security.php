<?php
declare(strict_types=1);

namespace Core;

/**
 * Primitivi criptografici, validare PIN, verificare CSRF si rate-limit in login_attempts.
 */
final class Security
{
    public const SESSION_COOKIE_NAME = 'itp_session';

    public static function hashPin(string $pin): string
    {
        return password_hash($pin, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    public static function verifyPin(string $pin, string $hash): bool
    {
        return $hash !== '' && password_verify($pin, $hash);
    }

    public static function newToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function assertValidPinFormat(string $pin, bool $isMaster = false): void
    {
        if ($isMaster) {
            if (preg_match('/^\d{8,12}$/', $pin) !== 1) {
                throw new HttpException(422, 'INVALID_MASTER_PIN', 'PIN-ul Secret Master trebuie să conțină exact 8–12 cifre.');
            }
            return;
        }
        if (preg_match('/^\d{4,6}$/', $pin) !== 1) {
            throw new HttpException(422, 'INVALID_PIN_FORMAT', 'PIN-ul uzual trebuie să conțină exact 4–6 cifre.');
        }
    }

    public static function verifyCsrf(Request $req, array $session): void
    {
        $headerToken = (string) ($req->header('x-csrf-token') ?? '');
        $expected = (string) ($session['csrf_token'] ?? '');
        if ($headerToken === '' || $expected === '' || !hash_equals($expected, $headerToken)) {
            throw new HttpException(403, 'CSRF_INVALID', 'Token CSRF lipsă sau invalid. Reîncarcă pagina.');
        }
    }

    public static function enforceRateLimit(\PDO $pdo, string $kind, ?int $userId, string $ip, Config $config): void
    {
        $now = $config->now();

        if ($kind === 'master') {
            $since = $now->modify('-30 minutes')->format('Y-m-d H:i:s');
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM login_attempts WHERE kind = 'master' AND success = 0 AND created_at >= ?"
            );
            $stmt->execute([$since]);
            if ((int) $stmt->fetchColumn() >= 3) {
                throw new HttpException(
                    429,
                    'MASTER_RATE_LIMITED',
                    'Prea multe încercări greșite pe PIN-ul Secret. Încearcă din nou peste 30 de minute.'
                );
            }
            return;
        }

        $since = $now->modify('-5 minutes')->format('Y-m-d H:i:s');
        if ($userId !== null) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM login_attempts WHERE kind = 'pin' AND success = 0 AND created_at >= ? AND (user_id = ? OR ip_address = ?)"
            );
            $stmt->execute([$since, $userId, $ip]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM login_attempts WHERE kind = 'pin' AND success = 0 AND created_at >= ? AND ip_address = ?"
            );
            $stmt->execute([$since, $ip]);
        }

        if ((int) $stmt->fetchColumn() >= 5) {
            throw new HttpException(
                429,
                'RATE_LIMITED',
                'Prea multe încercări PIN greșite (5 în 5 minute). Așteaptă 5 minute înainte de reîncercare.'
            );
        }
    }

    public static function recordLoginAttempt(\PDO $pdo, ?int $userId, string $ip, string $kind, bool $success, Config $config): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO login_attempts (user_id, ip_address, kind, success, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $ip,
            $kind,
            $success ? 1 : 0,
            $config->nowString(),
        ]);
    }
}
