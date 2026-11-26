# Ghid Detaliat de Implementare — Variantă PHP 8 + MariaDB (`ACW - Fălticeni` • Romarg cPanel)

> **Notă:** Versiunea Python/FastAPI/SQLite/Docker rămâne păstrată în [IMPLEMENTARE.md](IMPLEMENTARE.md) pentru o eventuală trecere viitoare pe VPS. Acest ghid acoperă pas cu pas implementarea completă în **PHP 8.2+ pur + MariaDB InnoDB**, optimizată să ruleze fără bătăi de cap și fără race conditions pe cel mai ieftin pachet **Romarg Găzduire Web cPanel (`1,95 €/lună`)**.

---

## 0. Strategie și Stack Confirmat

- **Frontend aprobat:** identic vizual cu [dashboard.html](dashboard.html) (Tabler Light local din `references/ui-libs/tabler/`, logo `aw-logo.png`, fundal cald WhatsApp, tastatură PIN + Dual-PIN Admin, `Istoric Imutabil` cu dată+oră+actor, `Jurnal securitate`, `Motive` 1-tap, `Schimbă PIN`, buton `🔍` mobil, ocupare linii, `robots.txt`).
- **Backend:** **PHP 8.2+** pur (PDO nativ `pdo_mysql`, `password_hash`, `random_bytes`, `DateTimeZone('Europe/Bucharest')`, zero Composer obligatoriu).
- **Bază de date:** **MariaDB (InnoDB, `utf8mb4_unicode_ci`)** — tranzacții ACID, `UNIQUE KEY` pentru sloturi active, `SELECT ... FOR UPDATE` pentru editări concurente, `TRIGGER`-e append-only.
- **Contract API:** păstrează **exact aceleași rute și payload-uri JSON** (`/api/auth/...`, `/api/admin/...`, `/api/m/programari/...`) ca varianta Python, astfel frontend-ul este 100% reutilizabil.

### Calendar Estimat (~2 Zile)

| Ziua | Etape acoperite | Durată estimată | Livrabil principal |
| :--- | :--- | :---: | :--- |
| **Ziua 1** | Etapele **0 – 5** (`Faza 0 – Core` PHP) | **~6 ore** | Router PHP, `.htaccess`, PDO MariaDB + migrări, Auth Dual-PIN, Audit, Export Backup, App Shell UI |
| **Ziua 2** | Etapele **6 – 9** (`programari` + Race-Condition Tests + Deploy Romarg) | **~6 – 7 ore** | Modul `programari`, `Istoric Imutabil` complet, teste concurență, publicare cPanel Romarg + SSL |

---

## Etapa 0 — Structură Proiect, Configurare și Securitate `.htaccess` (~0.5h)

### Fișiere de bază la rădăcină

1. **`config/env.example.php` → copiat ca `config/env.php` (ignorat din Git prin `.gitignore`):**

```php
<?php
return [
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_NAME' => 'acw_itp',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'APP_TZ'  => 'Europe/Bucharest',
    'COOKIE_SECURE' => 'auto', // 'auto' | '1' | '0'
    'SESSION_HOURS' => 12,
    'SESSION_IDLE_HOURS' => 4,
    'TRUSTED_PROXIES' => ['127.0.0.1'],
    'FIRST_START_TOKEN' => '', // opțional pe cPanel până la crearea Adminului
];
```

2. **`.htaccess` (Apache / LiteSpeed pe Romarg cPanel):**
   - Blochează accesul HTTP direct la orice fișier sensibil (`config/`, `core/`, `modules/`, `backups/`, `*.sql`, `*.md`, `.git`, `.env`).
   - Trimite către `index.php` toate cererile care nu sunt fișiere statice reale din `assets/`, `robots.txt` sau `manifest.json`:

```apacheconf
Options -Indexes
ServerSignature Off

<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteRule ^(\.git|\.env|config/|core/|modules/|backups/) - [F,L]
  RewriteRule \.(sql|md|log|db|sqlite)$ - [F,L]

  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^ index.php [QSA,L]
</IfModule>
```

3. **`core/Autoloader.php`:** mapare simplă `Core\` → `core/` și `Modules\Programari\` → `modules/programari/` folosind `spl_autoload_register`, fără Composer.
4. **Mediu local Windows (pentru dezvoltare acasă înainte de upload pe Romarg):**
   - **Laragon** sau **XAMPP** (PHP 8.2/8.3 + MariaDB), pornind direct proiectul sau prin `php -S 0.0.0.0:8000 index.php` conectat la MariaDB local.

---

## Etapa 1 — Baza de Date MariaDB (`Core/Database.php` + Migrări) (~1h)

### `core/Database.php`

- Creează singleton `PDO` cu DSN `mysql:host=...;port=...;dbname=...;charset=utf8mb4` și opțiunile obligatorii:
  - `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`
  - `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`
  - `PDO::ATTR_EMULATE_PREPARES => false` *(prepared statements reale în MariaDB)*
- Metodă `transaction(callable $fn)` cu protecție la reîncercare scurtă în caz de deadlock InnoDB (`SQLSTATE 40001` / `1213`):
  1. `$pdo->beginTransaction()`;
  2. execută `$fn($pdo)`;
  3. `$pdo->commit()`, sau `$pdo->rollBack()` la orice `Throwable`.
- **Migrator (`core/Migrator.php`):** tabel `_migrations(module_id, filename, checksum, applied_at)` — rulează `.sql`-urile neaplicate și verifică SHA-256 pe cele existente (fără a modifica schema pe ascuns).

### Schema SQL Core — `core/migrations/001_core.sql` (MariaDB InnoDB)

```sql
CREATE TABLE IF NOT EXISTS _migrations (
    module_id VARCHAR(64) NOT NULL,
    filename VARCHAR(128) NOT NULL,
    checksum CHAR(64) NOT NULL,
    applied_at DATETIME NOT NULL,
    PRIMARY KEY (module_id, filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    pin_hash VARCHAR(255) NOT NULL,
    master_pin_hash VARCHAR(255) NULL,
    is_primary_admin TINYINT(1) NOT NULL DEFAULT 0,
    primary_guard TINYINT GENERATED ALWAYS AS (IF(is_primary_admin = 1, 1, NULL)) STORED,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    must_change_pin TINYINT(1) NOT NULL DEFAULT 0,
    session_version INT UNSIGNED NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_one_primary (primary_guard)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_permissions (
    user_id INT UNSIGNED NOT NULL,
    permission VARCHAR(64) NOT NULL,
    PRIMARY KEY (user_id, permission),
    CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
    token_hash CHAR(64) PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    session_version INT UNSIGNED NOT NULL,
    csrf_token CHAR(64) NOT NULL,
    ip_address VARCHAR(64) NOT NULL DEFAULT '',
    user_agent VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    KEY idx_sessions_user (user_id),
    CONSTRAINT fk_sess_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    ip_address VARCHAR(64) NOT NULL,
    kind ENUM('pin', 'master') NOT NULL,
    success TINYINT(1) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_la_user_time (user_id, kind, created_at),
    KEY idx_la_ip_time (ip_address, kind, created_at),
    CONSTRAINT fk_la_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS modules_state (
    module_id VARCHAR(64) PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    version VARCHAR(32) NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(64) PRIMARY KEY,
    value_json JSON NOT NULL,
    updated_at DATETIME NOT NULL,
    updated_by VARCHAR(100) NOT NULL DEFAULT 'system'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS core_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME NOT NULL,
    user_id INT UNSIGNED NULL,
    actor_name VARCHAR(120) NOT NULL,
    module_id VARCHAR(64) NOT NULL DEFAULT 'core',
    action VARCHAR(48) NOT NULL,
    entity_type VARCHAR(64) NULL,
    entity_id VARCHAR(64) NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    summary VARCHAR(500) NOT NULL DEFAULT '',
    ip_address VARCHAR(64) NOT NULL DEFAULT '',
    user_agent VARCHAR(255) NOT NULL DEFAULT '',
    KEY idx_audit_time (created_at DESC),
    KEY idx_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Trigger-e Append-Only pe `core_audit_log` (`002_core_triggers.sql`)

```sql
CREATE TRIGGER trg_core_audit_no_update
BEFORE UPDATE ON core_audit_log
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'core_audit_log este append-only';

CREATE TRIGGER trg_core_audit_no_delete
BEFORE DELETE ON core_audit_log
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'core_audit_log este append-only';
```

> **Notă Shared Hosting cPanel:** anumite noduri shared restricționează `CREATE TRIGGER` dacă binlog-ul e activ fără `SUPER`. Runner-ul de migrări va încerca crearea trigger-elor separat și, dacă serverul refuză privilegiul, consemnează avertisment în logul de instalare — imutabilitatea rămânând oricum garantată strict și în cod (`AuditLogger` + `HistoryService` au **exclusiv `INSERT` și `SELECT`**, fără nicio metodă de `UPDATE` sau `DELETE`).

---

## Etapa 2 — Securitate, Autentificare PIN, Dual-PIN & Sesiuni (~1.5h)

- **`Security.php`:**
  - `hashPin(string $pin): string` → `password_hash($pin, PASSWORD_BCRYPT)`
  - `verifyPin(string $pin, string $hash): bool` → `password_verify($pin, $hash)`
  - `newToken(): string` → `rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')`
  - `hashToken(string $token): string` → `hash('sha256', $token)`
  - `verifyCsrf(Request $req, array $session): void` → `hash_equals($session['csrf_token'], $req->header('X-CSRF-Token'))`
- **Endpoint-uri Core Auth (identice ca contract JSON cu varianta FastAPI):**
  - `GET /api/auth/users` — cardurile utilizatorilor activi/blocați (`id`, `display_name`, `role`, `is_active`, `has_master_pin`), fără hash-uri.
  - `POST /api/auth/login` — `{user_id, pin}`, verifică `login_attempts` (5 greșeli / 5 min → `429`), validează `pin_hash`, setează cookie `itp_session` și loghează `LOGIN_OK` / `LOGIN_FAIL`.
  - `POST /api/auth/master-recovery` — `{master_pin, new_pin}`: în tranzacție `SELECT ... FOR UPDATE` pe Adminul principal (`is_primary_admin=1`), verifică `master_pin_hash` (3 greșeli / 15 min → `429` blocare 30 min), incrementează `session_version`, actualizează `pin_hash`, șterge toate sesiunile vechi din `sessions`, creează sesiune nouă și scrie `ADMIN_MASTER_RECOVERY` în `core_audit_log`.
  - `POST /api/auth/change-pin` — `{current_pin, new_pin}`: permite oricărui utilizator logat (`Laur`, `Vlad`, `Gabi`) să își schimbe propriul PIN uzual, scriind `PIN_CHANGE` în `core_audit_log`.
  - `POST /api/auth/logout` — șterge sesiunea curentă + cookie-ul + log `LOGOUT`.
  - `GET /api/auth/me` — profil activ, permisiuni și `csrf_token`.
  - `POST /api/setup/first-start` — activ exclusiv când `SELECT COUNT(*) FROM users` == `0` (plus `FIRST_START_TOKEN` din `config/env.php` dacă se rulează direct pe domeniu cPanel).

---

## Etapa 3 — Panou Admin, Audit & Backup MariaDB (~1h)

- **`AdminController.php`** *(toate rutele protejate prin `Auth::requireAdmin()` + verificare `X-CSRF-Token`)*:
  - **Utilizatori & Permisiuni:** `GET/POST/PUT /api/admin/users...`, blocare/activare (`USER_BLOCK`, `USER_UNBLOCK`, `USER_CREATE`, `USER_UPDATE`), protecție strictă pe `is_primary_admin = 1` (nu poate fi blocat sau retrogradat).
  - **Module:** `GET /api/admin/modules` + `POST /api/admin/modules/{id}/toggle` (`programari` blocat activ; scrie `MODULE_TOGGLE`).
  - **Setări stație:** `GET/PUT /api/admin/settings` (`ACW - Fălticeni`, Luni–Sâmbătă, `08:00–17:30`, `30 min`, `Linia 1..3`).
  - **Jurnal Securitate:** `GET /api/admin/audit` (filtre categorie/utilizator/dată) + `GET /api/admin/audit.csv` (export UTF-8 cu BOM `\xEF\xBB\xBF` pentru diacritice în Excel).
  - **Backup (`BackupService.php`):** pe Romarg ai deja **Acronis zilnic automat** din cPanel; în plus, panoul Admin oferă export consistent la cerere (`POST /api/admin/backups` — snapshot JSON/SQL al tabelelor în `backups/` + `GET /api/admin/backups/{file}` pentru descărcare locală de siguranță pe PC-ul stației).

---

## Etapa 4 & 5 — ModuleLoader & Conectare Frontend (~1.5h)

- **`ModuleLoader.php`:** citește `modules/*/module.json`, rulează migrările modulului, expune rutele `/api/m/<id>/...` doar dacă `enabled = 1` în `modules_state` (altfel `404`), și construiește meniul în `GET /api/core/menu`.
- **Frontend (`assets/`):**
  - Se extrag CSS-ul și JS-ul din [dashboard.html](dashboard.html) în `assets/css/app.css`, `assets/js/api.js` (wrapper `fetch` + `X-CSRF-Token` + redirect la login pe `401`) și `assets/js/app.js`.
  - Se elimină `localStorage` și `resetDemoData`, conectând fiecare formular, modal, `Istoric Imutabil` și `Jurnal securitate` la API-ul PHP.
  - Se adaugă **`manifest.json`** pentru instalare rapidă pe telefon (`Add to Home Screen`).

---

## Etapa 6 — Modulul `programari` (`modules/programari/`) (~3.5h)

### Schema SQL — `modules/programari/migrations/001_init.sql` (MariaDB InnoDB)

```sql
CREATE TABLE IF NOT EXISTS appointments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    appt_date DATE NOT NULL,
    appt_time CHAR(5) NOT NULL,                  -- HH:MM
    line VARCHAR(32) NOT NULL,                   -- ex. Linia 1
    plate VARCHAR(24) NOT NULL,                  -- ex. B 458 ITP
    plate_key VARCHAR(24) NOT NULL,              -- ex. B458ITP
    phone VARCHAR(24) NOT NULL,
    client VARCHAR(160) NOT NULL DEFAULT '',
    status ENUM('Programat', 'Revenire') NOT NULL DEFAULT 'Programat',
    reject_reasons_json JSON NOT NULL,
    reject_note VARCHAR(500) NOT NULL DEFAULT '',
    visit_type ENUM('NORMAL', 'REVENIRE') NOT NULL DEFAULT 'NORMAL',
    parent_id BIGINT UNSIGNED NULL,
    resolved_by_reinspection_id BIGINT UNSIGNED NULL,
    reinspection_deadline DATE NULL,
    verify_notes VARCHAR(500) NOT NULL DEFAULT '',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    deleted_by INT UNSIGNED NULL,
    delete_reason VARCHAR(255) NULL,
    active_slot_flag TINYINT GENERATED ALWAYS AS (IF(deleted_at IS NULL, 1, NULL)) STORED,
    UNIQUE KEY uq_appt_slot (appt_date, appt_time, line, active_slot_flag),
    KEY idx_appt_date (appt_date, deleted_at),
    KEY idx_appt_plate_key (plate_key),
    CONSTRAINT fk_appt_parent FOREIGN KEY (parent_id) REFERENCES appointments(id) ON DELETE SET NULL,
    CONSTRAINT fk_appt_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appointment_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    appointment_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(32) NOT NULL,             -- Extensibil: CREAT, MODIFICAT, REVENIRE, REVOCAT, REPROGRAMAT, STERS
    actor_user_id INT UNSIGNED NULL,
    actor_name VARCHAR(120) NOT NULL,            -- Snapshot text: ex. "Vlad (User)"
    action_at DATETIME NOT NULL,                 -- Dată + Oră exactă (Europe/Bucharest)
    summary VARCHAR(700) NOT NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    reason VARCHAR(255) NULL,
    ip_address VARCHAR(64) NOT NULL DEFAULT '',
    KEY idx_ah_appt (appointment_id, id),
    KEY idx_ah_time (action_at DESC),
    KEY idx_ah_type (event_type),
    CONSTRAINT fk_ah_appt FOREIGN KEY (appointment_id) REFERENCES appointments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reasons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('revenire', 'stergere') NOT NULL,
    label VARCHAR(80) NOT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_reason_kind_label (kind, label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO reasons (kind, label, sort_order) VALUES
    ('revenire', 'Frâne', 10),
    ('revenire', 'Direcție / Suspensie', 20),
    ('revenire', 'Lumini / Semnalizare', 30),
    ('revenire', 'Noxe / Emisii', 40),
    ('revenire', 'Anvelope / Jante', 50),
    ('revenire', 'Caroserie / Parbriz', 60),
    ('revenire', 'Scurgeri lichide', 70),
    ('revenire', 'Acte / Dotări', 80),
    ('stergere', 'Anulat de client', 10),
    ('stergere', 'Neprezentare (No-show)', 20),
    ('stergere', 'Programare dublată / Greșeală', 30),
    ('stergere', 'Alt motiv', 40);
```

### Trigger-e Append-Only pe `appointment_history` (`002_history_triggers.sql`)

```sql
CREATE TRIGGER trg_appt_hist_no_update
BEFORE UPDATE ON appointment_history
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'appointment_history este append-only';

CREATE TRIGGER trg_appt_hist_no_delete
BEFORE DELETE ON appointment_history
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'appointment_history este append-only';
```

### Flux Tranzacțional Anti Race-Condition (`AppointmentService.php`)

1. **Creare (`POST /api/m/programari/appointments`):**
   - Validează `nr. auto` (`MIN 4` alfanumeric + formatare `B 458 ITP`), telefon RO (`07xx/02xx/03xx`), slot `08:00–17:30` din `30` min, linie validă, `date >= azi` pentru non-admin, **Duminică blocată** pentru non-admin (`422 INVALID_DAY`) + reconfirmare Admin (`confirm_sunday: true`).
   - Verifică duplicat în aceeași zi (`409 DUPLICATE_PLATE` dacă nu are `confirm_duplicate: true`).
   - Execută `INSERT INTO appointments (...)` în tranzacție; dacă concurența prinde `1062` pe `uq_appt_slot`, face `ROLLBACK` și întoarce **`409 SLOT_TAKEN`**.
   - Inserează evenimentul **`CREAT`** (cu `action_at`, `actor_name`, context) în `appointment_history` în aceeași tranzacție.
2. **Editare (`PUT /api/m/programari/appointments/{id}`):**
   - `SELECT ... FROM appointments WHERE id = ? FOR UPDATE`.
   - Verifică `version === payload['version']` (altfel `409 STALE_VERSION`).
   - Dacă este vizită `REVENIRE` și noua dată depășește `reinspection_deadline`, cere `confirm_over_deadline: true` (`409 OVER_DEADLINE`) și o trece în `visit_type = 'NORMAL'`.
   - Execută `UPDATE appointments SET ..., version = version + 1` (prinde `1062` → `409 SLOT_TAKEN`) + inserează **`MODIFICAT`** cu diff câmp cu câmp în `appointment_history`.
3. **Revenire / Revocare / Reprogramare / Ștergere:**
   - Toate folosesc **`SELECT ... FOR UPDATE`** + verificări de permisiuni (`programari.revenire`, `programari.delete`).
   - `POST .../revoke` scrie eveniment distinct **`REVOCAT`** (nu `MODIFICAT`).
   - `POST .../reprogram` creează noua vizită + actualizează `resolved_by_reinspection_id` pe părinte + scrie **`REPROGRAMAT`** pe părinte și **`CREAT`** pe noua vizită (sau `ITP complet` peste 30 zile).
   - `DELETE .../appointments/{id}` cere motiv obligatoriu (din bifele 1-tap), setează `deleted_at`/`deleted_by`/`delete_reason` (eliberând slotul din `uq_appt_slot`), scrie **`ȘTERS`** în istoric și, **dacă vizita ștearsă avea `parent_id`**, resetează `resolved_by_reinspection_id = NULL` pe părinte + scrie **`REPROGRAMAT`** pe părinte că vizita s-a redeschis!

---

## Etapa 7 — Testare Automată, Concurență & Race Conditions (~1.5h)

### Script de testare PHP CLI (`tests/run_tests.php` — zero dependențe externe)

1. **Test concurență reală (Race Condition):**
   - Lansare **10 cereri HTTP paralele** (`curl_multi_exec` în PHP sau procese paralele) pe exact același slot `(date, '09:00', 'Linia 1')`:
     - Exact **1** cerere trebuie să întoarcă `201 Created`;
     - Celelalte **9** trebuie să întoarcă `409 SLOT_TAKEN`;
     - `SELECT COUNT(*) FROM appointments WHERE appt_date=? AND appt_time='09:00' AND line='Linia 1' AND deleted_at IS NULL` === **`1`**.
2. **Test `STALE_VERSION`:** doi clienți citesc `version = 1`; primul salvează → `version = 2`; al doilea încearcă `PUT` cu `version = 1` → primește `409 STALE_VERSION`.
3. **Test Auth & Dual-PIN:** PIN greșit × 5 → `429`; `master-recovery` invalidează sesiunile vechi și schimbă PIN-ul uzual; `change-pin` funcționează și scrie `PIN_CHANGE` în audit.
4. **Test flux `Revenire` complet:** marcare `REVENIRE`, editare motiv, `REVOCAT`, reprogramare (sub și peste 30 zile), ștergere vizită `REVENIRE` → verificare că părintele are din nou `resolved_by_reinspection_id IS NULL`.
5. **Test blocare `.htaccess`:** request HTTP direct către `/config/env.php` și `/core/migrations/001_core.sql` trebuie să întoarcă `403`.

---

## Etapa 8 — Livrare Locală pe PC (`Laragon / XAMPP`) (~0.5h)

1. Import/creare DB locală `acw_itp` în MariaDB (din Laragon / HeidiSQL / phpMyAdmin).
2. Configurare `config/env.php`.
3. Rulare locală în rețeaua Wi-Fi (`httpd.exe` din XAMPP/Laragon pe portul `80` sau `php -S 0.0.0.0:8000 index.php`).
4. Expunere demo temporară cu `cloudflared.exe` dacă este nevoie de verificare de la distanță înainte de urcarea pe domeniu.

---

## Etapa 9 — Deploy în Producție pe Romarg Găzduire Web cPanel (`1,95 €/lună`) (~0.5h)

1. **Pregătire domeniu & SSL în cPanel:**
   - Creează subdomeniu dedicat (ex. `programari.domeniultau.ro`) sau domeniu principal cu **AutoSSL** activ (`HTTPS`).
   - Verifică în cPanel → **Select PHP Version** să fie selectat **PHP 8.2** sau **PHP 8.3** cu extensiile `pdo_mysql`, `json`, `mbstring` active.
2. **Creare bază MariaDB în cPanel:**
   - **MySQL® Database Wizard:** creează baza (ex. `usercp_itp`), un utilizator dedicate și acordă **ALL PRIVILEGES**.
3. **Upload & Configurare:**
   - Urcă fișierele în folderul subdomeniului (FTP / cPanel File Manager `Upload ZIP` + `Extract`).
   - Completează `config/env.php` cu credențialele DB create la pasul 2 și un `FIRST_START_TOKEN` aleatoriu temporar.
   - Verifică instant că `https://programari.domeniultau.ro/config/env.php` întoarce **`403 Forbidden`** (regulile `.htaccess` active).
4. **Inițializare (`First-Start`) & Siguranță:**
   - Accesează `https://programari.domeniultau.ro/` — aplicația rulează automat migrările SQL, cere `FIRST_START_TOKEN`, creează administratorul principal (`Laur` + PIN uzual + PIN Secret `8–12` cifre) și conturile inițiale (`Vlad`, `Gabi`).
   - Șterge `FIRST_START_TOKEN` din `config/env.php` (ruta `/api/setup/first-start` rămâne oricum blocată permanent cât timp există utilizatori în `users`).
5. **Backup & Mentenanță:**
   - **Backup automat:** inclus zilnic prin **Acronis** în panoul cPanel Romarg.
   - **Backup suplimentar:** Adminul poate descărca oricând din aplicație snapshot-ul de siguranță, iar `robots.txt` (`Disallow: /`) blochează indexarea aplicației în motoarele de căutare.
