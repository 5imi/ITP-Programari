<?php
declare(strict_types=1);

namespace Modules\Programari;

use Core\Auth;
use Core\Config;
use Core\Database;
use Core\HttpException;
use Core\ModuleLoader;
use Core\Request;
use Core\Response;
use Core\Router;

/**
 * Controller HTTP pentru modulul `programari` (/api/m/programari/...).
 */
final class Controller
{
    public function __construct(
        private readonly Auth $auth,
        private readonly ModuleLoader $modules,
        private readonly AppointmentService $service,
        private readonly Config $config
    ) {
    }

    public function registerRoutes(Router $router): void
    {
        $router->add('GET', '/api/m/programari/appointments', function (Request $req): array {
            $this->assertEnabled();
            $isPoll = (string) $req->query('poll', '0') === '1';
            $this->auth->requireUser($req, false, !$isPoll);
            $data = $this->service->getAgenda([
                'date' => $req->query('date', ''),
                'search_all' => $req->query('search_all', '0'),
                'include_future' => $req->query('include_future', '1'),
                'q' => $req->query('q', ''),
                'line' => $req->query('line', 'Toate'),
                'status' => $req->query('status', 'Toate'),
            ]);
            return Response::json(array_merge(['ok' => true], $data));
        });

        $router->add('GET', '/api/m/programari/slots', function (Request $req): array {
            $this->assertEnabled();
            $this->auth->requireUser($req, false);
            $date = (string) $req->query('date', $this->config->todayIso());
            $line = (string) $req->query('line', 'Linia 1');
            $excl = (int) $req->query('exclude_id', 0);
            $data = $this->service->getFreeSlots($date, $line, $excl > 0 ? $excl : null);
            return Response::json(array_merge(['ok' => true], $data));
        });

        $router->add('GET', '/api/m/programari/lookup', function (Request $req): array {
            $this->assertEnabled();
            $this->auth->requireUser($req, false);
            $date = trim((string) $req->query('date', ''));
            $excludeId = (int) $req->query('exclude_id', 0);
            $excludeParentId = (int) $req->query('exclude_parent_id', 0);
            $match = $this->service->lookupByPlate(
                (string) $req->query('plate', ''),
                $date,
                $excludeId > 0 ? $excludeId : null,
                $excludeParentId > 0 ? $excludeParentId : null
            );
            return Response::json(['ok' => true, 'match' => $match]);
        });

        $router->add('POST', '/api/m/programari/appointments', function (Request $req): array {
            $this->assertEnabled();
            $ctx = $this->auth->requirePermission($req, 'programari.edit', true);
            $created = $this->service->create($req->json(), $ctx['user'], $req);
            return Response::json(['ok' => true, 'appointment' => $created], 201);
        });

        $router->add('PUT', '/api/m/programari/appointments/{id}', function (Request $req, array $params): array {
            $this->assertEnabled();
            $ctx = $this->auth->requirePermission($req, 'programari.edit', true);
            $updated = $this->service->update((int) $params['id'], $req->json(), $ctx['user'], $req);
            return Response::json(['ok' => true, 'appointment' => $updated]);
        });

        $router->add('POST', '/api/m/programari/appointments/{id}/revenire', function (Request $req, array $params): array {
            $this->assertEnabled();
            $ctx = $this->auth->requirePermission($req, 'programari.revenire', true);
            $updated = $this->service->markRevenire((int) $params['id'], $req->json(), $ctx['user'], $req);
            return Response::json(['ok' => true, 'appointment' => $updated]);
        });

        $router->add('POST', '/api/m/programari/appointments/{id}/revoke', function (Request $req, array $params): array {
            $this->assertEnabled();
            $ctx = $this->auth->requirePermission($req, 'programari.revenire', true);
            $updated = $this->service->revokeRevenire((int) $params['id'], $req->json(), $ctx['user'], $req);
            return Response::json(['ok' => true, 'appointment' => $updated]);
        });

        $router->add('POST', '/api/m/programari/appointments/{id}/reprogram', function (Request $req, array $params): array {
            $this->assertEnabled();
            $ctx = $this->auth->requirePermission($req, 'programari.revenire', true);
            $child = $this->service->reprogramFromRevenire((int) $params['id'], $req->json(), $ctx['user'], $req);
            return Response::json(['ok' => true, 'appointment' => $child], 201);
        });

        $router->add('DELETE', '/api/m/programari/appointments/{id}', function (Request $req, array $params): array {
            $this->assertEnabled();
            $ctx = $this->auth->requirePermission($req, 'programari.delete', true);
            $res = $this->service->softDelete((int) $params['id'], $req->json(), $ctx['user'], $req);
            return Response::json($res);
        });

        $router->add('GET', '/api/m/programari/history', function (Request $req): array {
            $this->assertEnabled();
            $this->auth->requireUser($req, false);
            $data = $this->service->getHistory([
                'q' => $req->query('q', ''),
                'event_type' => $req->query('event_type', 'Toate'),
                'actor' => $req->query('actor', 'TOȚI'),
                'scope' => $req->query('scope', ''),
            ]);
            return Response::json(array_merge(['ok' => true], $data));
        });

        $router->add('GET', '/api/m/programari/history.csv', function (Request $req): array {
            $this->assertEnabled();
            $this->auth->requireUser($req, false);
            $data = $this->service->getHistory([
                'q' => $req->query('q', ''),
                'event_type' => $req->query('event_type', 'Toate'),
                'actor' => $req->query('actor', 'TOȚI'),
                'scope' => $req->query('scope', ''),
            ]);
            $rows = [];
            foreach ($data['entries'] as $entry) {
                foreach ($entry['events'] as $ev) {
                    $rows[] = [
                        $entry['plate'],
                        $entry['client'],
                        $ev['date'],
                        $ev['at'],
                        $ev['actor_name'],
                        $ev['event_type'],
                        $ev['summary'],
                        $entry['deleted'] ? 'Ștearsă' : 'Activă',
                    ];
                }
            }
            return Response::csv(
                'itp_istoric_imutabil_' . $this->config->now()->format('Ymd') . '.csv',
                ['Nr. Auto', 'Client/Vehicul', 'Data Actiune', 'Ora', 'Utilizator', 'Tip Actiune', 'Detalii', 'Stare'],
                $rows
            );
        });

        $router->add('GET', '/api/m/programari/reasons', function (Request $req): array {
            $this->assertEnabled();
            $this->auth->requireUser($req, false);
            $kind = $req->query('kind', null);
            $reasons = $this->service->listReasons(is_string($kind) && $kind !== '' ? $kind : null);
            return Response::json(['ok' => true, 'reasons' => $reasons]);
        });

        $router->add('POST', '/api/m/programari/reasons', function (Request $req): array {
            $this->assertEnabled();
            $ctx = $this->auth->requireAdmin($req, true);
            $body = $req->json();
            $created = $this->service->addReason((string) ($body['kind'] ?? 'revenire'), (string) ($body['label'] ?? ''), $ctx['user'], $req);
            return Response::json(['ok' => true, 'reason' => $created], 201);
        });

        $router->add('PUT', '/api/m/programari/reasons/{id}', function (Request $req, array $params): array {
            $this->assertEnabled();
            $ctx = $this->auth->requireAdmin($req, true);
            $body = $req->json();
            $updated = $this->service->updateReason((int) $params['id'], (string) ($body['label'] ?? ''), $ctx['user'], $req);
            return Response::json(['ok' => true, 'reason' => $updated]);
        });

        $router->add('DELETE', '/api/m/programari/reasons/{id}', function (Request $req, array $params): array {
            $this->assertEnabled();
            $ctx = $this->auth->requireAdmin($req, true);
            $this->service->deleteReason((int) $params['id'], $ctx['user'], $req);
            return Response::json(['ok' => true]);
        });
    }

    private function assertEnabled(): void
    {
        if (!$this->modules->isEnabled('programari')) {
            throw new HttpException(404, 'MODULE_DISABLED', 'Modulul Programări este dezactivat.');
        }
    }
}
