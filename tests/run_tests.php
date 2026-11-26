<?php
declare(strict_types=1);

/**
 * Suita de testare PHP CLI (zero dependente externe) pentru backend-ul ITP MariaDB.
 * Rulare locala (Laragon / XAMPP) sau staging:
 *   php tests/run_tests.php http://127.0.0.1:8000
 */

require_once dirname(__DIR__) . '/core/Autoloader.php';
\Core\Autoloader::register(dirname(__DIR__));

$baseUrl = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');
$cookieFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'itp_test_cookies_' . getmypid() . '.txt';
@unlink($cookieFile);

function httpReq(string $method, string $url, ?array $body = null, string $csrf = '', string $cookieFile = ''): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($csrf !== '') {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($cookieFile !== '') {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException("cURL failure on $method $url: $err");
    }
    $json = json_decode($raw, true);
    return ['status' => $status, 'body' => is_array($json) ? $json : [], 'raw' => $raw];
}

function assertTrue(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "[FAIL] $msg\n");
        exit(1);
    }
}

echo "=== Testare backend PHP + MariaDB @ $baseUrl ===\n";

// 1. Setup status & First-Start (daca baza e goala)
$setupStatus = httpReq('GET', "$baseUrl/api/auth/setup-status");
assertTrue($setupStatus['status'] === 200, 'setup-status trebuie să întoarcă 200');

$csrf = '';
$adminPin = '4921';
$masterPin = '87654321';

if (!empty($setupStatus['body']['needs_setup'])) {
    $fs = httpReq('POST', "$baseUrl/api/setup/first-start", [
        'station_name' => 'ACW Test Station',
        'display_name' => 'LaurTest',
        'username' => 'laurtest',
        'pin' => $adminPin,
        'master_pin' => $masterPin,
    ], '', $cookieFile);
    assertTrue($fs['status'] === 201, 'first-start trebuie să întoarcă 201');
    $csrf = (string) ($fs['body']['csrf_token'] ?? '');
} else {
    echo "[INFO] Baza are deja utilizatori — se autentifică primul admin disponibil cu PIN din ITP_TEST_ADMIN_PIN.\n";
    $adminPin = getenv('ITP_TEST_ADMIN_PIN') ?: $adminPin;
    $users = httpReq('GET', "$baseUrl/api/auth/users");
    $admin = null;
    foreach ($users['body']['users'] ?? [] as $u) {
        if ($u['role'] === 'admin') {
            $admin = $u;
            break;
        }
    }
    assertTrue($admin !== null, 'Nu există cont admin în users');
    $login = httpReq('POST', "$baseUrl/api/auth/login", ['user_id' => $admin['id'], 'pin' => $adminPin], '', $cookieFile);
    assertTrue($login['status'] === 200, 'Login admin eșuat: ' . ($login['raw'] ?? ''));
    $csrf = (string) ($login['body']['csrf_token'] ?? '');
}

$me = httpReq('GET', "$baseUrl/api/auth/me", null, '', $cookieFile);
assertTrue($me['status'] === 200 && !empty($me['body']['today_date']), '/api/auth/me trebuie să întoarcă today_date');
$today = (string) $me['body']['today_date'];
$dt = (new DateTimeImmutable($today))->modify('+2 days');
if ((int) $dt->format('N') === 7) {
    $dt = $dt->modify('+1 day');
}
$testDate = $dt->format('Y-m-d');

// 2. Test concurenta reala (10 cereri paralele pe acelasi slot!)
echo "[TEST] Concurență: 10 cereri simultane pe ($testDate, 09:00, Linia 1)...\n";
$mh = curl_multi_init();
$handles = [];
for ($i = 0; $i < 10; $i++) {
    $ch = curl_init("$baseUrl/api/m/programari/appointments");
    $payload = json_encode([
        'date' => $testDate,
        'time' => '09:00',
        'line' => 'Linia 1',
        'plate' => sprintf('SV %02d RAC', 10 + $i),
        'phone' => '0744111222',
        'client' => 'Test Concurenta ' . $i,
    ], JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-CSRF-Token: ' . $csrf,
        ],
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_POSTFIELDS => $payload,
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[] = $ch;
}

do {
    $status = curl_multi_exec($mh, $running);
    if ($running) {
        curl_multi_select($mh);
    }
} while ($running && $status === CURLM_OK);

$createdCount = 0;
$slotTakenCount = 0;
$winnerId = 0;
foreach ($handles as $ch) {
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $raw = curl_multi_getcontent($ch);
    $json = json_decode((string) $raw, true) ?: [];
    if ($code === 201) {
        $createdCount++;
        $winnerId = (int) ($json['appointment']['id'] ?? 0);
    } elseif ($code === 409 && ($json['error'] ?? '') === 'SLOT_TAKEN') {
        $slotTakenCount++;
    } else {
        echo "  Răspuns neașteptat ($code): $raw\n";
    }
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);

assertTrue($createdCount === 1, "Exact 1 cerere trebuia să câștige slotul, găsit: $createdCount");
assertTrue($slotTakenCount === 9, "Celelalte 9 trebuiau să primească 409 SLOT_TAKEN, găsit: $slotTakenCount");
echo "[PASS] Race condition rezolvat atomic: 1 câștigător (#$winnerId), 9 x 409 SLOT_TAKEN.\n";

// 3. Test STALE_VERSION optimistic locking
$upd1 = httpReq('PUT', "$baseUrl/api/m/programari/appointments/$winnerId", [
    'date' => $testDate,
    'time' => '09:00',
    'line' => 'Linia 1',
    'plate' => 'SV 99 VER',
    'phone' => '0744111222',
    'client' => 'Test Versiune 2',
    'version' => 1,
], $csrf, $cookieFile);
assertTrue($upd1['status'] === 200 && (int) ($upd1['body']['appointment']['version'] ?? 0) === 2, 'Editarea v1 trebuia să treacă la v2');

$updStale = httpReq('PUT', "$baseUrl/api/m/programari/appointments/$winnerId", [
    'date' => $testDate,
    'time' => '09:30',
    'line' => 'Linia 1',
    'plate' => 'SV 99 VER',
    'phone' => '0744111222',
    'client' => 'Suprascriere veche',
    'version' => 1,
], $csrf, $cookieFile);
assertTrue($updStale['status'] === 409 && ($updStale['body']['error'] ?? '') === 'STALE_VERSION', 'Editarea cu version=1 trebuia respinsă cu 409 STALE_VERSION');
echo "[PASS] Optimistic locking (409 STALE_VERSION) verificat.\n";

// 4. Test flux Revenire -> REVOCAT -> Revenire -> Reprogramare -> Stergere copil -> redeschidere parinte
$rev1 = httpReq('POST', "$baseUrl/api/m/programari/appointments/$winnerId/revenire", [
    'version' => 2,
    'reasons' => ['Frâne'],
    'note' => 'Test frână spate',
], $csrf, $cookieFile);
$expectedDeadline = (new DateTimeImmutable($testDate))->modify('+30 days')->format('Y-m-d');
assertTrue(
    $rev1['status'] === 200
    && ($rev1['body']['appointment']['status'] ?? '') === 'Revenire'
    && ($rev1['body']['appointment']['reinspection_deadline'] ?? '') === $expectedDeadline,
    'Marcare Revenire eșuată sau reinspection_deadline lipsă'
);

$revoke = httpReq('POST', "$baseUrl/api/m/programari/appointments/$winnerId/revoke", [
    'version' => 3,
], $csrf, $cookieFile);
assertTrue(
    $revoke['status'] === 200
    && ($revoke['body']['appointment']['status'] ?? '') === 'Programat'
    && ($revoke['body']['appointment']['reinspection_deadline'] ?? 'x') === null,
    'Revocare Revenire eșuată'
);

$rev2 = httpReq('POST', "$baseUrl/api/m/programari/appointments/$winnerId/revenire", [
    'version' => 4,
    'reasons' => ['Lumini / Semnalizare'],
    'note' => 'Far stânga',
], $csrf, $cookieFile);
assertTrue($rev2['status'] === 200, 'Re-marcare Revenire eșuată');

$reprogDate = $dt->modify('+1 day');
if ((int) $reprogDate->format('N') === 7) {
    $reprogDate = $reprogDate->modify('+1 day');
}
$reprog = httpReq('POST', "$baseUrl/api/m/programari/appointments/$winnerId/reprogram", [
    'date' => $reprogDate->format('Y-m-d'),
    'time' => '10:30',
    'line' => 'Linia 2',
], $csrf, $cookieFile);
assertTrue($reprog['status'] === 201, 'Reprogramare revenire eșuată');
$childId = (int) ($reprog['body']['appointment']['id'] ?? 0);

// 4b. Test protectie revocare si stergere parinte cat timp exista reprogramare activa (409 HAS_ACTIVE_REINSPECTION)
$revokeBlocked = httpReq('POST', "$baseUrl/api/m/programari/appointments/$winnerId/revoke", [
    'version' => 6,
], $csrf, $cookieFile);
assertTrue(
    $revokeBlocked['status'] === 409 && ($revokeBlocked['body']['error'] ?? '') === 'HAS_ACTIVE_REINSPECTION',
    'Revocarea cu reprogramare activă trebuia blocată cu 409 HAS_ACTIVE_REINSPECTION'
);
$deleteParentBlocked = httpReq('DELETE', "$baseUrl/api/m/programari/appointments/$winnerId", [
    'version' => 6,
    'reason' => 'Test blocare',
], $csrf, $cookieFile);
assertTrue(
    $deleteParentBlocked['status'] === 409 && ($deleteParentBlocked['body']['error'] ?? '') === 'HAS_ACTIVE_REINSPECTION',
    'Ștergerea părintelui cu reprogramare activă trebuia blocată cu 409 HAS_ACTIVE_REINSPECTION'
);

// 4b-1.5. Test R08 (Reveniri deschise) & R09 (lookup open_revenire) & R10 (reinspection_date)
$openAgenda = httpReq('GET', "$baseUrl/api/m/programari/appointments?status=Reveniri%20deschise", null, '', $cookieFile);
assertTrue(
    $openAgenda['status'] === 200 && isset($openAgenda['body']['open_reveniri_count']),
    'Agenda trebuie să întoarcă open_reveniri_count'
);
$parentAgendaCheck = httpReq('GET', "$baseUrl/api/m/programari/appointments?date=$testDate", null, '', $cookieFile);
$parentRowCheck = null;
foreach ($parentAgendaCheck['body']['appointments'] ?? [] as $pa) {
    if ((int) $pa['id'] === $winnerId) {
        $parentRowCheck = $pa;
    }
}
assertTrue(
    $parentRowCheck !== null && ($parentRowCheck['reinspection_date'] ?? '') === $reprogDate->format('Y-m-d'),
    'Părintele reprogramat trebuie să includă reinspection_date'
);

// 4b-2. Test R05: editare motive pe parinte sincronizeaza verify_notes pe copil
$syncParent = httpReq('POST', "$baseUrl/api/m/programari/appointments/$winnerId/revenire", [
    'version' => 6,
    'reasons' => ['Frâne', 'Anvelope / Jante'],
    'note' => 'Cauciuc dreapta',
], $csrf, $cookieFile);
assertTrue($syncParent['status'] === 200, 'Re-editare motive pe părinte cu copil activ a eșuat');
$childAgenda = httpReq('GET', "$baseUrl/api/m/programari/appointments?date=" . $reprogDate->format('Y-m-d'), null, '', $cookieFile);
$childRow = null;
foreach ($childAgenda['body']['appointments'] ?? [] as $ca) {
    if ((int) $ca['id'] === $childId) {
        $childRow = $ca;
    }
}
assertTrue(
    $childRow !== null && str_contains((string) ($childRow['verify_notes'] ?? ''), 'Anvelope / Jante'),
    'Editarea motivelor pe părinte trebuia să sincronizeze verify_notes pe copil'
);

$delChild = httpReq('DELETE', "$baseUrl/api/m/programari/appointments/$childId", [
    'version' => (int) $childRow['version'],
    'reason' => 'Anulat de client',
], $csrf, $cookieFile);
assertTrue($delChild['status'] === 200, 'Ștergere vizită revenire eșuată');

$agendaCheck = httpReq('GET', "$baseUrl/api/m/programari/appointments?date=$testDate", null, '', $cookieFile);
$parentAfter = null;
foreach ($agendaCheck['body']['appointments'] ?? [] as $a) {
    if ((int) $a['id'] === $winnerId) {
        $parentAfter = $a;
    }
}
assertTrue($parentAfter !== null && $parentAfter['resolved_by_reinspection_id'] === null, 'Părintele trebuia să aibă resolved_by_reinspection_id = NULL după ștergerea vizitei de revenire');
echo "[PASS] Flux complet Revenire + REVOCAT + Reprogramare + protecție revocare activă + eliberare părinte verificat.\n";

// 4c. Test securitate: change-pin greșit întoarce 422 (nu 401), iar master_pin nu poate fi modificat prin PUT /api/admin/users
$badChangePin = httpReq('POST', "$baseUrl/api/auth/change-pin", [
    'current_pin' => '000000',
    'new_pin' => '9876',
], $csrf, $cookieFile);
assertTrue(
    $badChangePin['status'] === 422 && ($badChangePin['body']['error'] ?? '') === 'INVALID_CURRENT_PIN',
    'PIN curent greșit la change-pin trebuia să întoarcă 422 INVALID_CURRENT_PIN'
);
$meAfterBadPin = httpReq('GET', "$baseUrl/api/auth/me", null, '', $cookieFile);
assertTrue($meAfterBadPin['status'] === 200, 'Sesiunea trebuia să rămână validă după PIN curent greșit');
$adminId = (int) ($meAfterBadPin['body']['user']['id'] ?? 1);
$masterBlock = httpReq('PUT', "$baseUrl/api/admin/users/$adminId", [
    'display_name' => (string) ($meAfterBadPin['body']['user']['display_name'] ?? 'Admin'),
    'role' => 'admin',
    'master_pin' => '12345678',
], $csrf, $cookieFile);
assertTrue(
    $masterBlock['status'] === 403 && ($masterBlock['body']['error'] ?? '') === 'MASTER_PIN_PROTECTED',
    'Modificarea master_pin prin panoul standard trebuia respinsă cu 403 MASTER_PIN_PROTECTED'
);
echo "[PASS] Protecții securitate (422 change-pin, 403 MASTER_PIN_PROTECTED) verificate.\n";

// 5. Test Istoric Imutabil
$hist = httpReq('GET', "$baseUrl/api/m/programari/history?q=SV%2099%20VER", null, '', $cookieFile);
assertTrue(is_array($hist['body']['all_actors'] ?? null), 'Istoric trebuie să întoarcă lista all_actors');
$winnerEntry = null;
foreach ($hist['body']['entries'] ?? [] as $entry) {
    if ((int) ($entry['appointment_id'] ?? 0) === $winnerId) {
        $winnerEntry = $entry;
        break;
    }
}
assertTrue($winnerEntry !== null, 'Nu a fost găsită intrarea de istoric pentru programarea părinte');
$events = $winnerEntry['events'] ?? [];
$types = array_column($events, 'event_type');
foreach (['CREAT', 'MODIFICAT', 'REVENIRE', 'REVOCAT', 'REPROGRAMAT'] as $reqType) {
    assertTrue(in_array($reqType, $types, true), "Lipsește tipul de eveniment $reqType din istoric");
}
echo "[PASS] Istoric Imutabil conține toate evenimentele cu Dată + Oră + Actor.\n";

// Curatare programare test
$delParent = httpReq('DELETE', "$baseUrl/api/m/programari/appointments/$winnerId", [
    'version' => (int) $parentAfter['version'],
    'reason' => 'Test automat',
], $csrf, $cookieFile);
assertTrue($delParent['status'] === 200, 'Curățare programare test eșuată');

@unlink($cookieFile);
echo "=== TOATE TESTELE BACKEND AU TRECUT ===\n";
