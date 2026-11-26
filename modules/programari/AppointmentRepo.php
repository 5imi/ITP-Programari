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
        bool $includeFuture,
        int $openWindowDays = 60
    ): array {
        $baseWhere = ['a.deleted_at IS NULL'];
        $args = [];

        if ($q !== '') {
            $like = '%' . $q . '%';
            $plateLike = '%' . strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $q)) . '%';
            $phoneClause = $this->buildPhoneSearchClause('a.phone', $q);
            $phoneSql = $phoneClause !== null ? '(a.phone LIKE ? OR ' . $phoneClause['sql'] . ')' : 'a.phone LIKE ?';
            $baseWhere[] = "(a.plate LIKE ? OR a.plate_key LIKE ? OR a.vehicle LIKE ? OR a.client LIKE ? OR $phoneSql OR a.line LIKE ? OR DATE_FORMAT(a.appt_date, '%d.%m.%Y') LIKE ?)";
            array_push($args, $like, $plateLike, $like, $like, $like);
            if ($phoneClause !== null) {
                array_push($args, ...$phoneClause['params']);
            }
            array_push($args, $like, $like);
        }
        if ($line !== '' && $line !== 'Toate') {
            $baseWhere[] = 'a.line = ?';
            $args[] = $line;
        }

        $selectSql = 'SELECT a.*, child.appt_date AS reinspection_date, child.appt_time AS reinspection_time, child.line AS reinspection_line
                      FROM appointments a
                      LEFT JOIN appointments child ON child.id = a.resolved_by_reinspection_id AND child.deleted_at IS NULL';

        // R08: Lista dedicata "Reveniri deschise" (nereprogramate, ultimele N zile) fara text obligatoriu
        if ($status === 'Reveniri deschise') {
            $sinceDate = Validators::addDaysIso($todayDate, -max(30, $openWindowDays));
            $openWhere = array_merge($baseWhere, [
                "a.status = 'Revenire'",
                'a.resolved_by_reinspection_id IS NULL',
                'a.appt_date >= ?',
            ]);
            $sql = $selectSql . ' WHERE ' . implode(' AND ', $openWhere)
                . ' ORDER BY COALESCE(a.reinspection_deadline, a.appt_date) ASC, a.appt_date DESC, a.appt_time ASC LIMIT 200';
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($args, [$sinceDate]));
            $rows = array_map([self::class, 'hydrate'], $stmt->fetchAll());
            return ['appointments' => $rows, 'future_appointments' => []];
        }

        if ($status !== '' && $status !== 'Toate') {
            $baseWhere[] = 'a.status = ?';
            $args[] = $status;
        }

        if ($searchAll && $q !== '') {
            $sql = $selectSql . ' WHERE ' . implode(' AND ', $baseWhere) . ' ORDER BY a.appt_date DESC, a.appt_time ASC LIMIT 200';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($args);
            $rows = array_map([self::class, 'hydrate'], $stmt->fetchAll());
            return ['appointments' => $rows, 'future_appointments' => []];
        }

        $dayWhere = array_merge($baseWhere, ['a.appt_date = ?']);
        $dayStmt = $pdo->prepare(
            $selectSql . ' WHERE ' . implode(' AND ', $dayWhere) . ' ORDER BY a.appt_time ASC, a.line ASC'
        );
        $dayStmt->execute(array_merge($args, [$selectedDate]));
        $dayRows = array_map([self::class, 'hydrate'], $dayStmt->fetchAll());

        $futureRows = [];
        if ($includeFuture && $selectedDate === $todayDate) {
            $futWhere = array_merge($baseWhere, ['a.appt_date > ?']);
            $futStmt = $pdo->prepare(
                $selectSql . ' WHERE ' . implode(' AND ', $futWhere) . ' ORDER BY a.appt_date ASC, a.appt_time ASC, a.line ASC LIMIT 100'
            );
            $futStmt->execute(array_merge($args, [$todayDate]));
            $futureRows = array_map([self::class, 'hydrate'], $futStmt->fetchAll());
        }

        return ['appointments' => $dayRows, 'future_appointments' => $futureRows];
    }

    /**
     * Construieste filtrul SQL pentru cautare dupa telefon indiferent de spatii, cratime sau prefix +40/0040.
     *
     * @return array{sql: string, params: array<int, string>}|null
     */
    private function buildPhoneSearchClause(string $colExpr, string $q): ?array
    {
        $compact = preg_replace('/[\s.\-()]+/', '', trim($q)) ?? '';
        if ($compact === '' || preg_match('/^[+0-9]+$/', $compact) !== 1) {
            return null;
        }
        $digitsOnly = preg_replace('/\D/', '', $compact) ?? '';
        if (strlen($digitsOnly) < 3) {
            return null;
        }

        $normCol = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($colExpr, ' ', ''), '-', ''), '.', ''), '(', ''), ')', '')";
        $variants = [$compact];
        if (str_starts_with($compact, '+40') && strlen($compact) >= 5) {
            $variants[] = '0' . substr($compact, 3);
        } elseif (str_starts_with($compact, '0040') && strlen($compact) >= 6) {
            $variants[] = '0' . substr($compact, 4);
        } elseif (preg_match('/^0[237]\d+$/', $compact) === 1) {
            $variants[] = '+4' . $compact;
            $variants[] = '4' . $compact;
        }

        $parts = [];
        $params = [];
        foreach (array_values(array_unique($variants)) as $variant) {
            $parts[] = $normCol . ' LIKE ?';
            $params[] = '%' . $variant . '%';
        }
        return [
            'sql' => '(' . implode(' OR ', $parts) . ')',
            'params' => $params,
        ];
    }

    public function countOpenReinspections(\PDO $pdo, string $todayDate, int $windowDays = 60): int
    {
        $sinceDate = Validators::addDaysIso($todayDate, -max(30, $windowDays));
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM appointments
             WHERE deleted_at IS NULL AND status = 'Revenire' AND resolved_by_reinspection_id IS NULL AND appt_date >= ?"
        );
        $stmt->execute([$sinceDate]);
        return (int) $stmt->fetchColumn();
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
        string $sinceDateTime,
        int $maxEntries = 500
    ): array {
        $maxEntries = max(50, min(1000, $maxEntries));
        $sql = 'SELECT h.*, a.appt_date, a.appt_time, a.line, a.plate, a.plate_key, a.vehicle, a.client, a.phone, a.status,
                       a.deleted_at, a.created_at AS appt_created_at, u.display_name AS creator_name, u.role AS creator_role
                FROM appointment_history h
                JOIN appointments a ON a.id = h.appointment_id
                LEFT JOIN users u ON u.id = a.created_by
                WHERE 1=1';
        $args = [];

        if ($eventType === 'Șterse') {
            $sql .= ' AND a.deleted_at IS NOT NULL';
        } elseif ($eventType === 'Intacte' || $intactFilter === 'intacte') {
            $sql .= ' AND a.deleted_at IS NULL';
        }

        if ($q !== '') {
            $like = '%' . $q . '%';
            $plateLike = '%' . strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $q)) . '%';
            $phoneClause = $this->buildPhoneSearchClause('a.phone', $q);
            $phoneSql = $phoneClause !== null ? '(a.phone LIKE ? OR ' . $phoneClause['sql'] . ')' : 'a.phone LIKE ?';
            $sql .= " AND (a.plate LIKE ? OR a.plate_key LIKE ? OR a.vehicle LIKE ? OR a.client LIKE ? OR $phoneSql OR h.summary LIKE ? OR h.actor_name LIKE ?)";
            array_push($args, $like, $plateLike, $like, $like, $like);
            if ($phoneClause !== null) {
                array_push($args, ...$phoneClause['params']);
            }
            array_push($args, $like, $like);
        } else {
            // Fara cautare explicita, afiseaza fereastra implicita (ex. 60 zile) pentru incarcare rapida
            $sql .= ' AND h.action_at >= ?';
            $args[] = $sinceDateTime;
        }

        $sqlLimit = $maxEntries * 5;
        $sql .= ' ORDER BY h.appointment_id DESC, h.id ASC LIMIT ' . $sqlLimit;
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

        $allActorsSet = [];
        $out = [];
        foreach ($grouped as $entry) {
            $entry['actors'] = array_keys($entry['actors']);
            foreach ($entry['actors'] as $actName) {
                $allActorsSet[$actName] = true;
            }
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
                if ($eventType === 'Finalizate' && !isset($entry['event_types']['FINALIZAT'])) {
                    continue;
                }
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
        $truncated = count($out) > $maxEntries || count($rows) >= ($maxEntries * 5);
        if (count($out) > $maxEntries) {
            $out = array_slice($out, 0, $maxEntries);
        }
        $allActors = array_keys($allActorsSet);
        sort($allActors, SORT_NATURAL | SORT_FLAG_CASE);
        return [
            'entries' => $out,
            'all_actors' => $allActors,
            'truncated' => $truncated,
            'limit' => $maxEntries,
        ];
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
            'reinspection_date' => $row['reinspection_date'] ?? null,
            'reinspection_time' => $row['reinspection_time'] ?? null,
            'reinspection_line' => $row['reinspection_line'] ?? null,
            'verify_notes' => $row['verify_notes'] ?? '',
            'version' => (int) $row['version'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'deleted_at' => $row['deleted_at'],
            'delete_reason' => $row['delete_reason'],
        ];
    }
}
