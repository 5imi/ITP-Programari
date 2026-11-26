<?php
declare(strict_types=1);

namespace Core;

/**
 * Backup la cerere (snapshot JSON consistent al tabelelor MariaDB) in directorul `backups/`.
 * Pe Romarg cPanel ruleaza suplimentar backup-ul zilnic Acronis.
 */
/* Serviciu Backup (BackupService): Generare snapshot SQL/JSON, listare si descarcare arhive protejate din storage/backups. */
final class BackupService
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config
    ) {
    }

    public function backupsDir(): string
    {
        return $this->config->baseDir() . DIRECTORY_SEPARATOR . 'backups';
    }

    public function createSnapshot(string $actorName): array
    {
        $dir = $this->backupsDir();
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new HttpException(500, 'BACKUP_DIR_ERROR', 'Nu s-a putut crea directorul backups/.');
        }

        $pdo = $this->db->pdo();
        $tablesStmt = $pdo->query('SHOW TABLES');
        $tables = array_map('strval', $tablesStmt->fetchAll(\PDO::FETCH_COLUMN));

        // Citire snapshot consistent InnoDB (REPEATABLE READ) fara hash-uri de PIN sau sesiuni
        $snapshotTables = [];
        $inTx = $pdo->inTransaction();
        if (!$inTx) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        }
        try {
            foreach ($tables as $table) {
                if ($table === 'sessions') {
                    continue;
                }
                $rows = $pdo->query('SELECT * FROM `' . str_replace('`', '', $table) . '`')->fetchAll();
                if ($table === 'users') {
                    foreach ($rows as &$r) {
                        unset($r['pin_hash'], $r['master_pin_hash']);
                    }
                    unset($r);
                }
                $snapshotTables[$table] = $rows;
            }
            if (!$inTx) {
                $pdo->exec('COMMIT');
            }
        } catch (\Throwable $e) {
            if (!$inTx) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }

        $now = $this->config->now();
        $filename = 'itp_backup_' . $now->format('Ymd_His') . '.json';
        $payload = [
            'meta' => [
                'app' => (string) $this->config->get('APP_NAME', 'ACW - Fălticeni • Stație ITP'),
                'created_at' => $now->format(\DateTimeInterface::ATOM),
                'created_by' => $actorName,
                'tables_count' => count($snapshotTables),
                'pin_hashes_excluded' => true,
            ],
            'tables' => $snapshotTables,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new HttpException(500, 'BACKUP_ENCODE_ERROR', 'Eroare la serializarea snapshot-ului.');
        }

        $target = $dir . DIRECTORY_SEPARATOR . $filename;
        if (file_put_contents($target, $json, LOCK_EX) === false) {
            throw new HttpException(500, 'BACKUP_WRITE_ERROR', 'Nu s-a putut scrie fișierul de backup pe disc.');
        }

        // Retentie automata: pastreaza ultimele 30 de copii de siguranta
        $allFiles = glob($dir . DIRECTORY_SEPARATOR . 'itp_backup_*.json') ?: [];
        rsort($allFiles, SORT_STRING);
        foreach (array_slice($allFiles, 30) as $oldPath) {
            @unlink($oldPath);
        }

        return [
            'filename' => $filename,
            'size_bytes' => filesize($target) ?: strlen($json),
            'created_at' => $now->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<int, array{filename: string, size_bytes: int, modified_at: string}>
     */
    public function listBackups(): array
    {
        $dir = $this->backupsDir();
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . DIRECTORY_SEPARATOR . 'itp_backup_*.json') ?: [];
        rsort($files, SORT_STRING);

        $tz = $this->config->timezone();
        $out = [];
        foreach ($files as $path) {
            $mtime = filemtime($path) ?: time();
            $dt = (new \DateTimeImmutable('@' . $mtime))->setTimezone($tz);
            $out[] = [
                'filename' => basename($path),
                'size_bytes' => (int) (filesize($path) ?: 0),
                'modified_at' => $dt->format('Y-m-d H:i:s'),
            ];
        }
        return $out;
    }

    public function resolveBackupFile(string $filename): string
    {
        if (preg_match('/^itp_backup_\d{8}_\d{6}\.json$/', $filename) !== 1) {
            throw new HttpException(400, 'INVALID_BACKUP_NAME', 'Nume fișier de backup invalid.');
        }
        $path = $this->backupsDir() . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            throw new HttpException(404, 'BACKUP_NOT_FOUND', 'Fișierul de backup nu există.');
        }
        return $path;
    }
}
