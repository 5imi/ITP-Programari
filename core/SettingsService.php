<?php
declare(strict_types=1);

namespace Core;

/**
 * Gestioneaza setarile stationare persistate in tabela `settings` din MariaDB.
 */
final class SettingsService
{
    public const KEY_STATION = 'station_config';

    private ?array $cachedStationConfig = null;

    public function __construct(
        private readonly Database $db,
        private readonly Config $config
    ) {
    }

    public function ensureDefaults(\PDO $pdo): void
    {
        if ($this->cachedStationConfig !== null) {
            return;
        }
        $stmt = $pdo->prepare('SELECT value_json FROM settings WHERE setting_key = ?');
        $stmt->execute([self::KEY_STATION]);
        $raw = $stmt->fetchColumn();
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $this->cachedStationConfig = $decoded;
                return;
            }
        }
        $defaults = [
            'station_name' => (string) $this->config->get('APP_NAME', 'ACW - Fălticeni • Stație ITP'),
            'station_signature' => 'AUTOMOTIVE CENTER WERKSTAT SRL Falticeni Str. Magazia Garii 3 F',
            'lines' => ['Linia 1', 'Linia 2', 'Linia 3'],
            'slot_minutes' => 30,
            'open_time' => '08:00',
            'close_time' => '17:30',
            'work_days' => [1, 2, 3, 4, 5, 6],
            'reinspection_days' => 30,
        ];
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO settings (setting_key, value_json, updated_at, updated_by) VALUES (?, ?, ?, ?)'
        );
        $ins->execute([
            self::KEY_STATION,
            json_encode($defaults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $this->config->nowString(),
            'system',
        ]);
        $this->cachedStationConfig = $defaults;
    }

    public function getStationConfig(): array
    {
        if ($this->cachedStationConfig !== null) {
            return $this->cachedStationConfig;
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT value_json FROM settings WHERE setting_key = ?');
        $stmt->execute([self::KEY_STATION]);
        $raw = $stmt->fetchColumn();
        if (!is_string($raw)) {
            $this->ensureDefaults($pdo);
            return $this->cachedStationConfig ?? [];
        }
        $decoded = json_decode($raw, true);
        $this->cachedStationConfig = is_array($decoded) ? $decoded : [];
        return $this->cachedStationConfig;
    }

    public function updateStationConfig(\PDO $pdo, array $input, string $actorName): array
    {
        $current = $this->getStationConfig();
        $openRaw = trim((string) ($input['open_time'] ?? $current['open_time'] ?? '08:00'));
        $closeRaw = trim((string) ($input['close_time'] ?? $current['close_time'] ?? '17:30'));

        // Accepta si format "8:00" normalizand la "08:00"
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $openRaw, $mo) !== 1 || (int) $mo[1] > 23 || (int) $mo[2] > 59) {
            throw new HttpException(422, 'INVALID_OPEN_TIME', 'Ora de deschidere trebuie să fie în format HH:MM (ex. 08:00).');
        }
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $closeRaw, $mc) !== 1 || (int) $mc[1] > 23 || (int) $mc[2] > 59) {
            throw new HttpException(422, 'INVALID_CLOSE_TIME', 'Ora de închidere trebuie să fie în format HH:MM (ex. 17:30).');
        }
        $openMin = ((int) $mo[1]) * 60 + (int) $mo[2];
        $closeMin = ((int) $mc[1]) * 60 + (int) $mc[2];
        if ($openMin >= $closeMin) {
            throw new HttpException(422, 'INVALID_HOURS', 'Ora de deschidere trebuie să fie strict anterioară orei de închidere.');
        }

        $openNorm = sprintf('%02d:%02d', (int) $mo[1], (int) $mo[2]);
        $closeNorm = sprintf('%02d:%02d', (int) $mc[1], (int) $mc[2]);

        $workDays = array_values(array_unique(array_filter(
            array_map('intval', (array) ($input['work_days'] ?? $current['work_days'] ?? [1, 2, 3, 4, 5, 6])),
            static fn(int $d): bool => $d >= 1 && $d <= 7
        )));
        sort($workDays);
        if (empty($workDays)) {
            throw new HttpException(422, 'INVALID_WORK_DAYS', 'Selectează cel puțin o zi lucrătoare (1–7).');
        }

        $lines = array_values(array_unique(array_filter(array_map(
            static fn($v): string => trim((string) $v),
            (array) ($input['lines'] ?? $current['lines'] ?? ['Linia 1', 'Linia 2', 'Linia 3'])
        ), static fn(string $v): bool => $v !== '')));
        if (empty($lines)) {
            throw new HttpException(422, 'INVALID_LINES', 'Trebuie să existe cel puțin o linie ITP configurată.');
        }

        $next = [
            'station_name' => mb_substr(trim((string) ($input['station_name'] ?? $current['station_name'] ?? 'Stație ITP')), 0, 120, 'UTF-8'),
            'station_signature' => mb_substr(trim((string) ($input['station_signature'] ?? $current['station_signature'] ?? '')), 0, 220, 'UTF-8'),
            'lines' => $lines,
            'slot_minutes' => max(15, min(60, (int) ($input['slot_minutes'] ?? $current['slot_minutes'] ?? 30))),
            'open_time' => $openNorm,
            'close_time' => $closeNorm,
            'work_days' => $workDays,
            'reinspection_days' => max(1, min(90, (int) ($input['reinspection_days'] ?? $current['reinspection_days'] ?? 30))),
        ];

        if (empty($this->buildDailySlots($next))) {
            throw new HttpException(422, 'INVALID_SLOTS', 'Intervalul orar și durata slotului nu produc niciun interval valid.');
        }

        // D01: Verifica programari active de azi inainte care devin invalide cu noile linii/sloturi/zile
        $impact = $this->findFutureAppointmentConflicts($pdo, $next, $this->config->todayIso());
        if ($impact['total_affected'] > 0 && empty($input['confirm_impact'])) {
            $parts = [];
            if ($impact['line_conflicts'] > 0) {
                $parts[] = sprintf('pe linii eliminate: %d', $impact['line_conflicts']);
            }
            if ($impact['slot_conflicts'] > 0) {
                $parts[] = sprintf('la ore incompatibile: %d', $impact['slot_conflicts']);
            }
            if ($impact['day_conflicts'] > 0) {
                $parts[] = sprintf('în zile setate închise: %d', $impact['day_conflicts']);
            }
            $samplesSuffix = $impact['samples']
                ? ' [ex: ' . implode('; ', $impact['samples']) . ']'
                : '';
            throw new HttpException(
                409,
                'SETTINGS_IMPACT_CONFIRM_REQUIRED',
                sprintf(
                    'Există %d programări active de azi înainte afectate de noile setări (%s)%s. Confirmă dacă salvezi oricum sau mută mai întâi programările.',
                    $impact['total_affected'],
                    implode(', ', $parts),
                    $samplesSuffix
                ),
                ['impact' => $impact]
            );
        }

        $upd = $pdo->prepare(
            'INSERT INTO settings (setting_key, value_json, updated_at, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)'
        );
        $upd->execute([
            self::KEY_STATION,
            json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $this->config->nowString(),
            mb_substr($actorName, 0, 100, 'UTF-8'),
        ]);
        $this->cachedStationConfig = $next;

        return $next;
    }

    /**
     * @return array{total_affected: int, line_conflicts: int, slot_conflicts: int, day_conflicts: int, samples: array<int, string>}
     */
    public function findFutureAppointmentConflicts(\PDO $pdo, array $next, string $todayIso): array
    {
        $hasTable = (bool) $pdo->query("SHOW TABLES LIKE 'appointments'")->fetchColumn();
        if (!$hasTable) {
            return [
                'total_affected' => 0,
                'line_conflicts' => 0,
                'slot_conflicts' => 0,
                'day_conflicts' => 0,
                'samples' => [],
            ];
        }

        $nextLines = array_map('strval', (array) ($next['lines'] ?? []));
        $nextSlots = $this->buildDailySlots($next);
        $nextWorkDays = array_map('intval', (array) ($next['work_days'] ?? [1, 2, 3, 4, 5, 6]));

        $stmt = $pdo->prepare(
            'SELECT appt_date, appt_time, line, plate
             FROM appointments
             WHERE appt_date >= ? AND deleted_at IS NULL
             ORDER BY appt_date ASC, appt_time ASC'
        );
        $stmt->execute([$todayIso]);

        $totalAffected = 0;
        $lineConflicts = 0;
        $slotConflicts = 0;
        $dayConflicts = 0;
        $samples = [];

        foreach ($stmt->fetchAll() as $r) {
            $lineBad = !in_array((string) $r['line'], $nextLines, true);
            $slotBad = !in_array((string) $r['appt_time'], $nextSlots, true);
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $r['appt_date']);
            $dayBad = $dt ? !in_array((int) $dt->format('N'), $nextWorkDays, true) : false;

            if ($lineBad) {
                $lineConflicts++;
            }
            if ($slotBad) {
                $slotConflicts++;
            }
            if ($dayBad) {
                $dayConflicts++;
            }
            if ($lineBad || $slotBad || $dayBad) {
                $totalAffected++;
                if (count($samples) < 5) {
                    $roDate = $dt ? $dt->format('d.m') : (string) $r['appt_date'];
                    $samples[] = sprintf('%s (%s %s • %s)', (string) $r['plate'], $roDate, (string) $r['appt_time'], (string) $r['line']);
                }
            }
        }

        return [
            'total_affected' => $totalAffected,
            'line_conflicts' => $lineConflicts,
            'slot_conflicts' => $slotConflicts,
            'day_conflicts' => $dayConflicts,
            'samples' => $samples,
        ];
    }

    /**
     * Genereaza lista sloturilor orare HH:MM din configuratia stationara salvata in DB.
     *
     * @return array<int, string>
     */
    public function buildDailySlots(?array $stationConfig = null): array
    {
        $cfg = $stationConfig ?? $this->getStationConfig();
        $open = (string) ($cfg['open_time'] ?? '08:00');
        $close = (string) ($cfg['close_time'] ?? '17:30');
        $step = max(15, (int) ($cfg['slot_minutes'] ?? 30));

        if (preg_match('/^(\d{2}):(\d{2})$/', $open, $m1) !== 1 || preg_match('/^(\d{2}):(\d{2})$/', $close, $m2) !== 1) {
            return [];
        }
        $cur = ((int) $m1[1]) * 60 + (int) $m1[2];
        $end = ((int) $m2[1]) * 60 + (int) $m2[2];

        $slots = [];
        while ($cur <= $end) {
            $slots[] = sprintf('%02d:%02d', intdiv($cur, 60), $cur % 60);
            $cur += $step;
        }
        return $slots;
    }
}
