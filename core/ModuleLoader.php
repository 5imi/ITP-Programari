<?php
declare(strict_types=1);

namespace Core;

/**
 * Descopera modulele din `modules/<nume>/module.json`, sincronizeaza `modules_state` si inregistreaza rutele active.
 */
final class ModuleLoader
{
    /** @var array<string, array> */
    private array $manifests = [];

    public function __construct(
        private readonly Database $db,
        private readonly Config $config
    ) {
    }

    /**
     * Scaneaza directoarele de module si sincronizeaza starea lor in MariaDB.
     *
     * @return array<int, string> Lista ID-urilor de module descoperite
     */
    public function discover(): array
    {
        $modulesDir = $this->config->baseDir() . DIRECTORY_SEPARATOR . 'modules';
        if (!is_dir($modulesDir)) {
            return [];
        }

        $ids = [];
        $entries = glob($modulesDir . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'module.json') ?: [];
        sort($entries, SORT_STRING);

        foreach ($entries as $manifestPath) {
            $raw = file_get_contents($manifestPath);
            $meta = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($meta) || empty($meta['id'])) {
                continue;
            }
            $id = (string) $meta['id'];
            $this->manifests[$id] = $meta;
            $ids[] = $id;
        }

        return $ids;
    }

    public function syncModulesState(): void
    {
        $pdo = $this->db->pdo();
        $now = $this->config->nowString();

        foreach ($this->manifests as $id => $meta) {
            $stmt = $pdo->prepare('SELECT enabled FROM modules_state WHERE module_id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            $defaultEnabled = !empty($meta['enabled_by_default']) ? 1 : 0;
            $version = (string) ($meta['version'] ?? '1.0.0');

            if (!$row) {
                $ins = $pdo->prepare(
                    'INSERT INTO modules_state (module_id, enabled, version, updated_at) VALUES (?, ?, ?, ?)'
                );
                $ins->execute([$id, $defaultEnabled, $version, $now]);
            } elseif (!empty($meta['locked'])) {
                $upd = $pdo->prepare('UPDATE modules_state SET enabled = 1, version = ? WHERE module_id = ?');
                $upd->execute([$version, $id]);
            }
        }
    }

    public function isEnabled(string $moduleId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT enabled FROM modules_state WHERE module_id = ?');
        $stmt->execute([$moduleId]);
        $val = $stmt->fetchColumn();
        if ($val !== false) {
            return (int) $val === 1;
        }
        return !empty($this->manifests[$moduleId]['enabled_by_default']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAllWithState(): array
    {
        $stmt = $this->db->pdo()->query('SELECT module_id, enabled, version, updated_at FROM modules_state');
        $stateMap = [];
        foreach ($stmt->fetchAll() as $r) {
            $stateMap[$r['module_id']] = $r;
        }

        $out = [];
        foreach ($this->manifests as $id => $meta) {
            $dbRow = $stateMap[$id] ?? null;
            $locked = !empty($meta['locked']);
            $enabled = $locked ? true : ($dbRow ? ((int) $dbRow['enabled'] === 1) : !empty($meta['enabled_by_default']));
            $out[] = [
                'id' => $id,
                'name' => (string) ($meta['name'] ?? $id),
                'description' => (string) ($meta['description'] ?? ''),
                'version' => (string) ($meta['version'] ?? '1.0.0'),
                'locked' => $locked,
                'enabled' => $enabled,
                'updated_at' => $dbRow['updated_at'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * @return array<string, array>
     */
    public function manifests(): array
    {
        return $this->manifests;
    }
}
