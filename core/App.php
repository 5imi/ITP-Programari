<?php
declare(strict_types=1);

namespace Core;

use Core\Controllers\AdminController;
use Core\Controllers\AuthController;
use Modules\Programari\AppointmentRepo;
use Modules\Programari\AppointmentService;
use Modules\Programari\Controller as ProgramariController;
use Modules\Programari\HistoryService;

/**
 * Bootstrap central al aplicatiei: configurare, conexiune PDO, migrari incrementale si rutare.
 */
/* Nucleu aplicatie (App): Initializare Config, Database, Migrator, ModuleLoader, inregistrare rute Auth/Admin/Module si dispatch HTTP. */
final class App
{
    private Config $config;
    private Database $db;
    private Router $router;
    private array $migrationWarnings = [];

    public function __construct(string $baseDir, ?array $configOverride = null)
    {
        $this->config = new Config($baseDir, $configOverride);
        $this->db = new Database($this->config);

        $modules = new ModuleLoader($this->db, $this->config);
        $moduleIds = $modules->discover();

        $migrator = new Migrator($this->db, $this->config);
        $this->migrationWarnings = $migrator->runAll($moduleIds);

        $modules->syncModulesState();

        $settings = new SettingsService($this->db, $this->config);
        $settings->ensureDefaults($this->db->pdo());

        $auth = new Auth($this->db, $this->config);
        $audit = new AuditLogger($this->config);
        $backups = new BackupService($this->db, $this->config);

        $authCtrl = new AuthController($this->db, $this->config, $auth, $audit, $settings, $modules);
        $adminCtrl = new AdminController($this->db, $this->config, $auth, $audit, $settings, $modules, $backups);

        $this->router = new Router();

        // Auth & Setup
        $this->router->add('GET', '/api/auth/setup-status', [$authCtrl, 'setupStatus']);
        $this->router->add('POST', '/api/setup/first-start', [$authCtrl, 'firstStart']);
        $this->router->add('GET', '/api/auth/users', [$authCtrl, 'listLoginUsers']);
        $this->router->add('POST', '/api/auth/login', [$authCtrl, 'login']);
        $this->router->add('POST', '/api/auth/master-recovery', [$authCtrl, 'masterRecovery']);
        $this->router->add('POST', '/api/auth/change-pin', [$authCtrl, 'changePin']);
        $this->router->add('POST', '/api/auth/change-master-pin', [$authCtrl, 'changeMasterPin']);
        $this->router->add('POST', '/api/auth/logout', [$authCtrl, 'logout']);
        $this->router->add('GET', '/api/auth/me', [$authCtrl, 'me']);
        $this->router->add('GET', '/api/core/menu', [$authCtrl, 'menu']);

        // Admin
        $this->router->add('GET', '/api/admin/users', [$adminCtrl, 'listUsers']);
        $this->router->add('POST', '/api/admin/users', [$adminCtrl, 'createUser']);
        $this->router->add('PUT', '/api/admin/users/{id}', [$adminCtrl, 'updateUser']);
        $this->router->add('POST', '/api/admin/users/{id}/toggle-active', [$adminCtrl, 'toggleUserActive']);
        $this->router->add('GET', '/api/admin/modules', [$adminCtrl, 'listModules']);
        $this->router->add('POST', '/api/admin/modules/{id}/toggle', [$adminCtrl, 'toggleModule']);
        $this->router->add('GET', '/api/admin/settings', [$adminCtrl, 'getSettings']);
        $this->router->add('PUT', '/api/admin/settings', [$adminCtrl, 'updateSettings']);
        $this->router->add('GET', '/api/admin/audit', [$adminCtrl, 'listAudit']);
        $this->router->add('GET', '/api/admin/audit.csv', [$adminCtrl, 'exportAuditCsv']);
        $this->router->add('GET', '/api/admin/backups', [$adminCtrl, 'listBackups']);
        $this->router->add('POST', '/api/admin/backups', [$adminCtrl, 'createBackup']);
        $this->router->add('GET', '/api/admin/backups/{file}', [$adminCtrl, 'downloadBackup']);

        // Modul programari
        if (in_array('programari', $moduleIds, true)) {
            $repo = new AppointmentRepo();
            $history = new HistoryService($this->config);
            $service = new AppointmentService($this->db, $this->config, $settings, $repo, $history, $audit);
            $progCtrl = new ProgramariController($auth, $modules, $service, $this->config);
            $progCtrl->registerRoutes($this->router);
        }

        // Healthcheck simplu (fara expunere detalii interne)
        $this->router->add('GET', '/api/health', fn(): array => Response::json([
            'ok' => true,
            'today_date' => $this->config->todayIso(),
        ]));
    }

    public function handle(Request $req): array
    {
        $path = $req->path();
        if (!str_starts_with($path, '/api/')) {
            $shellPath = $this->config->baseDir() . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'shell.html';
            if (!is_file($shellPath)) {
                return Response::error('SHELL_MISSING', 'Interfața aplicației lipsește.', 500);
            }
            return Response::html((string) file_get_contents($shellPath), 200);
        }

        return $this->router->dispatch($req);
    }

    public function db(): Database
    {
        return $this->db;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function migrationWarnings(): array
    {
        return $this->migrationWarnings;
    }
}
