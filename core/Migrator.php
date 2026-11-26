<?php
declare(strict_types=1);

namespace Core;

/**
 * Runner incremental de migrari SQL (Core + Modules) cu verificare checksum SHA-256.
 * Trateaza fisierele `*_triggers.sql` cu fallback sigur pe shared hosting fara SUPER privilege.
 */
final class Migrator
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config
    ) {
    }

    /**
     * Ruleaza migrarile neaplicate pentru core si modulele detectate.
     *
     * @return array<int, string> Eventuale avertismente (ex. trigger refuzat de shared hosting)
     */
    public function runAll(array $moduleIds = []): array
    {
        $pdo = $this->db->pdo();
        $applied = $this->loadAppliedMap($pdo);

        $warnings = [];
        $base = $this->config->baseDir();

        $warnings = array_merge(
            $warnings,
            $this->runDir('core', $base . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'migrations', $applied)
        );

        foreach ($moduleIds as $moduleId) {
            $dir = $base . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . $moduleId . DIRECTORY_SEPARATOR . 'migrations';
            $warnings = array_merge($warnings, $this->runDir($moduleId, $dir, $applied));
        }

        return $warnings;
    }

    /**
     * Incarca toate migrarile aplicate intr-un singur SELECT (evita DDL `CREATE TABLE` la fiecare request).
     *
     * @return array<string, string>
     */
    private function loadAppliedMap(\PDO $pdo): array
    {
        try {
            $rows = $pdo->query('SELECT module_id, filename, checksum FROM _migrations')->fetchAll();
            $map = [];
            foreach ($rows as $r) {
                $map[(string) $r['module_id'] . '/' . (string) $r['filename']] = (string) $r['checksum'];
            }
            return $map;
        } catch (\PDOException $e) {
            $driverCode = $e->errorInfo[1] ?? null;
            if ($driverCode !== 1146 && $e->getCode() !== '42S02') {
                throw $e;
            }
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS _migrations (
                    module_id VARCHAR(64) NOT NULL,
                    filename VARCHAR(128) NOT NULL,
                    checksum CHAR(64) NOT NULL,
                    applied_at DATETIME NOT NULL,
                    PRIMARY KEY (module_id, filename)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            return [];
        }
    }

    /**
     * @param array<string, string> $applied
     * @return array<int, string>
     */
    private function runDir(string $moduleId, string $dir, array &$applied): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files, SORT_STRING);

        $pdo = $this->db->pdo();
        $warnings = [];

        foreach ($files as $filePath) {
            $filename = basename($filePath);
            $sql = (string) file_get_contents($filePath);
            $checksum = hash('sha256', str_replace("\r\n", "\n", $sql));
            $key = $moduleId . '/' . $filename;

            if (isset($applied[$key])) {
                if ($applied[$key] !== $checksum) {
                    throw new \RuntimeException(
                        sprintf('Checksum modificat pe migrarea deja aplicată [%s/%s]. Creează un fișier nou de migrare.', $moduleId, $filename)
                    );
                }
                continue;
            }

            $isTriggerFile = str_ends_with($filename, '_triggers.sql');
            $statements = self::splitStatements($sql);

            try {
                foreach ($statements as $statement) {
                    $pdo->exec($statement);
                }
                $ins = $pdo->prepare('INSERT IGNORE INTO _migrations (module_id, filename, checksum, applied_at) VALUES (?, ?, ?, ?)');
                $ins->execute([$moduleId, $filename, $checksum, $this->config->nowString()]);
                $applied[$key] = $checksum;
            } catch (\PDOException $e) {
                $driverCode = $e->errorInfo[1] ?? null;
                if ($isTriggerFile && in_array($driverCode, [1227, 1419], true)) {
                    // Marcheaza migrarea ca procesata pentru a nu re-incerca CREATE TRIGGER la fiecare cerere HTTP
                    $ins = $pdo->prepare('INSERT IGNORE INTO _migrations (module_id, filename, checksum, applied_at) VALUES (?, ?, ?, ?)');
                    $ins->execute([$moduleId, $filename, $checksum, $this->config->nowString()]);
                    $applied[$key] = $checksum;
                    $warnings[] = sprintf(
                        'Migrare trigger %s/%s omisă de privilegiile serverului (%s). Imutabilitatea este asigurată din aplicație.',
                        $moduleId,
                        $filename,
                        $e->getMessage()
                    );
                    continue;
                }
                throw $e;
            }
        }

        return $warnings;
    }

    /**
     * @return array<int, string>
     */
    private static function splitStatements(string $sql): array
    {
        $sql = str_replace("\r\n", "\n", $sql);
        $lines = explode("\n", $sql);
        $cleanLines = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '--')) {
                continue;
            }
            $cleanLines[] = $line;
        }
        $cleanSql = implode("\n", $cleanLines);
        $parts = preg_split('/;\s*(?:\n|$)/', $cleanSql) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }
}
