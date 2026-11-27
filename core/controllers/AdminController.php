<?php
declare(strict_types=1);

namespace Core\Controllers;

use Core\AuditLogger;
use Core\Auth;
use Core\BackupService;
use Core\Config;
use Core\Database;
use Core\HttpException;
use Core\ModuleLoader;
use Core\Request;
use Core\Response;
use Core\Security;
use Core\SettingsService;

/**
 * Controller administrare (doar rol `admin`): utilizatori, module, setari, audit securitate si backup.
 */
/* Controller Administrare (AdminController): Rute Admin pentru utilizatori/permisiuni, module, setari statie, audit CSV si backup-uri. */
final class AdminController
{
    private const VALID_PERMS = [
        'programari.edit',
        'programari.revenire',
        'programari.delete',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Auth $auth,
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
        private readonly ModuleLoader $modules,
        private readonly BackupService $backups
    ) {
    }

    public function listUsers(Request $req): array
    {
        $this->auth->requireAdmin($req, false);
        $pdo = $this->db->pdo();

        $users = $pdo->query(
            'SELECT id, username, display_name, role, is_primary_admin, is_active, must_change_pin, sort_order, created_at, updated_at, (master_pin_hash IS NOT NULL) AS has_master_pin
             FROM users ORDER BY sort_order ASC, id ASC'
        )->fetchAll();

        $permsRows = $pdo->query('SELECT user_id, permission FROM user_permissions')->fetchAll();
        $permMap = [];
        foreach ($permsRows as $pr) {
            $permMap[(int) $pr['user_id']][] = $pr['permission'];
        }

        $out = array_map(static function (array $u) use ($permMap): array {
            $uid = (int) $u['id'];
            return [
                'id' => $uid,
                'username' => $u['username'],
                'display_name' => $u['display_name'],
                'role' => $u['role'],
                'is_primary_admin' => (int) $u['is_primary_admin'] === 1,
                'is_active' => (int) $u['is_active'] === 1,
                'must_change_pin' => (int) $u['must_change_pin'] === 1,
                'has_master_pin' => (int) $u['has_master_pin'] === 1,
                'permissions' => $permMap[$uid] ?? [],
                'created_at' => $u['created_at'],
                'updated_at' => $u['updated_at'],
            ];
        }, $users);

        return Response::json(['ok' => true, 'users' => $out]);
    }

    public function createUser(Request $req): array
    {
        $ctx = $this->auth->requireAdmin($req, true);
        $body = $req->json();

        $displayName = trim((string) ($body['display_name'] ?? ''));
        $username = strtolower(trim((string) ($body['username'] ?? preg_replace('/[^a-z0-9]/i', '', $displayName))));
        $role = ($body['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        $pin = (string) ($body['pin'] ?? '');
        $perms = array_values(array_intersect(self::VALID_PERMS, (array) ($body['permissions'] ?? ['programari.edit', 'programari.revenire'])));

        if ($displayName === '' || mb_strlen($displayName) < 2) {
            throw new HttpException(422, 'INVALID_NAME', 'Numele afișat trebuie să aibă minim 2 caractere.');
        }
        if (preg_match('/^[a-z0-9_.-]{2,32}$/', $username) !== 1) {
            throw new HttpException(422, 'INVALID_USERNAME', 'Username invalid (2–32 caractere alfanumerice).');
        }
        Security::assertValidPinFormat($pin, false);

        if ($role === 'admin') {
            $perms = self::VALID_PERMS;
        }

        $created = $this->db->transaction(function (\PDO $pdo) use ($ctx, $req, $displayName, $username, $role, $pin, $perms): array {
            $now = $this->config->nowString();
            $stmt = $pdo->prepare(
                'INSERT INTO users (username, display_name, role, pin_hash, master_pin_hash, is_primary_admin, is_active, must_change_pin, session_version, sort_order, created_at, updated_at)
                 VALUES (?, ?, ?, ?, NULL, 0, 1, 0, 1, 100, ?, ?)'
            );
            try {
                $stmt->execute([$username, $displayName, $role, Security::hashPin($pin), $now, $now]);
            } catch (\PDOException $e) {
                if (Database::isDuplicateKeyException($e)) {
                    throw new HttpException(409, 'USERNAME_EXISTS', 'Există deja un utilizator cu acest username.');
                }
                throw $e;
            }
            $newId = (int) $pdo->lastInsertId();

            $insPerm = $pdo->prepare('INSERT INTO user_permissions (user_id, permission) VALUES (?, ?)');
            foreach ($perms as $p) {
                $insPerm->execute([$newId, $p]);
            }

            $this->audit->log(
                $pdo,
                (int) $ctx['user']['id'],
                $ctx['user']['actor_label'],
                'USER_CREATE',
                sprintf('Creat cont %s (%s) • permisiuni: %s', $displayName, $role, implode(', ', $perms)),
                'core',
                'user',
                (string) $newId,
                null,
                ['username' => $username, 'display_name' => $displayName, 'role' => $role, 'permissions' => $perms],
                $req
            );

            return ['ok' => true, 'user_id' => $newId];
        });

        return Response::json($created, 201);
    }

    public function updateUser(Request $req, array $params): array
    {
        $ctx = $this->auth->requireAdmin($req, true);
        $targetId = (int) ($params['id'] ?? 0);
        if ($targetId <= 0) {
            throw new HttpException(400, 'INVALID_USER_ID', 'ID utilizator invalid.');
        }

        $body = $req->json();
        $displayName = isset($body['display_name']) ? trim((string) $body['display_name']) : null;
        $role = isset($body['role']) ? (($body['role'] === 'admin') ? 'admin' : 'user') : null;
        $newPin = isset($body['pin']) && (string) $body['pin'] !== '' ? (string) $body['pin'] : null;
        $newMasterPin = isset($body['master_pin']) && (string) $body['master_pin'] !== '' ? (string) $body['master_pin'] : null;
        $perms = isset($body['permissions']) && is_array($body['permissions'])
            ? array_values(array_intersect(self::VALID_PERMS, $body['permissions']))
            : null;

        if ($displayName !== null && mb_strlen($displayName) < 2) {
            throw new HttpException(422, 'INVALID_NAME', 'Numele afișat trebuie să aibă minim 2 caractere.');
        }
        if ($newPin !== null) {
            Security::assertValidPinFormat($newPin, false);
        }
        if ($newMasterPin !== null) {
            throw new HttpException(
                403,
                'MASTER_PIN_PROTECTED',
                'PIN-ul Secret Master nu poate fi modificat din panoul standard cu PIN uzual.'
            );
        }

        $this->db->transaction(function (\PDO $pdo) use ($ctx, $req, $targetId, $displayName, $role, $newPin, $perms): void {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$targetId]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new HttpException(404, 'USER_NOT_FOUND', 'Utilizatorul nu există.');
            }

            $isPrimary = (int) $row['is_primary_admin'] === 1;
            if ($isPrimary && (int) $ctx['user']['id'] !== $targetId) {
                throw new HttpException(403, 'PRIMARY_ADMIN_PROTECTED', 'Doar Administratorul principal își poate edita propriul cont.');
            }
            $nextRole = $role ?? (string) $row['role'];
            if ($isPrimary && $nextRole !== 'admin') {
                throw new HttpException(409, 'PRIMARY_ADMIN_PROTECTED', 'Administratorul principal nu poate fi retrogradat.');
            }

            $nextName = $displayName ?? (string) $row['display_name'];
            $nextPinHash = $newPin !== null ? Security::hashPin($newPin) : (string) $row['pin_hash'];
            $nextSessVer = ($newPin !== null || $nextRole !== $row['role'])
                ? ((int) $row['session_version']) + 1
                : (int) $row['session_version'];

            $upd = $pdo->prepare(
                'UPDATE users SET display_name = ?, role = ?, pin_hash = ?, session_version = ?, updated_at = ? WHERE id = ?'
            );
            $upd->execute([$nextName, $nextRole, $nextPinHash, $nextSessVer, $this->config->nowString(), $targetId]);

            if ($nextRole === 'admin') {
                $perms = self::VALID_PERMS;
            }
            if ($perms !== null) {
                $pdo->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$targetId]);
                $insPerm = $pdo->prepare('INSERT INTO user_permissions (user_id, permission) VALUES (?, ?)');
                foreach ($perms as $p) {
                    $insPerm->execute([$targetId, $p]);
                }
            }

            if ($nextSessVer !== (int) $row['session_version']) {
                $pdo->prepare('DELETE FROM sessions WHERE user_id = ? AND token_hash <> ?')
                    ->execute([$targetId, $ctx['session']['token_hash']]);
                if ($targetId === (int) $ctx['user']['id']) {
                    $pdo->prepare('UPDATE sessions SET session_version = ? WHERE token_hash = ?')
                        ->execute([$nextSessVer, $ctx['session']['token_hash']]);
                }
            }

            $this->audit->log(
                $pdo,
                (int) $ctx['user']['id'],
                $ctx['user']['actor_label'],
                'USER_UPDATE',
                sprintf('Actualizat utilizator %s (%s)%s', $nextName, $nextRole, $newPin !== null ? ' [PIN schimbat]' : ''),
                'core',
                'user',
                (string) $targetId,
                ['display_name' => $row['display_name'], 'role' => $row['role']],
                ['display_name' => $nextName, 'role' => $nextRole, 'permissions' => $perms],
                $req
            );
        });

        return Response::json(['ok' => true]);
    }

    public function toggleUserActive(Request $req, array $params): array
    {
        $ctx = $this->auth->requireAdmin($req, true);
        $targetId = (int) ($params['id'] ?? 0);
        if ($targetId <= 0) {
            throw new HttpException(400, 'INVALID_USER_ID', 'ID utilizator invalid.');
        }

        $nextActive = $this->db->transaction(function (\PDO $pdo) use ($ctx, $req, $targetId): bool {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$targetId]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new HttpException(404, 'USER_NOT_FOUND', 'Utilizatorul nu există.');
            }
            if ((int) $row['is_primary_admin'] === 1) {
                throw new HttpException(409, 'PRIMARY_ADMIN_PROTECTED', 'Administratorul principal nu poate fi blocat.');
            }

            $willBeActive = (int) $row['is_active'] === 0;
            $nextVer = ((int) $row['session_version']) + 1;
            $upd = $pdo->prepare('UPDATE users SET is_active = ?, session_version = ?, updated_at = ? WHERE id = ?');
            $upd->execute([$willBeActive ? 1 : 0, $nextVer, $this->config->nowString(), $targetId]);

            if (!$willBeActive) {
                $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$targetId]);
            }

            $this->audit->log(
                $pdo,
                (int) $ctx['user']['id'],
                $ctx['user']['actor_label'],
                $willBeActive ? 'USER_UNBLOCK' : 'USER_BLOCK',
                sprintf('%s contul %s.', $willBeActive ? 'Activat' : 'Blocat', $row['display_name']),
                'core',
                'user',
                (string) $targetId,
                null,
                null,
                $req
            );

            return $willBeActive;
        });

        return Response::json(['ok' => true, 'is_active' => $nextActive]);
    }

    public function listModules(Request $req): array
    {
        $this->auth->requireAdmin($req, false);
        return Response::json([
            'ok' => true,
            'modules' => $this->modules->listAllWithState(),
        ]);
    }

    public function toggleModule(Request $req, array $params): array
    {
        $ctx = $this->auth->requireAdmin($req, true);
        $moduleId = (string) ($params['id'] ?? '');
        $manifests = $this->modules->manifests();

        if (!isset($manifests[$moduleId])) {
            throw new HttpException(404, 'MODULE_NOT_FOUND', 'Modulul solicitat nu există.');
        }
        if (!empty($manifests[$moduleId]['locked'])) {
            throw new HttpException(409, 'MODULE_LOCKED', 'Modulul principal nu poate fi dezactivat.');
        }

        $enabled = $this->db->transaction(function (\PDO $pdo) use ($ctx, $req, $moduleId, $manifests): bool {
            $stmt = $pdo->prepare('SELECT enabled FROM modules_state WHERE module_id = ? FOR UPDATE');
            $stmt->execute([$moduleId]);
            $cur = (int) $stmt->fetchColumn();
            $next = $cur === 1 ? 0 : 1;

            $upd = $pdo->prepare('UPDATE modules_state SET enabled = ?, updated_at = ? WHERE module_id = ?');
            $upd->execute([$next, $this->config->nowString(), $moduleId]);

            $this->audit->log(
                $pdo,
                (int) $ctx['user']['id'],
                $ctx['user']['actor_label'],
                'MODULE_TOGGLE',
                sprintf('%s modul "%s"', $next === 1 ? 'Activat' : 'Dezactivat', $manifests[$moduleId]['name'] ?? $moduleId),
                'core',
                'module',
                $moduleId,
                ['enabled' => $cur === 1],
                ['enabled' => $next === 1],
                $req
            );

            return $next === 1;
        });

        return Response::json(['ok' => true, 'module_id' => $moduleId, 'enabled' => $enabled]);
    }

    public function getSettings(Request $req): array
    {
        $this->auth->requireAdmin($req, false);
        $cfg = $this->settings->getStationConfig();
        return Response::json([
            'ok' => true,
            'station_config' => $cfg,
            'daily_slots' => $this->settings->buildDailySlots($cfg),
        ]);
    }

    public function updateSettings(Request $req): array
    {
        $ctx = $this->auth->requireAdmin($req, true);
        $body = $req->json();

        $next = $this->db->transaction(function (\PDO $pdo) use ($ctx, $req, $body): array {
            $prev = $this->settings->getStationConfig();
            $updated = $this->settings->updateStationConfig($pdo, $body, $ctx['user']['actor_label']);

            $this->audit->log(
                $pdo,
                (int) $ctx['user']['id'],
                $ctx['user']['actor_label'],
                'SETTINGS_UPDATE',
                'Actualizate setările stației ITP.',
                'core',
                'settings',
                SettingsService::KEY_STATION,
                $prev,
                $updated,
                $req
            );

            return $updated;
        });

        return Response::json([
            'ok' => true,
            'station_config' => $next,
            'daily_slots' => $this->settings->buildDailySlots($next),
        ]);
    }

    public function listAudit(Request $req): array
    {
        $this->auth->requireAdmin($req, false);
        $rows = $this->fetchAuditRows($req);
        return Response::json(['ok' => true, 'events' => $rows]);
    }

    public function exportAuditCsv(Request $req): array
    {
        $ctx = $this->auth->requireAdmin($req, false);
        $rows = $this->fetchAuditRows($req);

        $this->db->transaction(function (\PDO $pdo) use ($ctx, $req, $rows): void {
            $this->audit->log(
                $pdo,
                (int) $ctx['user']['id'],
                $ctx['user']['actor_label'],
                'EXPORT',
                sprintf('Export CSV Jurnal Securitate (%d înregistrări).', count($rows)),
                'core',
                'audit',
                null,
                null,
                null,
                $req
            );
        });

        $csvRows = array_map(static fn(array $r): array => [
            $r['date'],
            $r['at'],
            $r['actor_name'],
            $r['action'],
            $r['summary'],
            $r['ip_address'],
        ], $rows);

        return Response::csv(
            'itp_jurnal_securitate_' . $this->config->now()->format('Ymd') . '.csv',
            ['Data', 'Ora', 'Utilizator', 'Actiune', 'Detalii', 'IP'],
            $csvRows
        );
    }

    public function listBackups(Request $req): array
    {
        $this->auth->requireAdmin($req, false);
        return Response::json([
            'ok' => true,
            'backups' => $this->backups->listBackups(),
        ]);
    }

    public function createBackup(Request $req): array
    {
        $ctx = $this->auth->requireAdmin($req, true);
        $info = $this->backups->createSnapshot($ctx['user']['actor_label']);

        $this->db->transaction(function (\PDO $pdo) use ($ctx, $req, $info): void {
            $this->audit->log(
                $pdo,
                (int) $ctx['user']['id'],
                $ctx['user']['actor_label'],
                'BACKUP',
                sprintf('Generat snapshot MariaDB: %s (%d bytes).', $info['filename'], $info['size_bytes']),
                'core',
                'backup',
                $info['filename'],
                null,
                $info,
                $req
            );
        });

        return Response::json(['ok' => true, 'backup' => $info], 201);
    }

    public function downloadBackup(Request $req, array $params): array
    {
        $ctx = $this->auth->requireAdmin($req, false);
        $filename = (string) ($params['file'] ?? '');
        $path = $this->backups->resolveBackupFile($filename);

        $this->db->transaction(function (\PDO $pdo) use ($ctx, $req, $path): void {
            $this->audit->log(
                $pdo,
                (int) $ctx['user']['id'],
                $ctx['user']['actor_label'],
                'BACKUP_DOWNLOAD',
                sprintf('Descărcat fișier backup: %s.', basename($path)),
                'core',
                'backup',
                basename($path),
                null,
                null,
                $req
            );
        });

        if (PHP_SAPI !== 'cli') {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: same-origin');
            header('Cache-Control: no-store');
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . basename($path) . '"');
            header('Content-Length: ' . (string) filesize($path));
            readfile($path);
            exit;
        }

        return Response::json(['ok' => true, 'file' => basename($path)]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAuditRows(Request $req): array
    {
        $category = strtoupper(trim((string) $req->query('category', 'TOATE')));
        $q = trim((string) $req->query('q', ''));
        $limit = max(10, min(500, (int) $req->query('limit', 250)));

        $catActions = [
            'AUTH' => ['LOGIN_OK', 'LOGIN_FAIL', 'LOGOUT', 'PIN_CHANGE', 'MASTER_PIN_CHANGE', 'ADMIN_MASTER_RECOVERY', 'FIRST_START_SETUP'],
            'USERS' => ['USER_CREATE', 'USER_UPDATE', 'USER_BLOCK', 'USER_UNBLOCK'],
            'CONFIG' => ['REASON_ADD', 'REASON_UPDATE', 'REASON_DELETE', 'MODULE_TOGGLE', 'SETTINGS_UPDATE', 'BACKUP', 'BACKUP_DOWNLOAD', 'EXPORT'],
        ];

        $where = [];
        $args = [];
        if ($category !== 'TOATE' && isset($catActions[$category])) {
            $placeholders = implode(',', array_fill(0, count($catActions[$category]), '?'));
            $where[] = "action IN ($placeholders)";
            $args = array_merge($args, $catActions[$category]);
        }
        if ($q !== '') {
            $where[] = '(actor_name LIKE ? OR action LIKE ? OR summary LIKE ?)';
            $like = '%' . $q . '%';
            array_push($args, $like, $like, $like);
        }

        $sql = 'SELECT id, created_at, user_id, actor_name, module_id, action, summary, ip_address
                FROM core_audit_log';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($args);

        return array_map(static function (array $r): array {
            $dt = (string) $r['created_at'];
            $datePart = substr($dt, 0, 10);
            $timePart = substr($dt, 11, 5);
            return [
                'id' => (int) $r['id'],
                'created_at' => $dt,
                'date' => $datePart,
                'at' => $timePart,
                'actor_name' => $r['actor_name'],
                'module_id' => $r['module_id'],
                'action' => $r['action'],
                'summary' => $r['summary'],
                'ip_address' => $r['ip_address'],
            ];
        }, $stmt->fetchAll());
    }
}
