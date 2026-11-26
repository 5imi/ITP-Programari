<?php
declare(strict_types=1);

namespace Core;

/**
 * Gestioneaza sesiunile autentificate prin cookie HttpOnly si permisiunile RBAC.
 */
/* Autentificare si RBAC (Auth): Verificare sesiune cookie httpOnly, CSRF, permisiuni granulare si rotatie session_version. */
final class Auth
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config
    ) {
    }

    /**
     * Valideaza sesiunea curenta, inclusiv expirarea absoluta, idle timeout si session_version.
     *
     * @return array{user: array, session: array, permissions: array<int, string>}
     */
    public function requireUser(Request $req, bool $checkCsrf = false, bool $touchSession = true): array
    {
        $rawToken = $req->cookie(Security::SESSION_COOKIE_NAME);
        if ($rawToken === null || $rawToken === '') {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Autentifică-te cu PIN pentru a continua.');
        }

        $tokenHash = Security::hashToken($rawToken);
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT s.token_hash, s.user_id, s.session_version AS sess_ver, s.csrf_token, s.last_seen_at, s.expires_at,
                    u.id, u.username, u.display_name, u.role, u.is_primary_admin, u.is_active, u.must_change_pin,
                    u.session_version AS user_ver, u.master_pin_hash
             FROM sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = ?'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new HttpException(401, 'SESSION_INVALID', 'Sesiunea nu mai este validă.');
        }

        $nowStr = $this->config->nowString();
        $idleHours = max(1, (int) $this->config->get('SESSION_IDLE_HOURS', 4));
        $idleLimit = $this->config->now()->modify("-{$idleHours} hours")->format('Y-m-d H:i:s');

        if ((int) $row['is_active'] !== 1
            || (int) $row['sess_ver'] !== (int) $row['user_ver']
            || $row['expires_at'] <= $nowStr
            || $row['last_seen_at'] < $idleLimit
        ) {
            $del = $pdo->prepare('DELETE FROM sessions WHERE token_hash = ?');
            $del->execute([$tokenHash]);
            throw new HttpException(401, 'SESSION_EXPIRED', 'Sesiunea a expirat sau a fost revocată. Autentifică-te din nou.');
        }

        $session = [
            'token_hash' => $row['token_hash'],
            'csrf_token' => $row['csrf_token'],
            'expires_at' => $row['expires_at'],
        ];

        if ($checkCsrf) {
            Security::verifyCsrf($req, $session);
        }

        // Cererile de fundal (poll=1) nu prelungesc inactivitatea si nu scriu inutil in DB
        if ($touchSession) {
            $upd = $pdo->prepare('UPDATE sessions SET last_seen_at = ? WHERE token_hash = ?');
            $upd->execute([$nowStr, $tokenHash]);
        }

        $permStmt = $pdo->prepare('SELECT permission FROM user_permissions WHERE user_id = ?');
        $permStmt->execute([(int) $row['id']]);
        $permissions = array_map('strval', $permStmt->fetchAll(\PDO::FETCH_COLUMN));

        $user = [
            'id' => (int) $row['id'],
            'username' => $row['username'],
            'display_name' => $row['display_name'],
            'role' => $row['role'],
            'is_primary_admin' => (int) $row['is_primary_admin'] === 1,
            'is_active' => (int) $row['is_active'] === 1,
            'must_change_pin' => (int) $row['must_change_pin'] === 1,
            'has_master_pin' => !empty($row['master_pin_hash']),
            'actor_label' => sprintf('%s (%s)', $row['display_name'], $row['role'] === 'admin' ? 'Admin' : 'User'),
        ];

        return [
            'user' => $user,
            'session' => $session,
            'permissions' => $permissions,
        ];
    }

    public function requireAdmin(Request $req, bool $checkCsrf = false): array
    {
        $ctx = $this->requireUser($req, $checkCsrf);
        if ($ctx['user']['role'] !== 'admin') {
            throw new HttpException(403, 'ADMIN_REQUIRED', 'Această acțiune este permisă doar Administratorului.');
        }
        return $ctx;
    }

    public function requirePermission(Request $req, string $permission, bool $checkCsrf = false): array
    {
        return $this->requireAnyPermission($req, [$permission], $checkCsrf);
    }

    /**
     * @param array<int, string> $permissions
     */
    public function requireAnyPermission(Request $req, array $permissions, bool $checkCsrf = false): array
    {
        $ctx = $this->requireUser($req, $checkCsrf);
        if ($ctx['user']['role'] === 'admin') {
            return $ctx;
        }
        foreach ($permissions as $perm) {
            if (in_array($perm, $ctx['permissions'], true)) {
                return $ctx;
            }
        }
        throw new HttpException(403, 'FORBIDDEN', 'Nu ai permisiunea necesară pentru această acțiune.');
    }

    public function createSessionCookie(\PDO $pdo, int $userId, int $sessionVersion, Request $req): array
    {
        $rawToken = Security::newToken(32);
        $tokenHash = Security::hashToken($rawToken);
        $csrfToken = Security::newToken(32);

        $now = $this->config->now();
        $hours = max(1, (int) $this->config->get('SESSION_HOURS', 12));
        $expiresDt = $now->modify("+{$hours} hours");
        $expiresAt = $expiresDt->format('Y-m-d H:i:s');
        $nowStr = $now->format('Y-m-d H:i:s');

        // Curatare periodica sesiuni expirate si incercari login vechi (> 7 zile)
        $pdo->prepare('DELETE FROM sessions WHERE expires_at <= ?')->execute([$nowStr]);
        $oldAttemptsLimit = $now->modify('-7 days')->format('Y-m-d H:i:s');
        $pdo->prepare('DELETE FROM login_attempts WHERE created_at < ?')->execute([$oldAttemptsLimit]);

        $stmt = $pdo->prepare(
            'INSERT INTO sessions (token_hash, user_id, session_version, csrf_token, ip_address, user_agent, created_at, last_seen_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $tokenHash,
            $userId,
            $sessionVersion,
            $csrfToken,
            $req->clientIp(),
            $req->userAgent(),
            $nowStr,
            $nowStr,
            $expiresAt,
        ]);

        self::setCookieHeader($rawToken, $expiresDt->getTimestamp(), $req->isHttps());

        return [
            'csrf_token' => $csrfToken,
            'expires_at' => $expiresAt,
        ];
    }

    public function clearSessionCookie(Request $req): void
    {
        self::setCookieHeader('', time() - 3600, $req->isHttps());
    }

    private static function setCookieHeader(string $value, int $expiresTs, bool $secure): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        setcookie(Security::SESSION_COOKIE_NAME, $value, [
            'expires' => $expiresTs,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
