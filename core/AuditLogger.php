<?php
declare(strict_types=1);

namespace Core;

/**
 * Logger append-only pentru core_audit_log (Jurnal Securitate Admin).
 * Nu expune niciodata metode de UPDATE sau DELETE.
 */
final class AuditLogger
{
    public function __construct(private readonly Config $config)
    {
    }

    public function log(
        \PDO $pdo,
        ?int $userId,
        string $actorName,
        string $action,
        string $summary,
        string $moduleId = 'core',
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?Request $req = null
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO core_audit_log
                (created_at, user_id, actor_name, module_id, action, entity_type, entity_id, before_json, after_json, summary, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->config->nowString(),
            $userId,
            mb_substr($actorName, 0, 120, 'UTF-8'),
            mb_substr($moduleId, 0, 64, 'UTF-8'),
            mb_substr($action, 0, 48, 'UTF-8'),
            $entityType !== null ? mb_substr($entityType, 0, 64, 'UTF-8') : null,
            $entityId !== null ? mb_substr($entityId, 0, 64, 'UTF-8') : null,
            $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            mb_substr($summary, 0, 500, 'UTF-8'),
            $req !== null ? $req->clientIp() : '',
            $req !== null ? $req->userAgent() : '',
        ]);
    }
}
