<?php
declare(strict_types=1);

namespace Core;

/**
 * Gestioneaza setarile stationare persistate in tabela `settings` din MariaDB.
 */
final class SettingsService
{
    public const KEY_STATION = 'station_config';

    public function __construct(
        private readonly Database $db,
        private readonly Config $config
    ) {
    }

    public function ensureDefaults(\PDO $pdo): void
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE setting_key = ?');
        $stmt->execute([self::KEY_STATION]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
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
    }

    public function getStationConfig(): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT value_json FROM settings WHERE setting_key = ?');
        $stmt->execute([self::KEY_STATION]);
        $raw = $stmt->fetchColumn();
        if (!is_string($raw)) {
            $this->ensureDefaults($pdo);
            $stmt->execute([self::KEY_STATION]);
            $raw = $stmt->fetchColumn();
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    public function updateStationConfig(\PDO $pdo, array $input, string $actorName): array
    {
        $current = $this->getStationConfig();
        $next = [
            'station_name' => trim((string) ($input['station_name'] ?? $current['station_name'] ?? 'Stație ITP')),
            'station_signature' => trim((string) ($input['station_signature'] ?? $current['station_signature'] ?? '')),
            'lines' => array_values(array_filter(array_map(
                static fn($v): string => trim((string) $v),
                (array) ($input['lines'] ?? $current['lines'] ?? ['Linia 1', 'Linia 2', 'Linia 3'])
            ), static fn(string $v): bool => $v !== '')),
            'slot_minutes' => max(15, min(60, (int) ($input['slot_minutes'] ?? $current['slot_minutes'] ?? 30))),
            'open_time' => (string) ($input['open_time'] ?? $current['open_time'] ?? '08:00'),
            'close_time' => (string) ($input['close_time'] ?? $current['close_time'] ?? '17:30'),
            'work_days' => array_values(array_map('intval', (array) ($input['work_days'] ?? $current['work_days'] ?? [1, 2, 3, 4, 5, 6]))),
            'reinspection_days' => max(1, min(90, (int) ($input['reinspection_days'] ?? $current['reinspection_days'] ?? 30))),
        ];

        if (empty($next['lines'])) {
            throw new HttpException(422, 'INVALID_LINES', 'Trebuie să existe cel puțin o linie ITP configurată.');
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
            substr($actorName, 0, 100),
        ]);

        return $next;
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
