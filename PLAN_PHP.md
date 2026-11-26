# Plan Aplicație Stație ITP — Variantă PHP 8 + MariaDB (Romarg Găzduire Web cPanel)

> **Notă:** Planul și ghidul inițial pentru Python / FastAPI / SQLite / VPS Docker rămân păstrate intacte în [PLAN.md](PLAN.md) și [IMPLEMENTARE.md](IMPLEMENTARE.md) pentru o eventuală migrare ulterioară pe VPS. Acest document definește arhitectura de producție **cea mai economică și 100% funcțională** pe găzduire web partajată (**Romarg Găzduire Web cPanel**, de la `1,95 €/lună`).

---

## 1. Obiectiv și Principii Tehnice

- **Cost minim de operare 24/7 fără administrare VPS:** rulează nativ pe găzduirea web Romarg (`cPanel`, `PHP 8.2+`, `MariaDB`, `LiteSpeed/Apache`, SSL gratuit `AutoSSL`, backup zilnic `Acronis` inclus), fără Docker, fără SSH obligatoriu și fără costuri suplimentare.
- **Zero dependențe grele / zero pas de build:**
  - **Backend:** **PHP 8.2+ pur** (`PDO_MYSQL`, autoloader PSR-4 propriu, fără Composer sau framework-uri voluminoase). Deploy prin simplu upload FTP / File Manager / Git cPanel.
  - **Frontend:** aceeași interfață aprobată în [dashboard.html](dashboard.html) (**Tabler Light** offline din `references/ui-libs/`, fundal stil WhatsApp, tastatură numerică PIN, carduri compacte de mobil, buton `🔍` pliabil, `robots.txt`), conectată la API prin `fetch()` pe rute 100% relative.
- **Portabilitate:** folosește `.env` (sau `config/env.php` protejat) și SQL standard MariaDB InnoDB, putând fi rulată atât local pe Windows (`Laragon` / `XAMPP` / `php -S`), cât și pe orice găzduire cPanel sau VPS.

---

## 2. Protecție Race Condition & Concurență (First-Write Wins)

Cerință critică: **2–5 utilizatori care salvează simultan de pe telefoane/PC-uri nu pot corupe baza de date și nu pot rezerva aceeași oră/linie.**

1. **Motor tranzacțional ACID (`InnoDB`):**
   - Toate scrierile rulează prin `$db->transaction(fn(\PDO $pdo) => ...)` (`BEGIN`, `COMMIT`, `ROLLBACK` automat pe orice excepție), cu `PDO::ATTR_EMULATE_PREPARES => false`.
2. **Negociere atomică pe slot (`UNIQUE KEY` cu soft-delete):**
   - Tabela `appointments` folosește coloana generată `active_slot_flag TINYINT GENERATED ALWAYS AS (IF(deleted_at IS NULL, 1, NULL)) STORED` + indexul **`UNIQUE KEY uq_appt_slot (appt_date, appt_time, line, active_slot_flag)`**.
   - Dacă doi utilizatori apasă simultan *Salvează* pe aceeași `(dată, oră, linie)`:
     - Primul execută `INSERT` → `COMMIT` → primește **`201 Created`**.
     - Al doilea primește eroarea de duplicat direct din MariaDB (`SQLSTATE[23000]` / driver code `1062`) → tranzacția face `ROLLBACK` automat → nimic nu se corupe și utilizatorul primește **`409 SLOT_TAKEN`**.
   - La ștergere logică (`deleted_at = UTC_TIMESTAMP`), `active_slot_flag` devine `NULL`, eliberând instant slotul pentru noi programări fără a pierde istoricul.
3. **Editare simultană (`SELECT ... FOR UPDATE` + `version` optimistic lock):**
   - Orice `PUT` / acțiune pe o programare existentă deschide tranzacția, citește rândul cu **`SELECT ... FROM appointments WHERE id = ? FOR UPDATE`** și verifică `version === expected_version`.
   - Primul coleg care salvează incrementează `version = version + 1`; al doilea primește **`409 STALE_VERSION`** fără a suprascrie orbește modificările colegului.
4. **Legătură părinte-revenire atomică:**
   - Reprogramarea unei reveniri setează `resolved_by_reinspection_id` pe vizita-părinte în aceeași tranzacție.
   - Ștergerea unei vizite `REVENIRE` eliberează automat `resolved_by_reinspection_id` pe vizita-părinte (în aceeași tranzacție) și scrie evenimentul pe ambele intrări în `Istoric Imutabil`, redeschizând butonul **`+ Reprogramează`**.

---

## 3. Arhitectura Modulară (Clean Architecture în PHP)

Respectând separarea `Controller (API) → Service → Repository → Database`:

```text
itp-programari/
├── index.php                     # Front controller unic + routare API / App Shell
├── .htaccess                     # Rescriere rute + BLOCARE strictă acces direct la core/modules/config/backups
├── robots.txt                    # Disallow: /
├── manifest.json                 # PWA light (Add to Home Screen pe telefon)
├── config/
│   └── env.example.php           # Credențiale DB MariaDB, timezone, proxy, retenție backup
├── core/
│   ├── Autoloader.php            # Autoloading PSR-4 fără Composer
│   ├── Config.php
│   ├── Database.php              # Conexiune PDO MariaDB + transaction() + lockForUpdate()
│   ├── Migrator.php              # Runner migrări .sql (core + modules/*) cu checksum SHA-256
│   ├── Request.php               # JSON body, IP real (proxy de încredere), CSRF check
│   ├── Response.php              # Răspunsuri JSON standardizate & CSV BOM UTF-8
│   ├── Security.php              # password_hash(BCRYPT), token-uri random_bytes, CSRF, rate-limit
│   ├── Auth.php                  # Middleware: requireUser(), requireAdmin(), requirePerm()
│   ├── AuditLogger.php           # Scriere în core_audit_log în tranzacția curentă
│   ├── ModuleLoader.php          # Scanare modules/*/module.json + activ/inactiv
│   ├── BackupService.php         # Export SQL/JSON descărcabil pentru MariaDB (Acronis zilnic în cPanel)
│   ├── migrations/
│   │   └── 001_core.sql          # Tabele Core + trigger-e append-only MariaDB
│   └── controllers/
│       ├── AuthController.php    # Login PIN, Master Recovery, Change PIN, Logout, Me, Setup
│       └── AdminController.php   # Utilizatori, Permisiuni, Module, Setări, Jurnal Securitate, Backup
├── modules/
│   └── programari/
│       ├── module.json
│       ├── migrations/
│       │   └── 001_init.sql      # appointments, appointment_history, reasons (InnoDB)
│       ├── Validators.php        # RO plate, RO phone, slot, zi lucrătoare, dată trecută
│       ├── AppointmentRepo.php   # Interogări SQL + FOR UPDATE (fără update/delete pe history)
│       ├── HistoryService.php    # Registry extensibil evenimente (CREAT..ȘTERS) cu Dată+Oră+Actor
│       ├── AppointmentService.php# Logică tranzacțională completă + diff-uri + reveniri
│       └── Controller.php        # Endpoint-uri /api/m/programari/...
├── assets/                       # Static public: css/app.css, js/api.js, js/app.js, vendor/tabler/, img/aw-logo.png
└── backups/                      # Exporturi manuale locale (blocate din web prin .htaccess)
```

---

## 4. Securitate Specifică Shared Hosting (`public_html` / cPanel)

1. **Blocare absolută directoare interne în `.htaccess`:**
   - Orice cerere HTTP directă către `/config/*`, `/core/*`, `/modules/*`, `/backups/*`, `*.sql`, `*.md`, `.env`, `.git` este respinsă cu `403 Forbidden`.
   - `index.php` și `/assets/*` sunt singurele puncte accesibile din browser.
2. **Autentificare cu PIN & Dual-PIN Admin:**
   - Selecție utilizator + PIN numeric `4–6` cifre (stocat cu `password_hash($pin, PASSWORD_BCRYPT)`).
   - **Dual-PIN pentru Admin principal (`Laur`):** `pin_hash` uzual + `master_pin_hash` (`8–12` cifre) pentru preluarea controlului (`ADMIN_MASTER_RECOVERY`: incrementează `session_version`, invalidează sesiunile vechi, înlocuiește PIN-ul uzual).
   - Orice utilizator conectat își poate schimba propriul PIN uzual (`POST /api/auth/change-pin`).
   - Rate-limit persistent în tabela `login_attempts` (5 încercări/5 min pe PIN uzual → blocare 5 min; 3 încercări/15 min pe PIN Secret → blocare 30 min; `429 Too Many Requests`).
3. **Sesiuni în MariaDB + CSRF:**
   - Cookie `itp_session` (`HttpOnly`, `SameSite=Lax`, `Secure` automat pe HTTPS), salvat în tabela `sessions` doar ca `SHA-256`, cu verificare `session_version` și `is_active` la fiecare cerere API.
   - Header `X-CSRF-Token` obligatoriu pe `POST`, `PUT`, `PATCH`, `DELETE`.
4. **`First-Start` securizat la instalarea pe cPanel:**
   - Ruta de creare a primului Admin (`/api/setup/first-start`) funcționează **exclusiv** când tabela `users` este **complet goală** (`COUNT(*) = 0`) și poate fi protejată opțional printr-un `INSTALL_SETUP_KEY` din `config/env.php` (eliminată automat după crearea Adminului).

---

## 5. Trasabilitate Completă & Extensibilitate (`Istoric Imutabil` + `Jurnal Securitate`)

- **Toate acțiunile curente în `appointment_history` (`action_date`, `action_time`, `actor_name` + tip):**
  - `CREAT` *(standard, din `Expirări` / `Clienți`, duplicat confirmat, Duminică Admin, revenire din părinte, ITP complet peste termen)*
  - `MODIFICAT` *(diff câmp cu câmp + avertizări confirmate)*
  - `REVENIRE` *(motive 1-tap + observații + termen 30 zile)*
  - `REVOCAT` *(revenire anulată → `Programat`, separat de `MODIFICAT`)*
  - `REPROGRAMAT` *(legătură pe vizita-părinte + consemnare redeschidere la ștergere revenire)*
  - `ȘTERS` *(motiv 1-tap obligatoriu + slot eliberat)*
- **Coloana `event_type VARCHAR(32)`** (fără `ENUM` rigid în SQL) + catalog central în PHP → permite adăugarea ulterioară de acțiuni Etapa 2 (`SOSIT`, `ADMIS`, `INCASARE`) fără migrări riscante pe istoricul existent.
- **`core_audit_log` separat (discret pentru Admin):** înregistrează `LOGIN_OK`, `LOGIN_FAIL`, `LOGOUT`, `PIN_CHANGE`, `ADMIN_MASTER_RECOVERY`, `USER_CREATE/UPDATE/BLOCK/UNBLOCK`, `REASON_*`, `MODULE_TOGGLE`, `SETTINGS_UPDATE`, `EXPORT`, `BACKUP`, fiecare cu **dată, oră (`Europe/Bucharest`), utilizator și IP**.

---

## 6. Reguli Operaționale MVP (`Faza 1`) & Etapa 2

- **Program stație:** Luni – Sâmbătă (`08:00–17:30`), 3 linii × 30 min. Duminica (`închis`) este blocată pentru utilizatori și permisă doar ca excepție explicit confirmată de Admin.
- **Editare colaborativă:** utilizatorii cu drept `Editare` (`Vlad`, `Gabi`) pot modifica orice programare; ștergerea rămâne permisiune separată.
- **Revenire 30 zile:** verificare termen și la `+ Reprogramează`, și la `Editează` pe o vizită `REVENIRE`.
- **Etapa 2 (peste aceeași Bază PHP):** `clienti-vehicule`, `inspectii` (calcul automat scadență `6 / 12 / 24 luni` + poze), `expirari-itp`, `tarife-incasari` (doar Admin).
