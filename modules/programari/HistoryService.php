<?php
declare(strict_types=1);

namespace Modules\Programari;

use Core\Config;

/**
 * Serviciu append-only pentru `appointment_history` (Istoric Imutabil).
 * Extensibil: coloana event_type este VARCHAR(32), pregatita si pentru evenimente viitoare.
 */
/* Serviciu Istoric Imutabil (HistoryService): Inregistreaza evenimente append-only in appointment_history (CREAT, MODIFICAT, FINALIZAT, REVENIRE, REPROGRAMAT, STERS). */
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
            mb_substr($eventType, 0, 32, 'UTF-8'),
            $actorUserId,
            mb_substr($actorName, 0, 120, 'UTF-8'),
            $this->config->nowString(),
            mb_substr($summary, 0, 700, 'UTF-8'),
            $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $reason !== null ? mb_substr($reason, 0, 255, 'UTF-8') : null,
            substr($ipAddress, 0, 64),
        ]);
    }
}
