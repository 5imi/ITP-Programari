<?php
declare(strict_types=1);

namespace Core\Controllers;

use Core\AuditLogger;
use Core\Auth;
use Core\Config;
use Core\Database;
use Core\HttpException;
use Core\ModuleLoader;
use Core\Request;
use Core\Response;
use Core\Security;
use Core\SettingsService;

/**
 * Controller pentru autentificare cu PIN, Dual-PIN Admin Recovery, schimbare PIN si First-Start Setup.
 */
final class AuthController
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Auth $auth,
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
        private readonly ModuleLoader $modules
    ) {
    }

    public function setupStatus(Request $req): array
    {
        $count = (int) $this->db->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $setupToken = (string) $this->config->get('FIRST_START_TOKEN', '');
        $cfg = $this->settings->getStationConfig();

        return Response::json([
            'ok' => true,
            'needs_setup' => $count === 0,
            'setup_token_required' => $count === 0 && $setupToken !== '',
            'today_date' => $this->config->todayIso(),
            'station_name' => (string) ($cfg['station_name'] ?? $this->config->get('APP_NAME', 'Stație ITP')),
        ]);
    }

    public function firstStart(Request $req): array
    {
        $body = $req->json();
        $configuredToken = (string) $this->config->get('FIRST_START_TOKEN', '');
        if ($configuredToken !== '') {
            $provided = (string) ($body['setup_token'] ?? '');
            if ($provided === '' || !hash_equals($configuredToken, $provided)) {
                throw new HttpException(403, 'SETUP_TOKEN_INVALID', 'Codul FIRST_START_TOKEN din config/env.php nu corespunde.');
            }
        }

        $displayName = trim((string) ($body['display_name'] ?? ''));
        $username = strtolower(trim((string) ($body['username'] ?? 'admin')));
        $pin = (string) ($body['pin'] ?? '');
        $masterPin = (string) ($body['master_pin'] ?? '');
        $stationName = trim((string) ($body['station_name'] ?? ''));

        if ($displayName === '' || mb_strlen($displayName) < 2) {
            throw new HttpException(422, 'INVALID_NAME', 'Introdu numele administratorului principal (minim 2 caractere).');
        }
        if (preg_match('/^[a-z0-9_.-]{2,32}$/', $username) !== 1) {
            throw new HttpException(422, 'INVALID_USERNAME', 'Username-ul trebuie să conțină 2–32 caractere alfanumerice.');
        }
        Security::assertValidPinFormat($pin, false);
        Security::assertValidPinFormat($masterPin, true);

        $extraUsers = is_array($body['additional_users'] ?? null) ? $body['additional_users'] : [];

        $result = $this->db->transaction(function (\PDO $pdo) use (
            $req,
            $displayName,
            $username,
            $pin,
            $masterPin,
            $stationName,
            $extraUsers
        ): array {
            // Blocaj atomic: verificare ca tabela users este inca goala in tranzactie
            $existing = (int) $pdo->query('SELECT COUNT(*) FROM users FOR UPDATE')->fetchColumn();
            if ($existing > 0) {
                throw new HttpException(409, 'ALREADY_INITIALIZED', 'Aplicația este deja inițializată.');
            }

            $now = $this->config->nowString();
            $this->settings->ensureDefaults($pdo);
            if ($stationName !== '') {
                $this->settings->updateStationConfig($pdo, ['station_name' => $stationName], $displayName . ' (Admin)');
            }

            $insAdmin = $pdo->prepare(
                'INSERT INTO users (username, display_name, role, pin_hash, master_pin_hash, is_primary_admin, is_active, must_change_pin, session_version, sort_order, created_at, updated_at)
                 VALUES (?, ?, \'admin\', ?, ?, 1, 1, 0, 1, 10, ?, ?)'
            );
            $insAdmin->execute([
                $username,
                $displayName,
                Security::hashPin($pin),
                Security::hashPin($masterPin),
                $now,
                $now,
            ]);
            $adminId = (int) $pdo->lastInsertId();

            $allPerms = ['programari.edit', 'programari.revenire', 'programari.delete'];
            $insPerm = $pdo->prepare('INSERT INTO user_permissions (user_id, permission) VALUES (?, ?)');
            foreach ($allPerms as $perm) {
                $insPerm->execute([$adminId, $perm]);
            }

            // Utilizatori operationali initiali (optionali, creati de Admin la configurare - zero PIN hardcodat)
            $sort = 20;
            $createdExtraCount = 0;
            $insUser = $pdo->prepare(
                'INSERT INTO users (username, display_name, role, pin_hash, master_pin_hash, is_primary_admin, is_active, must_change_pin, session_version, sort_order, created_at, updated_at)
                 VALUES (?, ?, \'user\', ?, NULL, 0, 1, 0, 1, ?, ?, ?)'
            );
            foreach ($extraUsers as $eu) {
                if (!is_array($eu)) {
                    continue;
                }
                $uName = trim((string) ($eu['display_name'] ?? ''));
                $uPin = (string) ($eu['pin'] ?? '');
                $uUser = strtolower(trim((string) ($eu['username'] ?? preg_replace('/[^a-z0-9]/i', '', $uName))));
                if ($uName === '' && $uUser === '' && $uPin === '') {
                    continue;
                }
                if (mb_strlen($uName) < 2) {
                    throw new HttpException(422, 'INVALID_NAME', 'Numele fiecărui utilizator suplimentar trebuie să aibă minim 2 caractere.');
                }
                if (preg_match('/^[a-z0-9_.-]{2,32}$/', $uUser) !== 1) {
                    throw new HttpException(422, 'INVALID_USERNAME', sprintf('Username invalid pentru utilizatorul "%s" (2–32 caractere alfanumerice).', $uName));
                }
                Security::assertValidPinFormat($uPin, false);
                try {
                    $insUser->execute([$uUser, $uName, Security::hashPin($uPin), $sort, $now, $now]);
                } catch (\PDOException $e) {
                    if (Database::isDuplicateKeyException($e)) {
                        throw new HttpException(409, 'USERNAME_EXISTS', sprintf('Username-ul "%s" este duplicat.', $uUser));
                    }
                    throw $e;
                }
                $uid = (int) $pdo->lastInsertId();
                $perms = is_array($eu['permissions'] ?? null) ? $eu['permissions'] : ['programari.edit', 'programari.revenire'];
                foreach ($perms as $p) {
                    if (in_array($p, $allPerms, true)) {
                        $insPerm->execute([$uid, $p]);
                    }
                }
                $createdExtraCount++;
                $sort += 10;
            }

            $sess = $this->auth->createSessionCookie($pdo, $adminId, 1, $req);
            $this->audit->log(
                $pdo,
                $adminId,
                $displayName . ' (Admin)',
                'FIRST_START_SETUP',
                sprintf('Inițializare aplicație: Admin principal %s creat și %d utilizatori operaționali.', $displayName, $createdExtraCount),
                'core',
                'user',
                (string) $adminId,
                null,
                ['username' => $username, 'display_name' => $displayName],
                $req
            );

            return [
                'ok' => true,
                'csrf_token' => $sess['csrf_token'],
                'user_id' => $adminId,
            ];
        });

        return Response::json($result, 201);
    }

    public function listLoginUsers(Request $req): array
    {
        // A02: Nu expunem username-ul intern sau flag-ul is_primary_admin pe endpoint-ul public de login
        $stmt = $this->db->pdo()->query(
            'SELECT id, display_name, role, is_active, (master_pin_hash IS NOT NULL) AS has_master_pin
             FROM users
             ORDER BY sort_order ASC, id ASC'
        );
        $rows = array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'display_name' => $r['display_name'],
            'role' => $r['role'],
            'is_active' => (int) $r['is_active'] === 1,
            'has_master_pin' => (int) $r['has_master_pin'] === 1,
        ], $stmt->fetchAll());

        return Response::json([
            'ok' => true,
            'users' => $rows,
        ]);
    }

    public function login(Request $req): array
    {
        $body = $req->json();
        $userId = (int) ($body['user_id'] ?? 0);
        $pin = (string) ($body['pin'] ?? '');
        $ip = $req->clientIp();

        if ($userId <= 0 || $pin === '') {
            throw new HttpException(422, 'INVALID_CREDENTIALS', 'Selectează utilizatorul și introdu PIN-ul.');
        }

        $pdo = $this->db->pdo();
        Security::enforceRateLimit($pdo, 'pin', $userId, $ip, $this->config);

        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            Security::recordLoginAttempt($pdo, null, $ip, 'pin', false, $this->config);
            throw new HttpException(401, 'LOGIN_FAILED', 'Utilizator sau PIN incorect.');
        }

        if ((int) $user['is_active'] !== 1) {
            Security::recordLoginAttempt($pdo, $userId, $ip, 'pin', false, $this->config);
            $this->audit->log(
                $pdo,
                $userId,
                sprintf('%s (%s)', $user['display_name'], $user['role'] === 'admin' ? 'Admin' : 'User'),
                'LOGIN_FAIL',
                'Încercare autentificare pe cont blocat.',
                'core',
                'user',
                (string) $userId,
                null,
                null,
                $req
            );
            throw new HttpException(403, 'USER_BLOCKED', 'Contul este blocat. Contactează Administratorul.');
        }

        if (!Security::verifyPin($pin, (string) $user['pin_hash'])) {
            Security::recordLoginAttempt($pdo, $userId, $ip, 'pin', false, $this->config);
            $this->audit->log(
                $pdo,
                $userId,
                sprintf('%s (%s)', $user['display_name'], $user['role'] === 'admin' ? 'Admin' : 'User'),
                'LOGIN_FAIL',
                'PIN introdus incorect.',
                'core',
                'user',
                (string) $userId,
                null,
                null,
                $req
            );
            throw new HttpException(401, 'LOGIN_FAILED', 'PIN incorect. Încearcă din nou.');
        }

        $payload = $this->db->transaction(function (\PDO $txPdo) use ($user, $ip, $req): array {
            $uid = (int) $user['id'];
            Security::recordLoginAttempt($txPdo, $uid, $ip, 'pin', true, $this->config);
            $sess = $this->auth->createSessionCookie($txPdo, $uid, (int) $user['session_version'], $req);
            $actorLabel = sprintf('%s (%s)', $user['display_name'], $user['role'] === 'admin' ? 'Admin' : 'User');
            $this->audit->log(
                $txPdo,
                $uid,
                $actorLabel,
                'LOGIN_OK',
                'Autentificare cu succes prin PIN.',
                'core',
                'user',
                (string) $uid,
                null,
                null,
                $req
            );
            return [
                'ok' => true,
                'csrf_token' => $sess['csrf_token'],
                'expires_at' => $sess['expires_at'],
            ];
        });

        return Response::json($payload);
    }

    public function masterRecovery(Request $req): array
    {
        $body = $req->json();
        $masterPin = (string) ($body['master_pin'] ?? '');
        $newPin = (string) ($body['new_pin'] ?? '');
        $ip = $req->clientIp();

        Security::assertValidPinFormat($masterPin, true);
        Security::assertValidPinFormat($newPin, false);

        $pdo = $this->db->pdo();
        Security::enforceRateLimit($pdo, 'master', null, $ip, $this->config);

        $payload = $this->db->transaction(function (\PDO $txPdo) use ($masterPin, $newPin, $ip, $req): array {
            $stmt = $txPdo->query('SELECT * FROM users WHERE is_primary_admin = 1 LIMIT 1 FOR UPDATE');
            $admin = $stmt->fetch();

            if (!$admin || empty($admin['master_pin_hash']) || !Security::verifyPin($masterPin, (string) $admin['master_pin_hash'])) {
                return [
                    '__failed' => true,
                    'admin_id' => $admin ? (int) $admin['id'] : null,
                    'actor_label' => $admin ? sprintf('%s (Admin)', $admin['display_name']) : 'Sistem',
                ];
            }

            $adminId = (int) $admin['id'];
            $nextVersion = ((int) $admin['session_version']) + 1;
            $now = $this->config->nowString();

            $upd = $txPdo->prepare(
                'UPDATE users SET pin_hash = ?, session_version = ?, must_change_pin = 0, updated_at = ? WHERE id = ?'
            );
            $upd->execute([Security::hashPin($newPin), $nextVersion, $now, $adminId]);

            // Delogheaza toate celelalte sesiuni ale Adminului principal
            $delSess = $txPdo->prepare('DELETE FROM sessions WHERE user_id = ?');
            $delSess->execute([$adminId]);

            Security::recordLoginAttempt($txPdo, $adminId, $ip, 'master', true, $this->config);
            $sess = $this->auth->createSessionCookie($txPdo, $adminId, $nextVersion, $req);

            $actorLabel = sprintf('%s (Admin)', $admin['display_name']);
            $this->audit->log(
                $txPdo,
                $adminId,
                $actorLabel,
                'ADMIN_MASTER_RECOVERY',
                'Preluare comandă Admin cu PIN Secret: PIN uzual înlocuit și toate sesiunile anterioare invalidate.',
                'core',
                'user',
                (string) $adminId,
                null,
                ['session_version' => $nextVersion],
                $req
            );

            return [
                'ok' => true,
                'csrf_token' => $sess['csrf_token'],
                'message' => 'Comandă Admin preluată! PIN-ul uzual a fost actualizat și celelalte sesiuni au fost delogate.',
            ];
        });

        // Inregistrare tentativa esuata in afara tranzactiei (nu este anulata de ROLLBACK)
        if (!empty($payload['__failed'])) {
            $failedAdminId = $payload['admin_id'] !== null ? (int) $payload['admin_id'] : null;
            Security::recordLoginAttempt($pdo, $failedAdminId, $ip, 'master', false, $this->config);
            $this->audit->log(
                $pdo,
                $failedAdminId,
                (string) $payload['actor_label'],
                'LOGIN_FAIL',
                'Încercare eșuată de preluare comandă Admin cu PIN Secret Master.',
                'core',
                'user',
                $failedAdminId !== null ? (string) $failedAdminId : null,
                null,
                null,
                $req
            );
            throw new HttpException(401, 'MASTER_PIN_INVALID', 'PIN-ul Secret Master este incorect.');
        }

        return Response::json($payload);
    }

    public function changePin(Request $req): array
    {
        $ctx = $this->auth->requireUser($req, true);
        $body = $req->json();
        $currentPin = (string) ($body['current_pin'] ?? '');
        $newPin = (string) ($body['new_pin'] ?? '');

        if ($currentPin === '') {
            throw new HttpException(422, 'MISSING_CURRENT_PIN', 'Introdu PIN-ul actual.');
        }
        Security::assertValidPinFormat($newPin, false);
        if ($currentPin === $newPin) {
            throw new HttpException(422, 'SAME_PIN', 'PIN-ul nou trebuie să fie diferit de cel actual.');
        }

        $userId = (int) $ctx['user']['id'];
        $ip = $req->clientIp();
        $pdo = $this->db->pdo();
        Security::enforceRateLimit($pdo, 'pin', $userId, $ip, $this->config);

        $payload = $this->db->transaction(function (\PDO $txPdo) use ($ctx, $userId, $ip, $currentPin, $newPin, $req): array {
            $stmt = $txPdo->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();

            if (!$row || !Security::verifyPin($currentPin, (string) $row['pin_hash'])) {
                return ['__failed' => true];
            }

            $nextVersion = ((int) $row['session_version']) + 1;
            $now = $this->config->nowString();

            $upd = $txPdo->prepare(
                'UPDATE users SET pin_hash = ?, session_version = ?, must_change_pin = 0, updated_at = ? WHERE id = ?'
            );
            $upd->execute([Security::hashPin($newPin), $nextVersion, $now, $userId]);

            // Pastreaza sesiunea curenta activa, invalideaza eventualele sesiuni vechi de pe alte dispozitive
            $delOther = $txPdo->prepare('DELETE FROM sessions WHERE user_id = ? AND token_hash <> ?');
            $delOther->execute([$userId, $ctx['session']['token_hash']]);

            $updCurr = $txPdo->prepare('UPDATE sessions SET session_version = ? WHERE token_hash = ?');
            $updCurr->execute([$nextVersion, $ctx['session']['token_hash']]);

            Security::recordLoginAttempt($txPdo, $userId, $ip, 'pin', true, $this->config);

            $this->audit->log(
                $txPdo,
                $userId,
                $ctx['user']['actor_label'],
                'PIN_CHANGE',
                'Utilizatorul și-a schimbat propriul PIN uzual.',
                'core',
                'user',
                (string) $userId,
                null,
                null,
                $req
            );

            return [
                'ok' => true,
                'message' => 'PIN actualizat cu succes.',
            ];
        });

        if (!empty($payload['__failed'])) {
            Security::recordLoginAttempt($pdo, $userId, $ip, 'pin', false, $this->config);
            $this->audit->log(
                $pdo,
                $userId,
                $ctx['user']['actor_label'],
                'LOGIN_FAIL',
                'PIN actual introdus greșit la schimbarea PIN-ului.',
                'core',
                'user',
                (string) $userId,
                null,
                null,
                $req
            );
            // 422 (nu 401): sesiunea ramane autentificata, doar validarea PIN-ului actual a esuat
            throw new HttpException(422, 'INVALID_CURRENT_PIN', 'PIN-ul actual nu este corect.');
        }

        return Response::json($payload);
    }

    public function logout(Request $req): array
    {
        $ctx = $this->auth->requireUser($req, true);
        $this->db->transaction(function (\PDO $pdo) use ($ctx, $req): void {
            $del = $pdo->prepare('DELETE FROM sessions WHERE token_hash = ?');
            $del->execute([$ctx['session']['token_hash']]);

            $this->audit->log(
                $pdo,
                (int) $ctx['user']['id'],
                $ctx['user']['actor_label'],
                'LOGOUT',
                'Deconectare din aplicație.',
                'core',
                'user',
                (string) $ctx['user']['id'],
                null,
                null,
                $req
            );
        });

        $this->auth->clearSessionCookie($req);
        return Response::json(['ok' => true]);
    }

    public function me(Request $req): array
    {
        $ctx = $this->auth->requireUser($req, false);
        $cfg = $this->settings->getStationConfig();

        return Response::json([
            'ok' => true,
            'user' => $ctx['user'],
            'permissions' => $ctx['permissions'],
            'csrf_token' => $ctx['session']['csrf_token'],
            'today_date' => $this->config->todayIso(),
            'now_time' => $this->config->now()->format('H:i'),
            'station_config' => $cfg,
            'daily_slots' => $this->settings->buildDailySlots($cfg),
            'modules' => $this->modules->listAllWithState(),
        ]);
    }

    public function menu(Request $req): array
    {
        $ctx = $this->auth->requireUser($req, false);
        $modules = array_values(array_filter(
            $this->modules->listAllWithState(),
            static fn(array $m): bool => (bool) $m['enabled']
        ));

        return Response::json([
            'ok' => true,
            'role' => $ctx['user']['role'],
            'modules' => $modules,
        ]);
    }
}
