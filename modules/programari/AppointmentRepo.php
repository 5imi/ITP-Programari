<?php
declare(strict_types=1);

namespace Modules\Programari;

/**
 * Repository SQL MariaDB pentru tabelele `appointments`, `appointment_history` si `reasons`.
 * Interdictie stricta: nu contine nicio metoda de UPDATE sau DELETE pe `appointment_history`.
 */
final class AppointmentRepo
{
    public function findActiveById(\PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM appointments WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    public function lockActiveByIdForUpdate(\PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM appointments WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    public function findByIdIncludingDeletedForUpdate(\PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM appointments WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    /**
     * Returneaza programari active pentru ziua ceruta sau toate zilele (cand search_all=true),
     * plus programarile viitoare daca se cere `include_future=true`.
     *
     * @return array{appointments: array<int, array>, future_appointments: array<int, array>}
     */
    public function listAgenda(
        \PDO $pdo,
        string $selectedDate,
        string $todayDate,
        bool $searchAll,
        string $q,
        string $line,
        string $status,
        bool $includeFuture
    ): array {
        $baseWhere = ['deleted_at IS NULL'];
        $args = [];

        if ($q !== '') {
            $baseWhere[] = '(plate LIKE ? OR plate_key LIKE ? OR vehicle LIKE ? OR client LIKE ? OR phone LIKE ? OR line LIKE ? OR DATE_FORMAT(appt_date, \'%d.%m.%Y\') LIKE ?)';
            $like = '%' . $q . '%';
            $plateLike = '%' . strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $q)) . '%';
            array_push($args, $like, $plateLike, $like, $like, $like, $like, $like);
        }
        if ($line !== '' && $line !== 'Toate') {
            $baseWhere[] = 'line = ?';
            $args[] = $line;
        }
        if ($status !== '' && $status !== 'Toate') {
            $baseWhere[] = 'status = ?';
            $args[] = $status;
        }

        if ($searchAll && $q !== '') {
            $sql = 'SELECT * FROM appointments WHERE ' . implode(' AND ', $baseWhere) . ' ORDER BY appt_date DESC, appt_time ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($args);
            $rows = array_map([self::class, 'hydrate'], $stmt->fetchAll());
            return ['appointments' => $rows, 'future_appointments' => []];
        }

        $dayWhere = array_merge($baseWhere, ['appt_date = ?']);
        $dayStmt = $pdo->prepare(
            'SELECT * FROM appointments WHERE ' . implode(' AND ', $dayWhere) . ' ORDER BY appt_time ASC, line ASC'
        );
        $dayStmt->execute(array_merge($args, [$selectedDate]));
        $dayRows = array_map([self::class, 'hydrate'], $dayStmt->fetchAll());

        $futureRows = [];
        if ($includeFuture && $selectedDate === $todayDate) {
            $futWhere = array_merge($baseWhere, ['appt_date > ?']);
            $futStmt = $pdo->prepare(
                'SELECT * FROM appointments WHERE ' . implode(' AND ', $futWhere) . ' ORDER BY appt_date ASC, appt_time ASC, line ASC LIMIT 100'
            );
            $futStmt->execute(array_merge($args, [$todayDate]));
            $futureRows = array_map([self::class, 'hydrate'], $futStmt->fetchAll());
        }

        return ['appointments' => $dayRows, 'future_appointments' => $futureRows];
    }

    /**
     * Numara programarile active pe linii strict pentru ziua selectata (fara filtre de cautare/status).
     *
     * @return array<string, int>
     */
    public function countOccupancyByLine(\PDO $pdo, string $date): array
    {
        $stmt = $pdo->prepare(
            'SELECT line, COUNT(*) AS cnt FROM appointments WHERE appt_date = ? AND deleted_at IS NULL GROUP BY line'
        );
        $stmt->execute([$date]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(string) $r['line']] = (int) $r['cnt'];
        }
        return $out;
    }

    public function findSameDayPlateConflict(
        \PDO $pdo,
        string $plateKey,
        string $date,
        ?int $excludeId = null,
        ?int $excludeParentId = null
    ): ?array {
        $sql = 'SELECT id, appt_date, appt_time, line, plate FROM appointments WHERE plate_key = ? AND appt_date = ? AND deleted_at IS NULL';
        $args = [$plateKey, $date];
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $args[] = $excludeId;
        }
        if ($excludeParentId !== null) {
            $sql .= ' AND id <> ?';
            $args[] = $excludeParentId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function insert(\PDO $pdo, array $data): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO appointments (
                appt_date, appt_time, line, plate, plate_key, vehicle, phone, client,
                status, reject_reasons_json, reject_note, visit_type, parent_id,
                resolved_by_reinspection_id, reinspection_deadline, verify_notes,
                version, created_by, created_at, updated_by, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['appt_date'],
            $data['appt_time'],
            $data['line'],
            $data['plate'],
            $data['plate_key'],
            $data['vehicle'] ?? '',
            $data['phone'],
            $data['client'],
            $data['status'] ?? 'Programat',
            json_encode($data['reject_reasons'] ?? [], JSON_UNESCAPED_UNICODE),
            $data['reject_note'] ?? '',
            $data['visit_type'] ?? 'NORMAL',
            $data['parent_id'] ?? null,
            $data['resolved_by_reinspection_id'] ?? null,
            $data['reinspection_deadline'] ?? null,
            $data['verify_notes'] ?? '',
            $data['created_by'],
            $data['created_at'],
            $data['updated_by'],
            $data['updated_at'],
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Grupeaza evenimentele cronologice din `appointment_history` pe fiecare programare.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listHistoryEntries(
        \PDO $pdo,
        string $q,
        string $eventType,
        string $actor,
        string $intactFilter,
        string $sinceDateTime
    ): array {
        $sql = 'SELECT h.*, a.appt_date, a.appt_time, a.line, a.plate, a.plate_key, a.vehicle, a.client, a.phone, a.status,
                       a.deleted_at, a.created_at AS appt_created_at, u.display_name AS creator_name, u.role AS creator_role
                FROM appointment_history h
                JOIN appointments a ON a.id = h.appointment_id
                LEFT JOIN users u ON u.id = a.created_by
                WHERE 1=1';
        $args = [];

        if ($q !== '') {
            $like = '%' . $q . '%';
            $plateLike = '%' . strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $q)) . '%';
            $sql .= ' AND (a.plate LIKE ? OR a.plate_key LIKE ? OR a.vehicle LIKE ? OR a.client LIKE ? OR a.phone LIKE ? OR h.summary LIKE ? OR h.actor_name LIKE ?)';
            array_push($args, $like, $plateLike, $like, $like, $like, $like, $like);
        } else {
            // Fara cautare explicita, afiseaza fereastra implicita (ex. 60 zile) pentru incarcare rapida
            $sql .= ' AND h.action_at >= ?';
            $args[] = $sinceDateTime;
        }

        $sql .= ' ORDER BY h.appointment_id DESC, h.id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll();

        $grouped = [];
        foreach ($rows as $r) {
            $aid = (int) $r['appointment_id'];
            if (!isset($grouped[$aid])) {
                $creatorLabel = $r['creator_name']
                    ? sprintf('%s (%s)', $r['creator_name'], $r['creator_role'] === 'admin' ? 'Admin' : 'User')
                    : (string) $r['actor_name'];
                $grouped[$aid] = [
                    'appointment_id' => $aid,
                    'plate' => $r['plate'],
                    'vehicle' => $r['vehicle'] ?? '',
                    'client' => $r['client'],
                    'phone' => $r['phone'],
                    'appt_date' => $r['appt_date'],
                    'appt_time' => $r['appt_time'],
                    'line' => $r['line'],
                    'status' => $r['status'],
                    'deleted' => $r['deleted_at'] !== null,
                    'created_at' => $r['appt_created_at'],
                    'created_by' => $creatorLabel,
                    'events' => [],
                    'actors' => [],
                    'event_types' => [],
                ];
            }
            $at = (string) $r['action_at'];
            $grouped[$aid]['events'][] = [
                'id' => (int) $r['id'],
                'event_type' => $r['event_type'],
                'actor_name' => $r['actor_name'],
                'action_at' => $at,
                'date' => substr($at, 0, 10),
                'at' => substr($at, 11, 5),
                'summary' => $r['summary'],
                'reason' => $r['reason'],
                'before' => $r['before_json'] ? json_decode((string) $r['before_json'], true) : null,
                'after' => $r['after_json'] ? json_decode((string) $r['after_json'], true) : null,
            ];
            $grouped[$aid]['actors'][$r['actor_name']] = true;
            $grouped[$aid]['event_types'][$r['event_type']] = true;
        }

        $out = [];
        foreach ($grouped as $entry) {
            $entry['actors'] = array_keys($entry['actors']);
            $lastEv = end($entry['events']);
            $entry['last_action_at'] = $lastEv ? $lastEv['action_at'] : $entry['created_at'];
            $entry['last_event_id'] = $lastEv ? (int) $lastEv['id'] : 0;

            if ($actor !== '' && $actor !== 'TOȚI' && !in_array($actor, $entry['actors'], true)) {
                continue;
            }
            if ($intactFilter === 'intacte' && ($entry['deleted'] || count($entry['events']) > 1)) {
                continue;
            }
            if ($eventType !== '' && $eventType !== 'Toate') {
                if ($eventType === 'Șterse' && !$entry['deleted']) {
                    continue;
                }
                if ($eventType === 'Modificate' && !isset($entry['event_types']['MODIFICAT'])) {
                    continue;
                }
                if ($eventType === 'Reveniri' && !isset($entry['event_types']['REVENIRE'])) {
                    continue;
                }
                if ($eventType === 'Revocate' && !isset($entry['event_types']['REVOCAT'])) {
                    continue;
                }
                if ($eventType === 'Reprogramate' && !isset($entry['event_types']['REPROGRAMAT'])) {
                    continue;
                }
                if ($eventType === 'Intacte' && ($entry['deleted'] || count($entry['events']) > 1)) {
                    continue;
                }
            }

            unset($entry['event_types']);
            $out[] = $entry;
        }

        usort($out, static fn(array $a, array $b): int => strcmp((string) $b['last_action_at'], (string) $a['last_action_at']) ?: ((int) $b['last_event_id'] <=> (int) $a['last_event_id']));
        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listReasons(\PDO $pdo, ?string $kind = null): array
    {
        if ($kind !== null) {
            $stmt = $pdo->prepare('SELECT id, kind, label, sort_order FROM reasons WHERE kind = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC');
            $stmt->execute([$kind]);
        } else {
            $stmt = $pdo->query('SELECT id, kind, label, sort_order FROM reasons WHERE is_active = 1 ORDER BY kind ASC, sort_order ASC, id ASC');
        }
        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'kind' => $r['kind'],
            'label' => $r['label'],
            'sort_order' => (int) $r['sort_order'],
        ], $stmt->fetchAll());
    }

    /**
     * @return array<string, mixed>
     */
    public static function hydrate(array $row): array
    {
        $reasons = json_decode((string) ($row['reject_reasons_json'] ?? '[]'), true);
        return [
            'id' => (int) $row['id'],
            'date' => $row['appt_date'],
            'time' => $row['appt_time'],
            'line' => $row['line'],
            'plate' => $row['plate'],
            'plate_key' => $row['plate_key'],
            'vehicle' => $row['vehicle'] ?? '',
            'phone' => $row['phone'],
            'client' => $row['client'],
            'status' => $row['status'],
            'reject_reasons' => is_array($reasons) ? $reasons : [],
            'reject_note' => $row['reject_note'] ?? '',
            'visit_type' => $row['visit_type'],
            'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'resolved_by_reinspection_id' => $row['resolved_by_reinspection_id'] !== null ? (int) $row['resolved_by_reinspection_id'] : null,
            'reinspection_deadline' => $row['reinspection_deadline'],
            'verify_notes' => $row['verify_notes'] ?? '',
            'version' => (int) $row['version'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'deleted_at' => $row['deleted_at'],
            'delete_reason' => $row['delete_reason'],
        ];
    }
}
