<?php
declare(strict_types=1);

namespace Modules\Programari;

use Core\Config;

/**
 * Serviciu append-only pentru `appointment_history` (Istoric Imutabil).
 * Extensibil: coloana event_type este VARCHAR(32), pregatita si pentru evenimente viitoare.
 */
final class HistoryService
{
    public const EVENT_CATALOG = [
        'CREAT' => 'Programare creată',
        'MODIFICAT' => 'Modificată',
        'REVENIRE' => 'Marcat Revenire',
        'REVOCAT' => 'Revenire revocată',
        'REPROGRAMAT' => 'Reprogramat revenire',
        'ȘTERS' => 'Ștearsă / Anulată',
    ];

    public function __construct(private readonly Config $config)
    {
    }

    public function record(
        \PDO $pdo,
        int $appointmentId,
        string $eventType,
        ?int $actorUserId,
        string $actorName,
        string $summary,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        string $ipAddress = ''
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO appointment_history
                (appointment_id, event_type, actor_user_id, actor_name, action_at, summary, before_json, after_json, reason, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $appointmentId,
            substr($eventType, 0, 32),
            $actorUserId,
            substr($actorName, 0, 120),
            $this->config->nowString(),
            substr($summary, 0, 700),
            $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $reason !== null ? substr($reason, 0, 255) : null,
            substr($ipAddress, 0, 64),
        ]);
    }
}
