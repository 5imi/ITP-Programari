<?php
declare(strict_types=1);

namespace Core;

/**
 * Wrapper PDO MariaDB (InnoDB, utf8mb4, prepared statements reale si retry pe deadlock).
 */
/* Conexiune MariaDB PDO (Database): Gestiune conexiune UTF8MB4, tranzactii atomice si detectie erori DuplicateKey/Deadlock. */
final class Database
{
    private \PDO $pdo;

    public function __construct(Config $config)
    {
        $host = (string) $config->get('DB_HOST', '127.0.0.1');
        $port = (string) $config->get('DB_PORT', '3306');
        $name = (string) $config->get('DB_NAME', 'acw_itp');
        $user = (string) $config->get('DB_USER', 'root');
        $pass = (string) $config->get('DB_PASS', '');

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
        $this->pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Aliniere fus orar sesiune MariaDB cu APP_TZ (Europe/Bucharest)
        $offset = $config->formatOffset();
        $this->pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = " . $this->pdo->quote($offset));
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * Executa o tranzactie ACID InnoDB cu ROLLBACK automat si retry scurt pe deadlock (1213/40001).
     *
     * @template T
     * @param callable(\PDO): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $maxAttempts = 3;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $startedHere = !$this->pdo->inTransaction();
            if ($startedHere) {
                $this->pdo->beginTransaction();
            }
            try {
                $result = $fn($this->pdo);
                if ($startedHere && $this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
                return $result;
            } catch (\Throwable $e) {
                if ($startedHere && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if ($startedHere && $attempt < $maxAttempts && self::isDeadlockException($e)) {
                    usleep(25000 * $attempt);
                    continue;
                }
                throw $e;
            }
        }
        throw new \RuntimeException('Tranzacția MariaDB a eșuat.');
    }

    public static function isDuplicateKeyException(\Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }
        $driverCode = $e->errorInfo[1] ?? null;
        return $driverCode === 1062 || $e->getCode() === '23000';
    }

    public static function isDeadlockException(\Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }
        $driverCode = $e->errorInfo[1] ?? null;
        return $driverCode === 1213 || $e->getCode() === '40001';
    }
}
