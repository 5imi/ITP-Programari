<?php
declare(strict_types=1);

namespace Modules\Programari;

use Core\AuditLogger;
use Core\Config;
use Core\Database;
use Core\HttpException;
use Core\Request;
use Core\SettingsService;

/**
 * Logica tranzactionala anti race-condition pentru programari, reveniri 30 zile, motive si Istoric Imutabil.
 */
final class AppointmentService
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly SettingsService $settings,
        private readonly AppointmentRepo $repo,
        private readonly HistoryService $history,
        private readonly AuditLogger $audit
    ) {
    }

    public function getAgenda(array $query): array
    {
        $cfg = $this->settings->getStationConfig();
        $today = $this->config->todayIso();
        $selectedDate = isset($query['date']) && trim((string) $query['date']) !== ''
            ? Validators::assertValidDate(trim((string) $query['date']))
            : $today;
        $searchAll = !empty($query['search_all']) && $query['search_all'] !== '0' && $query['search_all'] !== 'false';
        $includeFuture = !isset($query['include_future']) || ($query['include_future'] !== '0' && $query['include_future'] !== 'false');
        $q = trim((string) ($query['q'] ?? ''));
        $line = trim((string) ($query['line'] ?? 'Toate'));
        $status = trim((string) ($query['status'] ?? 'Toate'));

        $pdo = $this->db->pdo();
        $lists = $this->repo->listAgenda($pdo, $selectedDate, $today, $searchAll, $q, $line, $status, $includeFuture);
        $occ = $this->repo->countOccupancyByLine($pdo, $selectedDate);

        return [
            'today_date' => $today,
            'selected_date' => $selectedDate,
            'appointments' => $lists['appointments'],
            'future_appointments' => $lists['future_appointments'],
            'occupancy_by_line' => $occ,
            'daily_slots' => $this->settings->buildDailySlots($cfg),
            'station_config' => $cfg,
        ];
    }

    public function getFreeSlots(string $dateRaw, string $lineRaw, ?int $excludeId = null): array
    {
        $cfg = $this->settings->getStationConfig();
        $date = Validators::assertValidDate(trim($dateRaw));
        $line = Validators::assertValidLine($lineRaw, $cfg);
        $allSlots = $this->settings->buildDailySlots($cfg);

        $sql = 'SELECT appt_time, line FROM appointments WHERE appt_date = ? AND deleted_at IS NULL';
        $args = [$date];
        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND id <> ?';
            $args[] = $excludeId;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($args);

        $taken = [];
        $takenBySlot = [];
        $takenCountByLine = [];
        foreach ($cfg['lines'] as $ln) {
            $takenCountByLine[$ln] = 0;
        }
        foreach ($stmt->fetchAll() as $row) {
            $slotTime = (string) $row['appt_time'];
            $slotLine = (string) $row['line'];
            if (!isset($takenBySlot[$slotTime])) {
                $takenBySlot[$slotTime] = [];
            }
            if (!in_array($slotLine, $takenBySlot[$slotTime], true)) {
                $takenBySlot[$slotTime][] = $slotLine;
            }
            if (array_key_exists($slotLine, $takenCountByLine)) {
                $takenCountByLine[$slotLine]++;
            }
            if ($slotLine === $line) {
                $taken[] = $slotTime;
            }
        }
        $taken = array_values(array_unique($taken));

        $totalSlotsCount = count($allSlots);
        $freeCountByLine = [];
        foreach ($takenCountByLine as $ln => $cnt) {
            $freeCountByLine[$ln] = max(0, $totalSlotsCount - $cnt);
        }

        return [
            'date' => $date,
            'line' => $line,
            'is_closed_day' => Validators::isClosedDay($date, $cfg),
            'all_slots' => $allSlots,
            'taken_slots' => $taken,
            'free_slots' => array_values(array_diff($allSlots, $taken)),
            'taken_by_slot' => (object) $takenBySlot,
            'free_count_by_line' => (object) $freeCountByLine,
        ];
    }

    public function lookupByPlate(string $plateRaw): ?array
    {
        $compact = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $plateRaw));
        if (strlen($compact) < 4) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT plate, vehicle, phone, client, appt_date, status
             FROM appointments
             WHERE plate_key = ? AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$compact]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $body, array $user, Request $req): array
    {
        $cfg = $this->settings->getStationConfig();
        $validated = $this->validatePayload($body, $cfg, $user);

        return $this->db->transaction(function (\PDO $pdo) use ($body, $validated, $user, $req): array {
            $dup = $this->repo->findSameDayPlateConflict($pdo, $validated['plate_key'], $validated['date']);
            if ($dup && empty($body['confirm_duplicate'])) {
                throw new HttpException(
                    409,
                    'DUPLICATE_PLATE',
                    sprintf(
                        'Numărul %s are deja programare pe %s la %s (%s). Confirmă dacă dorești dublură.',
                        $dup['plate'],
                        Validators::formatDateRo((string) $dup['appt_date']),
                        $dup['appt_time'],
                        $dup['line']
                    ),
                    ['conflict' => $dup]
                );
            }

            $now = $this->config->nowString();
            try {
                $newId = $this->repo->insert($pdo, [
                    'appt_date' => $validated['date'],
                    'appt_time' => $validated['time'],
                    'line' => $validated['line'],
                    'plate' => $validated['plate'],
                    'plate_key' => $validated['plate_key'],
                    'vehicle' => $validated['vehicle'],
                    'phone' => $validated['phone'],
                    'client' => $validated['client'],
                    'status' => 'Programat',
                    'visit_type' => 'NORMAL',
                    'created_by' => (int) $user['id'],
                    'created_at' => $now,
                    'updated_by' => (int) $user['id'],
                    'updated_at' => $now,
                ]);
            } catch (\PDOException $e) {
                if (Database::isDuplicateKeyException($e)) {
                    throw new HttpException(
                        409,
                        'SLOT_TAKEN',
                        sprintf('Slotul %s %s (%s) tocmai a fost rezervat de alt coleg.', Validators::formatDateRo($validated['date']), $validated['time'], $validated['line'])
                    );
                }
                throw $e;
            }

            $created = $this->repo->findActiveById($pdo, $newId);
            $extraNotes = [];
            if ($dup) {
                $extraNotes[] = 'duplicat confirmat';
            }
            if ($validated['is_closed_admin']) {
                $extraNotes[] = 'excepție zi închisă Admin';
            }
            $suffix = $extraNotes ? ' (' . implode(', ', $extraNotes) . ')' : '';

            $this->history->record(
                $pdo,
                $newId,
                'CREAT',
                (int) $user['id'],
                (string) $user['actor_label'],
                sprintf(
                    'Programare creată pentru %s la %s (%s)%s',
                    Validators::formatDateRo($validated['date']),
                    $validated['time'],
                    $validated['line'],
                    $suffix
                ),
                null,
                $created,
                null,
                $req->clientIp()
            );

            return $created ?? [];
        });
    }

    public function update(int $id, array $body, array $user, Request $req): array
    {
        $cfg = $this->settings->getStationConfig();
        $validated = $this->validatePayload($body, $cfg, $user);
        $expectedVersion = (int) ($body['version'] ?? 0);
        if ($expectedVersion <= 0) {
            throw new HttpException(422, 'VERSION_REQUIRED', 'Lipsește câmpul version pentru editare concurentă.');
        }

        return $this->db->transaction(function (\PDO $pdo) use ($id, $body, $validated, $expectedVersion, $user, $req): array {
            $existing = $this->repo->lockActiveByIdForUpdate($pdo, $id);
            if (!$existing) {
                throw new HttpException(404, 'APPOINTMENT_NOT_FOUND', 'Programarea nu există sau a fost ștearsă.');
            }
            if ($existing['date'] < $this->config->todayIso() && $user['role'] !== 'admin') {
                throw new HttpException(403, 'PAST_DAY_ADMIN_ONLY', 'Zilele trecute pot fi modificate doar de Administrator.');
            }
            if ((int) $existing['version'] !== $expectedVersion) {
                throw new HttpException(
                    409,
                    'STALE_VERSION',
                    'Programarea a fost modificată între timp de alt coleg. Reîncarcă agenda înainte de a salva.',
                    ['current_version' => (int) $existing['version'], 'current' => $existing]
                );
            }

            $excludeParent = $existing['parent_id'];
            $dup = $this->repo->findSameDayPlateConflict($pdo, $validated['plate_key'], $validated['date'], $id, $excludeParent);
            if ($dup && empty($body['confirm_duplicate'])) {
                throw new HttpException(
                    409,
                    'DUPLICATE_PLATE',
                    sprintf(
                        'Numărul %s are deja programare pe %s la %s (%s).',
                        $dup['plate'],
                        Validators::formatDateRo((string) $dup['appt_date']),
                        $dup['appt_time'],
                        $dup['line']
                    ),
                    ['conflict' => $dup]
                );
            }

            $overDeadline = $existing['visit_type'] === 'REVENIRE'
                && !empty($existing['reinspection_deadline'])
                && $validated['date'] > $existing['reinspection_deadline'];

            if ($overDeadline && empty($body['confirm_over_deadline'])) {
                throw new HttpException(
                    409,
                    'OVER_DEADLINE',
                    sprintf(
                        'Data aleasă (%s) depășește termenul de 30 zile (%s). Dacă salvezi, trece pe ITP complet.',
                        Validators::formatDateRo($validated['date']),
                        Validators::formatDateRo((string) $existing['reinspection_deadline'])
                    )
                );
            }

            $changes = [];
            if ($existing['date'] !== $validated['date']) {
                $changes[] = sprintf('Dată: %s → %s', Validators::formatDateRo($existing['date']), Validators::formatDateRo($validated['date']));
            }
            if ($existing['time'] !== $validated['time']) {
                $changes[] = sprintf('Oră: %s → %s', $existing['time'], $validated['time']);
            }
            if ($existing['line'] !== $validated['line']) {
                $changes[] = sprintf('Linie: %s → %s', $existing['line'], $validated['line']);
            }
            if ($existing['plate'] !== $validated['plate']) {
                $changes[] = sprintf('Nr. auto: %s → %s', $existing['plate'], $validated['plate']);
            }
            if ($existing['phone'] !== $validated['phone']) {
                $changes[] = sprintf('Telefon: %s → %s', $existing['phone'], $validated['phone']);
            }
            if ($existing['client'] !== $validated['client']) {
                $changes[] = sprintf('Client: %s → %s', $existing['client'], $validated['client']);
            }
            if (($existing['vehicle'] ?? '') !== $validated['vehicle']) {
                $changes[] = sprintf('Mașină: %s → %s', $existing['vehicle'] ?? '-', $validated['vehicle'] !== '' ? $validated['vehicle'] : '-');
            }

            if (empty($changes) && !$overDeadline) {
                return $existing;
            }

            $nextVisitType = $overDeadline ? 'NORMAL' : $existing['visit_type'];
            $nextDeadline = $overDeadline ? null : $existing['reinspection_deadline'];
            $nextVerify = $overDeadline ? '' : $existing['verify_notes'];

            $upd = $pdo->prepare(
                'UPDATE appointments
                 SET appt_date = ?, appt_time = ?, line = ?, plate = ?, plate_key = ?, vehicle = ?, phone = ?, client = ?,
                     visit_type = ?, reinspection_deadline = ?, verify_notes = ?,
                     version = version + 1, updated_by = ?, updated_at = ?
                 WHERE id = ? AND version = ?'
            );

            try {
                $upd->execute([
                    $validated['date'],
                    $validated['time'],
                    $validated['line'],
                    $validated['plate'],
                    $validated['plate_key'],
                    $validated['vehicle'],
                    $validated['phone'],
                    $validated['client'],
                    $nextVisitType,
                    $nextDeadline,
                    $nextVerify,
                    (int) $user['id'],
                    $this->config->nowString(),
                    $id,
                    $expectedVersion,
                ]);
            } catch (\PDOException $e) {
                if (Database::isDuplicateKeyException($e)) {
                    throw new HttpException(
                        409,
                        'SLOT_TAKEN',
                        sprintf('Slotul %s %s (%s) este deja ocupat.', Validators::formatDateRo($validated['date']), $validated['time'], $validated['line'])
                    );
                }
                throw $e;
            }

            $after = $this->repo->findActiveById($pdo, $id);
            $diffSummary = implode(' | ', $changes)
                . ($overDeadline ? ' | Trecut pe ITP complet (peste 30 zile)' : '')
                . ($dup ? ' | Duplicat confirmat' : '')
                . ($validated['is_closed_admin'] ? ' | Excepție Admin zi închisă' : '');

            $this->history->record(
                $pdo,
                $id,
                'MODIFICAT',
                (int) $user['id'],
                (string) $user['actor_label'],
                $diffSummary,
                $existing,
                $after,
                null,
                $req->clientIp()
            );

            return $after ?? [];
        });
    }

    public function markRevenire(int $id, array $body, array $user, Request $req): array
    {
        $cfg = $this->settings->getStationConfig();
        $expectedVersion = (int) ($body['version'] ?? 0);
        if ($expectedVersion <= 0) {
            throw new HttpException(422, 'VERSION_REQUIRED', 'Lipsește câmpul version.');
        }

        $reasons = array_values(array_filter(array_map(
            static fn($v): string => trim((string) $v),
            (array) ($body['reasons'] ?? [])
        ), static fn(string $v): bool => $v !== ''));
        $note = trim((string) ($body['note'] ?? ''));

        if (empty($reasons) && $note === '') {
            throw new HttpException(422, 'REVENIRE_REASON_REQUIRED', 'Selectează cel puțin un motiv sau introdu o observație.');
        }

        $reinspDays = max(1, (int) ($cfg['reinspection_days'] ?? 30));

        return $this->db->transaction(function (\PDO $pdo) use ($id, $expectedVersion, $reasons, $note, $reinspDays, $user, $req): array {
            $existing = $this->repo->lockActiveByIdForUpdate($pdo, $id);
            if (!$existing) {
                throw new HttpException(404, 'APPOINTMENT_NOT_FOUND', 'Programarea nu există.');
            }
            if ($existing['date'] < $this->config->todayIso() && $user['role'] !== 'admin') {
                throw new HttpException(403, 'PAST_DAY_ADMIN_ONLY', 'Zilele din istoric pot fi modificate doar de Administrator.');
            }
            if ((int) $existing['version'] !== $expectedVersion) {
                throw new HttpException(409, 'STALE_VERSION', 'Programarea a fost modificată între timp. Reîncarcă agenda.');
            }

            $upd = $pdo->prepare(
                "UPDATE appointments
                 SET status = 'Revenire', reject_reasons_json = ?, reject_note = ?, version = version + 1, updated_by = ?, updated_at = ?
                 WHERE id = ? AND version = ?"
            );
            $upd->execute([
                json_encode($reasons, JSON_UNESCAPED_UNICODE),
                substr($note, 0, 500),
                (int) $user['id'],
                $this->config->nowString(),
                $id,
                $expectedVersion,
            ]);

            $after = $this->repo->findActiveById($pdo, $id);
            $deadline = Validators::addDaysIso($existing['date'], $reinspDays);
            $reasonText = implode(', ', array_filter([implode(', ', $reasons), $note]));

            $this->history->record(
                $pdo,
                $id,
                'REVENIRE',
                (int) $user['id'],
                (string) $user['actor_label'],
                sprintf('Marcat Revenire %d zile (până la %s) • %s', $reinspDays, Validators::formatDateRo($deadline), $reasonText),
                $existing,
                $after,
                $reasonText,
                $req->clientIp()
            );

            return $after ?? [];
        });
    }

    public function revokeRevenire(int $id, array $body, array $user, Request $req): array
    {
        $expectedVersion = (int) ($body['version'] ?? 0);
        if ($expectedVersion <= 0) {
            throw new HttpException(422, 'VERSION_REQUIRED', 'Lipsește câmpul version.');
        }

        return $this->db->transaction(function (\PDO $pdo) use ($id, $expectedVersion, $user, $req): array {
            $existing = $this->repo->lockActiveByIdForUpdate($pdo, $id);
            if (!$existing) {
                throw new HttpException(404, 'APPOINTMENT_NOT_FOUND', 'Programarea nu există.');
            }
            if ($existing['status'] !== 'Revenire') {
                throw new HttpException(409, 'NOT_IN_REVENIRE', 'Programarea nu este marcată ca Revenire.');
            }
            if ($existing['date'] < $this->config->todayIso() && $user['role'] !== 'admin') {
                throw new HttpException(403, 'PAST_DAY_ADMIN_ONLY', 'Zilele din istoric pot fi modificate doar de Administrator.');
            }
            if ((int) $existing['version'] !== $expectedVersion) {
                throw new HttpException(409, 'STALE_VERSION', 'Programarea a fost modificată între timp. Reîncarcă agenda.');
            }

            $upd = $pdo->prepare(
                "UPDATE appointments
                 SET status = 'Programat', reject_reasons_json = '[]', reject_note = '', version = version + 1, updated_by = ?, updated_at = ?
                 WHERE id = ? AND version = ?"
            );
            $upd->execute([(int) $user['id'], $this->config->nowString(), $id, $expectedVersion]);

            $after = $this->repo->findActiveById($pdo, $id);
            $prevReasons = implode(', ', array_filter([implode(', ', $existing['reject_reasons']), $existing['reject_note']]));

            $this->history->record(
                $pdo,
                $id,
                'REVOCAT',
                (int) $user['id'],
                (string) $user['actor_label'],
                sprintf('Revocată starea Revenire → readus la Programat%s', $prevReasons !== '' ? ' (motiv anterior: ' . $prevReasons . ')' : ''),
                $existing,
                $after,
                null,
                $req->clientIp()
            );

            return $after ?? [];
        });
    }

    public function reprogramFromRevenire(int $parentId, array $body, array $user, Request $req): array
    {
        $cfg = $this->settings->getStationConfig();
        $date = Validators::assertValidDate(trim((string) ($body['date'] ?? '')));
        $time = Validators::assertValidSlot((string) ($body['time'] ?? ''), $this->settings->buildDailySlots($cfg));
        $line = Validators::assertValidLine((string) ($body['line'] ?? ''), $cfg);

        if ($date < $this->config->todayIso() && $user['role'] !== 'admin') {
            throw new HttpException(422, 'PAST_DATE', 'Doar Administratorul poate programa într-o dată trecută.');
        }
        $isClosed = Validators::isClosedDay($date, $cfg);
        if ($isClosed && $user['role'] !== 'admin') {
            throw new HttpException(422, 'INVALID_DAY', sprintf('Stația ITP este închisă pe %s.', Validators::formatDateRo($date)));
        }
        if ($isClosed && empty($body['confirm_sunday'])) {
            throw new HttpException(409, 'SUNDAY_CONFIRM_REQUIRED', 'Ziua aleasă este închisă. Confirmă excepția Admin.');
        }

        $reinspDays = max(1, (int) ($cfg['reinspection_days'] ?? 30));

        return $this->db->transaction(function (\PDO $pdo) use ($parentId, $body, $date, $time, $line, $reinspDays, $user, $req): array {
            $parent = $this->repo->lockActiveByIdForUpdate($pdo, $parentId);
            if (!$parent) {
                throw new HttpException(404, 'PARENT_NOT_FOUND', 'Programarea inițială nu există.');
            }
            if ($parent['status'] !== 'Revenire') {
                throw new HttpException(409, 'NOT_REVENIRE', 'Doar o programare marcată Revenire poate fi reprogramată pentru revenire.');
            }
            if ($parent['resolved_by_reinspection_id'] !== null) {
                throw new HttpException(409, 'ALREADY_REPROGRAMMED', 'Există deja o vizită de revenire programată pentru această mașină.');
            }

            $deadline = Validators::addDaysIso($parent['date'], $reinspDays);
            $overDeadline = $date > $deadline;
            if ($overDeadline && empty($body['confirm_over_deadline'])) {
                throw new HttpException(
                    409,
                    'OVER_DEADLINE',
                    sprintf('Data aleasă (%s) depășește termenul de %d zile (%s). Confirmă pentru ITP complet.', Validators::formatDateRo($date), $reinspDays, Validators::formatDateRo($deadline))
                );
            }

            $dup = $this->repo->findSameDayPlateConflict($pdo, $parent['plate_key'], $date, null, $parentId);
            if ($dup && empty($body['confirm_duplicate'])) {
                throw new HttpException(409, 'DUPLICATE_PLATE', sprintf('Numărul %s are deja programare pe %s.', $dup['plate'], Validators::formatDateRo($date)));
            }

            $verifySummary = implode(' — ', array_filter([
                implode(', ', $parent['reject_reasons']),
                $parent['reject_note'],
            ]));

            $now = $this->config->nowString();
            try {
                $childId = $this->repo->insert($pdo, [
                    'appt_date' => $date,
                    'appt_time' => $time,
                    'line' => $line,
                    'plate' => $parent['plate'],
                    'plate_key' => $parent['plate_key'],
                    'vehicle' => $parent['vehicle'] ?? '',
                    'phone' => $parent['phone'],
                    'client' => $parent['client'],
                    'status' => 'Programat',
                    'visit_type' => $overDeadline ? 'NORMAL' : 'REVENIRE',
                    'parent_id' => $parentId,
                    'reinspection_deadline' => $overDeadline ? null : $deadline,
                    'verify_notes' => $overDeadline ? '' : substr($verifySummary, 0, 500),
                    'created_by' => (int) $user['id'],
                    'created_at' => $now,
                    'updated_by' => (int) $user['id'],
                    'updated_at' => $now,
                ]);
            } catch (\PDOException $e) {
                if (Database::isDuplicateKeyException($e)) {
                    throw new HttpException(409, 'SLOT_TAKEN', sprintf('Slotul %s %s (%s) este deja ocupat.', Validators::formatDateRo($date), $time, $line));
                }
                throw $e;
            }

            $updParent = $pdo->prepare('UPDATE appointments SET resolved_by_reinspection_id = ?, version = version + 1, updated_by = ?, updated_at = ? WHERE id = ?');
            $updParent->execute([$childId, (int) $user['id'], $now, $parentId]);

            $child = $this->repo->findActiveById($pdo, $childId);

            $this->history->record(
                $pdo,
                $parentId,
                'REPROGRAMAT',
                (int) $user['id'],
                (string) $user['actor_label'],
                sprintf(
                    'Reprogramată %s pe %s la %s (%s)',
                    $overDeadline ? 'ca ITP complet (peste 30 zile)' : 'revenirea',
                    Validators::formatDateRo($date),
                    $time,
                    $line
                ),
                null,
                ['child_id' => $childId],
                null,
                $req->clientIp()
            );

            $this->history->record(
                $pdo,
                $childId,
                'CREAT',
                (int) $user['id'],
                (string) $user['actor_label'],
                sprintf(
                    '%s programată din %s pentru %s la %s (%s)',
                    $overDeadline ? 'ITP complet (termen 30 zile depășit)' : 'Revenire',
                    Validators::formatDateRo($parent['date']),
                    Validators::formatDateRo($date),
                    $time,
                    $line
                ),
                null,
                $child,
                null,
                $req->clientIp()
            );

            return $child ?? [];
        });
    }

    public function softDelete(int $id, array $body, array $user, Request $req): array
    {
        $expectedVersion = (int) ($body['version'] ?? 0);
        if ($expectedVersion <= 0) {
            throw new HttpException(422, 'VERSION_REQUIRED', 'Lipsește câmpul version.');
        }
        $reason = trim((string) ($body['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) < 2) {
            throw new HttpException(422, 'DELETE_REASON_REQUIRED', 'Selectează sau introdu motivul ștergerii.');
        }

        return $this->db->transaction(function (\PDO $pdo) use ($id, $expectedVersion, $reason, $user, $req): array {
            $existing = $this->repo->lockActiveByIdForUpdate($pdo, $id);
            if (!$existing) {
                throw new HttpException(404, 'APPOINTMENT_NOT_FOUND', 'Programarea nu există sau a fost deja ștearsă.');
            }
            if ((int) $existing['version'] !== $expectedVersion) {
                throw new HttpException(409, 'STALE_VERSION', 'Programarea a fost modificată între timp de alt coleg.');
            }

            $now = $this->config->nowString();
            $upd = $pdo->prepare(
                'UPDATE appointments
                 SET deleted_at = ?, deleted_by = ?, delete_reason = ?, version = version + 1, updated_by = ?, updated_at = ?
                 WHERE id = ? AND version = ?'
            );
            $upd->execute([$now, (int) $user['id'], substr($reason, 0, 255), (int) $user['id'], $now, $id, $expectedVersion]);

            $this->history->record(
                $pdo,
                $id,
                'ȘTERS',
                (int) $user['id'],
                (string) $user['actor_label'],
                sprintf('Șters (%s) — slot %s %s (%s) eliberat', $reason, Validators::formatDateRo($existing['date']), $existing['time'], $existing['line']),
                $existing,
                null,
                $reason,
                $req->clientIp()
            );

            // Eliberare automata legatura pe vizita-parinte daca s-a sters o vizita REVENIRE
            if ($existing['parent_id'] !== null) {
                $parent = $this->repo->findByIdIncludingDeletedForUpdate($pdo, (int) $existing['parent_id']);
                if ($parent && (int) $parent['resolved_by_reinspection_id'] === $id) {
                    $pdo->prepare('UPDATE appointments SET resolved_by_reinspection_id = NULL, version = version + 1, updated_by = ?, updated_at = ? WHERE id = ?')
                        ->execute([(int) $user['id'], $now, (int) $parent['id']]);
                    $this->history->record(
                        $pdo,
                        (int) $parent['id'],
                        'REPROGRAMAT',
                        (int) $user['id'],
                        (string) $user['actor_label'],
                        sprintf('Programarea de revenire din %s a fost ștearsă (%s) — redeschisă pentru reprogramare', Validators::formatDateRo($existing['date']), $reason),
                        null,
                        null,
                        $reason,
                        $req->clientIp()
                    );
                }
            }

            return ['ok' => true, 'deleted_id' => $id];
        });
    }

    public function getHistory(array $query): array
    {
        $q = trim((string) ($query['q'] ?? ''));
        $eventType = trim((string) ($query['event_type'] ?? 'Toate'));
        $actor = trim((string) ($query['actor'] ?? 'TOȚI'));
        $intactFilter = trim((string) ($query['scope'] ?? ''));
        $since = $this->config->now()->modify('-60 days')->format('Y-m-d 00:00:00');

        return [
            'entries' => $this->repo->listHistoryEntries($this->db->pdo(), $q, $eventType, $actor, $intactFilter, $since),
        ];
    }

    public function listReasons(?string $kind = null): array
    {
        return $this->repo->listReasons($this->db->pdo(), $kind);
    }

    public function addReason(string $kind, string $label, array $user, Request $req): array
    {
        $kind = $kind === 'stergere' ? 'stergere' : 'revenire';
        $label = trim($label);
        if (mb_strlen($label) < 2 || mb_strlen($label) > 60) {
            throw new HttpException(422, 'INVALID_REASON', 'Motivul trebuie să aibă între 2 și 60 de caractere.');
        }

        return $this->db->transaction(function (\PDO $pdo) use ($kind, $label, $user, $req): array {
            try {
                $stmt = $pdo->prepare('INSERT INTO reasons (kind, label, sort_order, is_active) VALUES (?, ?, 200, 1)');
                $stmt->execute([$kind, $label]);
            } catch (\PDOException $e) {
                if (Database::isDuplicateKeyException($e)) {
                    throw new HttpException(409, 'REASON_EXISTS', sprintf('Motivul "%s" există deja în listă.', $label));
                }
                throw $e;
            }
            $id = (int) $pdo->lastInsertId();
            $this->audit->log($pdo, (int) $user['id'], $user['actor_label'], 'REASON_ADD', sprintf('Adăugat motiv %s: "%s"', $kind, $label), 'programari', 'reason', (string) $id, null, null, $req);
            return ['id' => $id, 'kind' => $kind, 'label' => $label];
        });
    }

    public function updateReason(int $id, string $label, array $user, Request $req): array
    {
        $label = trim($label);
        if (mb_strlen($label) < 2 || mb_strlen($label) > 60) {
            throw new HttpException(422, 'INVALID_REASON', 'Motivul trebuie să aibă între 2 și 60 de caractere.');
        }

        return $this->db->transaction(function (\PDO $pdo) use ($id, $label, $user, $req): array {
            $stmt = $pdo->prepare('SELECT * FROM reasons WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new HttpException(404, 'REASON_NOT_FOUND', 'Motivul nu există.');
            }
            try {
                $upd = $pdo->prepare('UPDATE reasons SET label = ? WHERE id = ?');
                $upd->execute([$label, $id]);
            } catch (\PDOException $e) {
                if (Database::isDuplicateKeyException($e)) {
                    throw new HttpException(409, 'REASON_EXISTS', sprintf('Motivul "%s" există deja.', $label));
                }
                throw $e;
            }
            $this->audit->log($pdo, (int) $user['id'], $user['actor_label'], 'REASON_UPDATE', sprintf('Editat motiv %s: "%s" → "%s"', $row['kind'], $row['label'], $label), 'programari', 'reason', (string) $id, null, null, $req);
            return ['id' => $id, 'kind' => $row['kind'], 'label' => $label];
        });
    }

    public function deleteReason(int $id, array $user, Request $req): void
    {
        $this->db->transaction(function (\PDO $pdo) use ($id, $user, $req): void {
            $stmt = $pdo->prepare('SELECT * FROM reasons WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new HttpException(404, 'REASON_NOT_FOUND', 'Motivul nu există.');
            }
            $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM reasons WHERE kind = ? AND is_active = 1');
            $cntStmt->execute([$row['kind']]);
            if ((int) $cntStmt->fetchColumn() <= 1) {
                throw new HttpException(409, 'LAST_REASON_PROTECTED', 'Trebuie să rămână cel puțin un motiv în listă.');
            }
            $pdo->prepare('DELETE FROM reasons WHERE id = ?')->execute([$id]);
            $this->audit->log($pdo, (int) $user['id'], $user['actor_label'], 'REASON_DELETE', sprintf('Șters motiv %s: "%s"', $row['kind'], $row['label']), 'programari', 'reason', (string) $id, null, null, $req);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(array $body, array $cfg, array $user): array
    {
        $date = Validators::assertValidDate(trim((string) ($body['date'] ?? '')));
        $time = Validators::assertValidSlot((string) ($body['time'] ?? ''), $this->settings->buildDailySlots($cfg));
        $line = Validators::assertValidLine((string) ($body['line'] ?? ''), $cfg);
        $plateNorm = Validators::normalizePlate((string) ($body['plate'] ?? ''));
        $phone = Validators::normalizePhone((string) ($body['phone'] ?? ''));
        $clientRaw = trim((string) ($body['client'] ?? ''));
        $vehicleRaw = trim((string) ($body['vehicle'] ?? ''));
        if ($vehicleRaw === '' && str_contains($clientRaw, '•')) {
            $parts = array_values(array_filter(array_map('trim', explode('•', $clientRaw)), static fn(string $s): bool => $s !== ''));
            $clientRaw = $parts[0] ?? $clientRaw;
            $vehicleRaw = $parts[1] ?? '';
        }

        if ($date < $this->config->todayIso() && $user['role'] !== 'admin') {
            throw new HttpException(422, 'PAST_DATE', 'Doar Administratorul poate opera în date trecute.');
        }

        $isClosed = Validators::isClosedDay($date, $cfg);
        if ($isClosed && $user['role'] !== 'admin') {
            throw new HttpException(422, 'INVALID_DAY', sprintf('Stația ITP este închisă pe %s. Alege o zi lucrătoare.', Validators::formatDateRo($date)));
        }
        if ($isClosed && empty($body['confirm_sunday'])) {
            throw new HttpException(409, 'SUNDAY_CONFIRM_REQUIRED', sprintf('Ziua %s este închisă. Confirmă excepția Admin.', Validators::formatDateRo($date)));
        }

        return [
            'date' => $date,
            'time' => $time,
            'line' => $line,
            'plate' => $plateNorm['plate'],
            'plate_key' => $plateNorm['plate_key'],
            'vehicle' => substr($vehicleRaw, 0, 120),
            'phone' => $phone,
            'client' => substr($clientRaw !== '' ? $clientRaw : $plateNorm['plate'], 0, 160),
            'is_closed_admin' => $isClosed && $user['role'] === 'admin',
        ];
    }
}
