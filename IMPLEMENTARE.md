# Ghid Detaliat de Implementare — Aplicație Locală Stație ITP (`ACW - Fălticeni`)

## 0. Introducere și Strategie de Livrare

- **Obiectiv:** Construirea aplicației reale în **2 etape livrate în ~2 zile** — **Faza 0 (`Core` / Baza modulară)** + **Faza 1 (Modulul `programari`)**, gata de utilizare zilnică pe PC și telefon în rețeaua locală (`localhost` + LAN/Wi-Fi) și expusă securizat demo prin `cloudflared.exe`.
- **Referință UI/UX:** [dashboard.html](dashboard.html) rămâne macheta aprobată (Light Theme Tabler, fundal stil WhatsApp `#efeae2` + pattern SVG discret, logo ACW, login cu carduri + tastatură PIN + PIN Secret Admin, carduri mobile compacte, modal confirmare `#modalConfirm`, bife rapide 1-tap pentru motive). **Nu se șterge.**
- **Stack confirmat:** **Python 3.14 + FastAPI + Uvicorn + SQLite WAL (`sqlite3`)**, frontend curat **HTML5 + CSS3 + JavaScript + Tabler local** (`references/ui-libs/`), fără Node.js și fără pași de build.

### Calendar Estimat (2 Zile)

| Ziua | Etape acoperite | Durată estimată | Livrabil principal |
| :--- | :--- | :---: | :--- |
| **Ziua 1** | Etapele **0 – 5** (`Faza 0 – Core`) | **~6.25 – 7 ore** | Server FastAPI, SQLite WAL + migrări, Auth Dual-PIN, Audit, Backup, Module Loader, App Shell UI |
| **Ziua 2** | Etapele **6 – 8** (`Faza 1 – programari` + Teste + Livrare) | **~6 – 7 ore** | Modul `programari` complet, `Istoric Imutabil`, `Motive`, teste `pytest`, testare LAN/Cloudflare, `start.bat` |

### Flux Git & Versionare
- **Un commit per etapă**, cu mesaje convenționale (`feat(core): ...`, `feat(programari): ...`, `test: ...`, `docs: ...`), urmat de `git push origin main`.
- **Tag-uri de referință:** `v0.1.0` la finalizarea **Fazei 0 (Etapa 5)** și `v1.0.0` la finalizarea **Fazei 1 (Etapa 8)**.

---

## Etapa 0 — Pregătire Mediu și Structură (~0.5h)

### Structura de directoare țintă
```text
d:\ITP programari\
├── core/
│   ├── __init__.py
│   ├── app.py                   # Aplicația FastAPI, static files, middleware module active
│   ├── config.py                # Încărcare variabile .env și constante de sistem
│   ├── db.py                    # Conexiune SQLite WAL, transaction(), runner migrări
│   ├── security.py              # hash_pin/verify_pin (bcrypt), token-uri sesiune/CSRF
│   ├── audit.py                 # Helper central core_audit_log (append-only)
│   ├── deps.py                  # Dependințe FastAPI: require_user, require_admin, require_perm
│   ├── module_loader.py         # Descoperire modules/*/module.json, migrări, mount routere
│   ├── backup.py                # Backup consistent la cald + PRAGMA integrity_check + rotație
│   ├── reset_admin_pin.py       # Script CLI de urgență fizică pe PC-ul serverului
│   ├── migrations/
│   │   └── 001_core.sql         # Schema SQL Core + trigger-e imutabilitate + seed setări
│   ├── routes/
│   │   ├── __init__.py
│   │   ├── auth.py              # Login PIN, Master Recovery, Logout, Me, Change PIN
│   │   ├── admin.py             # Utilizatori, Permisiuni, Module, Setări, Audit, Backup
│   │   └── setup.py             # First-Start exclusiv local (127.0.0.1 fără proxy)
│   └── static/
│       ├── index.html           # App Shell (derivat din dashboard.html)
│       ├── css/app.css
│       ├── js/api.js            # Wrapper fetch (cookie + X-CSRF-Token + erori toast)
│       ├── js/app.js
│       ├── vendor/tabler/       # Copiat local din references/ui-libs/tabler/
│       └── img/aw-logo.png      # Copiat din references/ui-libs/aw-logo.png
├── modules/
│   └── programari/
│       ├── __init__.py
│       ├── module.json          # Manifest modul (permisiuni, meniu, ordine)
│       ├── migrations/
│       │   └── 001_init.sql     # Tabele appointments, appointment_history, reasons
│       ├── router.py            # Endpoint-uri /api/m/programari/...
│       ├── service.py           # Logică tranzacțională și dif-uri pentru Istoric Imutabil
│       └── validators.py        # Validări dată, slot, linie, număr auto RO, telefon RO
├── tests/
├── data/                        # Baza itp.db (ignorată în Git)
├── backups/                     # Copii de siguranță (ignorate în Git)
├── .env.example
├── requirements.txt
├── requirements-dev.txt
└── start.bat
```

### Dependențe Python
**`requirements.txt`:**
```text
fastapi
uvicorn[standard]
bcrypt
tzdata
```
> **Important (Windows):** pachetul `tzdata` este **obligatoriu pe Windows** pentru ca `zoneinfo.ZoneInfo("Europe/Bucharest")` să funcționeze corect la schimbările de oră (EET/EEST).

**`requirements-dev.txt`:**
```text
pytest
httpx
```

### Configurare `.env.example`
```env
ITP_HOST=0.0.0.0
ITP_PORT=8000
ITP_DB_PATH=data/itp.db
ITP_TRUSTED_PROXIES=127.0.0.1
ITP_COOKIE_SECURE=auto
ITP_SESSION_HOURS=12
ITP_SESSION_IDLE_HOURS=4
ITP_BACKUP_KEEP=30
ITP_TZ=Europe/Bucharest
```

### Comenzi de instalare
```powershell
python -m venv .venv
.venv\Scripts\python -m pip install -r requirements.txt -r requirements-dev.txt
```

- **Risc și soluție de rezervă:** dacă pe un alt PC Python 3.14 nu găsește wheel precompilat pentru `bcrypt`, `core/security.py` trece transparent pe `hashlib.scrypt` din biblioteca standard Python, păstrând aceeași interfață `hash_pin()` / `verify_pin()`.
- **Gata când:** comanda `.venv\Scripts\python -c "import fastapi, bcrypt, zoneinfo; zoneinfo.ZoneInfo('Europe/Bucharest')"` rulează fără eroare (exit code `0`).

---

## Etapa 1 — Bază de Date Core și Migrări (~1h)

### `core/db.py`
- Funcția `connect()` deschide SQLite cu `row_factory = sqlite3.Row` și aplică la fiecare conexiune:
  ```sql
  PRAGMA journal_mode = WAL;
  PRAGMA foreign_keys = ON;
  PRAGMA busy_timeout = 5000;
  PRAGMA synchronous = NORMAL;
  ```
- Context manager `transaction(conn)` care pornește explicit **`BEGIN IMMEDIATE`** (evită blocajele la concurență între 2 telefoane), execută `COMMIT` la ieșire și `ROLLBACK` la orice excepție.
- **Runner de migrări:**
  1. Creează tabelul `_migrations(module_id TEXT NOT NULL, filename TEXT NOT NULL, checksum TEXT NOT NULL, applied_at TEXT NOT NULL, PRIMARY KEY(module_id, filename))`.
  2. Scanează fișierele `.sql` în ordine alfabetică, separat pentru `core` și pentru fiecare modul din `modules/`.
  3. Aplică fiecare fișier nou într-o tranzacție și îi salvează hash-ul SHA-256 în `_migrations`.
  4. Dacă un fișier deja aplicat are alt checksum decât cel din `_migrations`, aplicația **oprește pornirea cu eroare explicită** (protejează integritatea schemei).

### Schema SQL completă — `core/migrations/001_core.sql`
```sql
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
    display_name TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('admin', 'user')),
    pin_hash TEXT NOT NULL,
    master_pin_hash TEXT NULL,
    is_primary_admin INTEGER NOT NULL DEFAULT 0 CHECK (is_primary_admin IN (0, 1)),
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    must_change_pin INTEGER NOT NULL DEFAULT 0 CHECK (must_change_pin IN (0, 1)),
    session_version INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 100,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_users_one_primary
    ON users(is_primary_admin)
    WHERE is_primary_admin = 1;

CREATE TABLE IF NOT EXISTS user_permissions (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    permission TEXT NOT NULL,
    PRIMARY KEY (user_id, permission)
);

CREATE TABLE IF NOT EXISTS sessions (
    token_hash TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    session_version INTEGER NOT NULL,
    csrf_token TEXT NOT NULL,
    ip_address TEXT NOT NULL DEFAULT '',
    user_agent TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    expires_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id);

CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    ip_address TEXT NOT NULL,
    kind TEXT NOT NULL CHECK (kind IN ('pin', 'master')),
    success INTEGER NOT NULL CHECK (success IN (0, 1)),
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_login_attempts_user_time ON login_attempts(user_id, kind, created_at);
CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_time ON login_attempts(ip_address, kind, created_at);

CREATE TABLE IF NOT EXISTS modules_state (
    module_id TEXT PRIMARY KEY,
    enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
    version TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value_json TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    updated_by TEXT NOT NULL DEFAULT 'system'
);

CREATE TABLE IF NOT EXISTS core_audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    user_id INTEGER NULL,
    username TEXT NOT NULL,
    module_id TEXT NOT NULL DEFAULT 'core',
    action TEXT NOT NULL,
    entity_type TEXT NULL,
    entity_id TEXT NULL,
    before_json TEXT NULL,
    after_json TEXT NULL,
    ip_address TEXT NOT NULL DEFAULT '',
    user_agent TEXT NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_core_audit_time ON core_audit_log(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_core_audit_action ON core_audit_log(action);

CREATE TRIGGER IF NOT EXISTS trg_core_audit_no_update
BEFORE UPDATE ON core_audit_log
BEGIN
    SELECT RAISE(ABORT, 'core_audit_log este append-only');
END;

CREATE TRIGGER IF NOT EXISTS trg_core_audit_no_delete
BEFORE DELETE ON core_audit_log
BEGIN
    SELECT RAISE(ABORT, 'core_audit_log este append-only');
END;
```

### Setări implicite (Seed în `settings`)
- `station_name`: `"ACW - Fălticeni"`
- `working_days`: `[1, 2, 3, 4, 5, 6]` *(Luni–Sâmbătă, coduri ISO)*
- `day_start`: `"08:00"`
- `last_slot`: `"17:30"`
- `slot_minutes`: `30`
- `lines`: `["Linia 1", "Linia 2", "Linia 3"]`
- `history_default_days`: `60`
- `backup_keep`: `30`

- **Gata când:** un one-liner Python confirmă că `PRAGMA journal_mode` returnează `wal`, toate cele 7 tabele Core există și `UPDATE core_audit_log SET action='X'` eșuează cu eroarea din trigger (`core_audit_log este append-only`).

---

## Etapa 2 — Securitate și Autentificare cu PIN (~1.5h)

### `core/security.py`
- `hash_pin(pin: str) -> str` și `verify_pin(pin: str, hashed: str) -> bool` (folosind `bcrypt`, cu fallback `hashlib.scrypt`).
- `new_token() -> str` (`secrets.token_urlsafe(32)`).
- `sha256(token: str) -> str` — token-ul de sesiune nu se salvează niciodată în clar în DB, ci doar ca hash SHA-256.

### Endpoint-uri Auth (`core/routes/auth.py` + `core/routes/setup.py`)

| Metodă | Rută | Body | Răspuns / Rol |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/auth/users` | — | Cardurile de login: `id`, `display_name`, `role`, `is_active` *(fără PIN-uri)* |
| `POST` | `/api/auth/login` | `{user_id, pin}` | Setează cookie `itp_session`, returnează profil + `csrf_token` |
| `POST` | `/api/auth/master-recovery` | `{master_pin, new_pin}` | Preia controlul Adminului principal cu PIN Secret |
| `POST` | `/api/auth/logout` | — | Șterge sesiunea curentă + cookie-ul |
| `GET` | `/api/auth/me` | — | `{user, permissions, csrf_token, must_change_pin}` |
| `POST` | `/api/auth/change-pin` | `{current_pin, new_pin}` | Schimbare PIN uzual propriu |
| `POST` | `/api/auth/change-master-pin` | `{current_master_pin, new_master_pin}` | Schimbare PIN Secret *(cere obligatoriu PIN-ul Secret vechi)* |
| `GET/POST` | `/api/setup/first-start` | `{admin_name, pin, master_pin, create_defaults}` | Activ doar când tabela `users` e goală și cererea e strict locală |

### Sesiuni, Cookie, CSRF și IP real
- **Cookie `itp_session`:** `HttpOnly=True`, `SameSite="strict"`. Flag-ul `Secure` se activează automat (`ITP_COOKIE_SECURE=auto`) atunci când cererea vine pe HTTPS sau conține `X-Forwarded-Proto: https` de la un proxy de încredere.
- **Expirare sesiune:** maxim **12 ore** absolut (`expires_at`) sau **4 ore** de inactivitate (`last_seen_at`).
- **Invalidare instantanee:** la fiecare cerere, middleware-ul verifică `session.session_version == user.session_version` și `user.is_active == 1`. Dacă diferă, sesiunea este respinsă imediat cu `401`.
- **CSRF:** header-ul `X-CSRF-Token` este obligatoriu pe toate cererile `POST`, `PUT`, `PATCH`, `DELETE` (singurele excepții: `login`, `master-recovery` și `first-start`).
- **IP real:** headerele `CF-Connecting-IP` și `X-Forwarded-For` se citesc **doar** dacă adresa peer-ului TCP se află în `ITP_TRUSTED_PROXIES` (implicit `127.0.0.1` pentru `cloudflared.exe`), prevenind falsificarea IP-ului.

### Rate-limit persistent (în tabela `login_attempts`)
- **PIN uzual (`kind='pin'`):** **5 greșeli** per `user_id` în ultimele 5 minute → blocare **5 minute**; **20 greșeli** per `ip_address` în ultimele 5 minute → blocare **5 minute**.
- **PIN Secret (`kind='master'`):** **3 greșeli** în ultimele 15 minute → blocare **30 minute**.
- Răspuns HTTP **`429 Too Many Requests`** cu mesaj clar în română (ex. *„Prea multe încercări greșite. Reîncearcă peste X minute.”*). Rezistă la repornirea serverului deoarece stă în SQLite.

### Flux Dual-PIN Admin (`master-recovery`) și Urgență
1. La `POST /api/auth/master-recovery` cu `master_pin` valid (8–12 cifre) și `new_pin` valid (4–6 cifre), într-o **singură tranzacție SQLite**:
   - `users.session_version += 1` *(deloghează instant toate celelalte telefoane/PC-uri ale Adminului)*
   - `users.pin_hash = hash_pin(new_pin)`
   - `users.must_change_pin = 0`
   - șterge toate rândurile vechi din `sessions` ale Adminului principal
   - creează o sesiune nouă pentru dispozitivul curent
   - scrie în `core_audit_log` acțiunea **`ADMIN_MASTER_RECOVERY`**
2. **Protecție PIN Secret:** nu există niciun endpoint prin care PIN-ul uzual să poată citi, șterge sau modifica `master_pin_hash`.
3. **First-Start (`routes/setup.py`):** activ **exclusiv** când tabela `users` este goală și cererea vine direct de pe `127.0.0.1` **fără** headere de proxy (`CF-Connecting-IP` / `X-Forwarded-For` blocate → `403`). Creează administratorul principal (`Laur`, PIN 4–6 cifre, PIN Secret 8–12 cifre) și, opțional, conturile inițiale `Vlad` și `Gabi`.
4. **Script CLI de rezervă (`python -m core.reset_admin_pin`):** rulează exclusiv din consola Windows de pe PC-ul serverului; resetează PIN-ul uzual și PIN-ul Secret ale adminului principal, incrementează `session_version`, șterge sesiunile și înregistrează în `core_audit_log`.

### Dependințe FastAPI (`core/deps.py`)
- `require_user`: validează cookie-ul, `session_version`, expirarea și `is_active`.
- `require_admin`: cere `user.role == 'admin'`.
- `require_perm(perm: str)`: trece automat pentru `admin`, altfel verifică prezența în `user_permissions` (altfel `403`).

- **Gata când:** testele de login PIN, blocare `429`, `master-recovery` (invalidare sesiune veche) și respingere `First-Start` prin header proxy trec integral.

---

## Etapa 3 — Audit, Panou Admin și Backup (~1h)

### `core/audit.py`
- Funcția centrală:
  ```python
  def log_event(
      conn,
      user,
      action: str,
      module_id: str = "core",
      entity_type: str | None = None,
      entity_id: str | None = None,
      before: dict | None = None,
      after: dict | None = None,
      request=None,
  ) -> None: ...
  ```
- Se apelează întotdeauna **în aceeași tranzacție** cu operația propriu-zisă.
- **Catalog acțiuni Core:** `LOGIN_OK`, `LOGIN_FAIL`, `LOGOUT`, `ADMIN_MASTER_RECOVERY`, `PIN_CHANGE`, `USER_CREATE`, `USER_UPDATE`, `PERMISSION_CHANGE`, `USER_BLOCK`, `USER_UNBLOCK`, `REASON_CHANGE`, `MODULE_TOGGLE`, `SETTINGS_UPDATE`, `EXPORT`, `BACKUP`.

### Rute Admin (`core/routes/admin.py` — protejate prin `require_admin`)
- **Utilizatori & Permisiuni:** CRUD utilizatori (PIN numeric `4–6` cifre; adminul principal `is_primary_admin=1` nu poate fi blocat și nu i se poate schimba rolul) + setare permisiuni granulare per modul.
- **Module:** `GET /api/admin/modules` și `POST /api/admin/modules/{module_id}/toggle` (modulul `programari` este blocat activ ca modul principal).
- **Setări stație:** `GET/PUT /api/admin/settings` (program orar, zile lucrătoare, linii, durată slot, retenție).
- **Jurnal securitate / Admin:** `GET /api/admin/audit` (filtre după utilizator, acțiune, interval de date + paginare) și `GET /api/admin/audit.csv` (cu **BOM UTF-8 `\ufeff`** pentru diacritice corecte în Excel).

### `core/backup.py`
- Execută `sqlite3.Connection.backup()` la cald către `backups/itp_YYYYMMDD_HHMMSS.db`.
- Rulează imediat `PRAGMA integrity_check` pe fișierul copiat (dacă nu întoarce `"ok"`, șterge copia coruptă și raportează eroare).
- **Rotație automată:** păstrează ultimele `backup_keep` (implicit `30`) copii.
- **Programare automată:** backup la pornirea aplicației + task `asyncio` la fiecare 24 de ore.
- **Endpoint-uri:** `GET /api/admin/backups` (listă), `POST /api/admin/backups` (creare manuală), `GET /api/admin/backups/{filename}` (descărcare sigură, validată contra path traversal).

- **Gata când:** crearea unui backup generează un fișier valid verificat cu `PRAGMA integrity_check == 'ok'`, iar CSV-ul de audit se deschide cu diacritice corecte în Excel.

---

## Etapa 4 — Motorul de Module (`core/module_loader.py`) (~0.75h)

### Format `module.json`
```json
{
  "id": "programari",
  "name": "Programări",
  "version": "1.0.0",
  "order": 10,
  "icon": "calendar",
  "dependsOn": [],
  "permissions": [
    "programari.view",
    "programari.edit",
    "programari.revenire",
    "programari.delete"
  ],
  "menu": [
    { "id": "azi", "label": "Programări", "permission": "programari.view" },
    { "id": "istoric", "label": "Istoric Imutabil", "permission": "programari.view" }
  ]
}
```

### Flux la pornire și la cerere
1. Scanează `modules/*/module.json`, sortează după `order` și verifică dependențele `dependsOn`.
2. Sincronizează în `modules_state` (implicit `enabled = 1` la prima descoperire).
3. Rulează migrările din `modules/<id>/migrations/*.sql` prin runner-ul din Etapa 1.
4. Montează router-ul modulului la `/api/m/<id>` și fișierele statice opționale la `/m/<id>/`.
5. **Middleware modul activ:** orice cerere către `/api/m/<id>/...` verifică dacă modulul are `enabled = 1` în `modules_state`; dacă este dezactivat, răspunde **`404`**.
6. **`GET /api/core/menu`:** returnează doar modulele active și intrările de meniu la care utilizatorul curent are permisiunea cerută.

- **Gata când:** dezactivarea unui modul opțional din `/api/admin/modules/.../toggle` îl scoate imediat din `/api/core/menu` și returnează `404` pe rutele lui API.

---

## Etapa 5 — App Shell Frontend (`core/static/`) (~1.5h)

### Structură și Design
- Pornește direct din [dashboard.html](dashboard.html): același **Tabler Light local**, același **fundal stil WhatsApp (`#efeae2` + pattern SVG)**, sigla ACW (`img/aw-logo.png`), ecranul de Login cu carduri utilizator + tastatură numerică PIN + butonul `PIN Secret Admin (Preia controlul)`, meniul PC și bara compactă de jos pe mobil.
- CSS-ul se extrage în `core/static/css/app.css`.
- **`core/static/js/api.js`:** wrapper unic peste `fetch(..., { credentials: "same-origin" })` care injectează automat `X-CSRF-Token`, parsează erorile `{ detail: { code, message } }` afișându-le ca toast clar și redirecționează automat la ecranul de Login la orice răspuns `401`.

### Mapare funcții din `dashboard.html` → API Real

| Funcționalitate / Funcție din `dashboard.html` | Destinație în aplicația reală |
| :--- | :--- |
| `renderPinUserGrid` | `GET /api/auth/users` |
| `completeLogin` / `onPinInputChanged` | `POST /api/auth/login` |
| `submitMasterRecovery` | `POST /api/auth/master-recovery` |
| `requestLogout` | `POST /api/auth/logout` |
| `formUser` / `toggleUserActive` / `renderUtilizatori` | `GET/POST/PUT /api/admin/users...` |
| `toggleModule` / `renderModule` | `GET/POST /api/admin/modules...` |
| Backup manual / listă / descărcare | `GET/POST /api/admin/backups...` |
| `resetDemoData` și persistența `localStorage` | **Eliminate** în producție (datele trăiesc în SQLite) |
| Funcțiile de programări, istoric, motive | Conectate în **Etapa 6** (`/api/m/programari/...`) |

- **Notă Faza 1:** tab-urile de previzualizare din Etapa 2 (`Mașini` și `Expirări`) sunt ascunse până la adăugarea modulelor lor dedicate după lansarea Fazei 1.

- **Gata când:** poți face login real cu PIN pe PC și telefon, schimba parola/PIN-ul, testa `Preia controlul` cu PIN Secret și naviga prin App Shell fără erori în consolă.

---

## Etapa 6 — Modulul `programari` (Faza 1 MVP) (~4h)

### Schema SQL completă — `modules/programari/migrations/001_init.sql`
```sql
CREATE TABLE IF NOT EXISTS appointments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date TEXT NOT NULL,                  -- YYYY-MM-DD
    time TEXT NOT NULL,                  -- HH:MM
    line TEXT NOT NULL,                  -- ex. Linia 1
    plate TEXT NOT NULL,                 -- ex. B 458 ITP
    plate_key TEXT NOT NULL,             -- ex. B458ITP
    phone TEXT NOT NULL,
    client TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'Programat' CHECK (status IN ('Programat', 'Revenire')),
    reject_reasons_json TEXT NOT NULL DEFAULT '[]',
    reject_note TEXT NOT NULL DEFAULT '',
    visit_type TEXT NOT NULL DEFAULT 'NORMAL' CHECK (visit_type IN ('NORMAL', 'REVENIRE')),
    parent_id INTEGER NULL REFERENCES appointments(id),
    reinspection_deadline TEXT NULL,     -- YYYY-MM-DD (+30 zile)
    version INTEGER NOT NULL DEFAULT 1,
    created_by INTEGER NOT NULL REFERENCES users(id),
    created_at TEXT NOT NULL,
    updated_by INTEGER NULL REFERENCES users(id),
    updated_at TEXT NOT NULL,
    deleted_at TEXT NULL,
    deleted_by INTEGER NULL REFERENCES users(id),
    delete_reason TEXT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_appt_slot
    ON appointments(date, time, line)
    WHERE deleted_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_appt_date ON appointments(date) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_appt_plate_key ON appointments(plate_key);

CREATE TABLE IF NOT EXISTS appointment_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    appointment_id INTEGER NOT NULL REFERENCES appointments(id),
    event_type TEXT NOT NULL CHECK (event_type IN ('CREAT', 'MODIFICAT', 'REVENIRE', 'REVOCAT', 'REPROGRAMAT', 'STERS')),
    actor_user_id INTEGER NULL,
    actor_name TEXT NOT NULL,
    summary TEXT NOT NULL,
    before_json TEXT NULL,
    after_json TEXT NULL,
    reason TEXT NULL,
    ip_address TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_appt_hist_appt ON appointment_history(appointment_id, id);
CREATE INDEX IF NOT EXISTS idx_appt_hist_time ON appointment_history(created_at DESC);

CREATE TRIGGER IF NOT EXISTS trg_appt_hist_no_update
BEFORE UPDATE ON appointment_history
BEGIN
    SELECT RAISE(ABORT, 'appointment_history este append-only');
END;

CREATE TRIGGER IF NOT EXISTS trg_appt_hist_no_delete
BEFORE DELETE ON appointment_history
BEGIN
    SELECT RAISE(ABORT, 'appointment_history este append-only');
END;

CREATE TABLE IF NOT EXISTS reasons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL CHECK (kind IN ('revenire', 'stergere')),
    label TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 100,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    UNIQUE (kind, label)
);

-- Seed motive Revenire (30 zile)
INSERT OR IGNORE INTO reasons (kind, label, sort_order) VALUES
    ('revenire', 'Frâne', 10),
    ('revenire', 'Direcție / Suspensie', 20),
    ('revenire', 'Lumini / Semnalizare', 30),
    ('revenire', 'Noxe / Emisii', 40),
    ('revenire', 'Anvelope / Jante', 50),
    ('revenire', 'Caroserie / Parbriz', 60),
    ('revenire', 'Scurgeri lichide', 70),
    ('revenire', 'Acte / Dotări', 80);

-- Seed motive Ștergere / Anulare
INSERT OR IGNORE INTO reasons (kind, label, sort_order) VALUES
    ('stergere', 'Anulat de client', 10),
    ('stergere', 'Neprezentare (No-show)', 20),
    ('stergere', 'Programare dublată / Greșeală', 30),
    ('stergere', 'Alt motiv', 40);
```

> **Notă DB vs. UI:** în SQLite `event_type` folosește codul ASCII `'STERS'` pentru consistență în interogări, iar interfața îl afișează utilizatorului cu diacritice (`ȘTERS`).

### Permisiuni declarate în `module.json`
- `programari.view` *(orice utilizator activ)*
- `programari.edit` *(corespunde `canEdit` — permite creare/editare pentru **orice programare** din agendă)*
- `programari.revenire` *(permite marcare Revenire, revocare și reprogramare 30 zile)*
- `programari.delete` *(permite ștergere soft-delete cu motiv)*
- *Administratorul le are automat pe toate.*

### Reguli de Business și Coduri de Eroare (`validators.py` + `service.py`)

| Regulă | Verificare pe server | Cod eroare HTTP & `code` |
| :--- | :--- | :--- |
| **Zile lucrătoare** | Doar Luni–Sâmbătă (`working_days` = `1..6`) | `422 INVALID_DAY` |
| **Slot orar** | Grila `08:00–17:30` din `30` în `30` min | `422 INVALID_SLOT` |
| **Linie ITP** | Trebuie să existe în `settings.lines` (`Linia 1..3`) | `422 INVALID_LINE` |
| **Zile trecute** | Non-admin nu poate crea/modifica în trecut | `403 PAST_DATE_FORBIDDEN` |
| **Nr. înmatriculare** | Minim 4 caractere alfanumerice; normalizare `plate` (`B 458 ITP`) + `plate_key` (`B458ITP`) | `422 INVALID_PLATE` |
| **Telefon RO** | 10 cifre `07xx`/`02xx`/`03xx` sau `+40...` | `422 INVALID_PHONE` |
| **Anti-suprapunere** | Prinsă din `sqlite3.IntegrityError` pe `idx_appt_slot` (`WHERE deleted_at IS NULL`) | `409 SLOT_TAKEN` |
| **Duplicat nr. auto / zi** | Același `plate_key` în aceeași `date` (la reprogramare se exclude `parent_id`); trece doar cu `confirm_duplicate: true` | `409 DUPLICATE_PLATE` |
| **Concurență optimistă** | `PUT` verifică `version` curent; dacă alt coleg a salvat între timp | `409 STALE_VERSION` |
| **Revenire 30 zile** | Cel puțin un motiv bifat sau o notă; calculează `reinspection_deadline = date + 30 zile` | `422 EMPTY_REASON` |
| **Reprogramare** | Creează vizită nouă `visit_type='REVENIRE'` cu `parent_id`. Peste `reinspection_deadline` cere `confirm_over_deadline: true` și devine `visit_type='NORMAL'` (ITP complet) | `409 OVER_DEADLINE` |
| **Revocare** | `Revenire` → `Programat`, golește motivele, păstrează trasabilitatea în istoric | — |
| **Ștergere (soft-delete)** | Motiv obligatoriu; setează `deleted_at`/`deleted_by`/`delete_reason` și eliberează instant slotul | `422 EMPTY_REASON` |

### Scriere Atomică în `Istoric Imutabil`
Fiecare operație de scriere din `service.py` rulează în `with transaction(conn):` (`BEGIN IMMEDIATE`):
1. Validează regulile și permisiunile.
2. Execută modificarea pe `appointments`.
3. Calculează diferențele lizibile pe server (ex. `Oră: 08:00 → 09:00; Linie: Linia 1 → Linia 3`) și inserează rândul corespunzător în `appointment_history` cu `before_json`, `after_json`, `actor_user_id`, `actor_name` (snapshot text) și `ip_address`.
4. La `reprogram`, inserează simultan 2 evenimente în aceeași tranzacție: `REPROGRAMAT` pe vizita-părinte și `CREAT` pe noua vizită.

### Endpoint-uri API (`/api/m/programari/...`)

| Metodă | Rută | Descriere |
| :--- | :--- | :--- |
| `GET` | `/day?date=&line=` | Lista programărilor zilei + statistici libere per linie |
| `GET` | `/search?q=&all_days=1` | Căutare după nr. înmatriculare, telefon sau client |
| `GET` | `/slots?date=&exclude_id=` | Sloturile disponibile/ocupate per linie pentru modal |
| `GET` | `/lookup?plate=` | Autocomplete instant client + telefon din vizite anterioare |
| `GET` | `/duplicates?date=&plate=&exclude_id=` | Verificare live duplicat nr. auto în aceeași zi |
| `POST` | `/appointments` | Creare programare (`CREAT`) |
| `PUT` | `/appointments/{id}` | Editare programare cu `version` (`MODIFICAT`) |
| `POST` | `/appointments/{id}/revenire` | Marcare / editare motiv revenire 30 zile (`REVENIRE`) |
| `POST` | `/appointments/{id}/revoke` | Revocare `Revenire` → `Programat` (`REVOCAT`) |
| `POST` | `/appointments/{id}/reprogram` | Reprogramare revenire sau ITP complet (`REPROGRAMAT` + `CREAT`) |
| `DELETE` | `/appointments/{id}` | Soft-delete cu motiv în body (`STERS`) |
| `GET` | `/history?from=&to=&user=&filter=&q=` | Istoric Imutabil (implicit ultimele 60 zile; cu `q` pe nr. auto caută în tot istoricul) |
| `GET` | `/history.csv` | Export CSV cu BOM UTF-8 pe filtrul activ |
| `GET/POST/PUT/DELETE` | `/reasons` | Gestiune motive (`revenire` / `stergere`, doar Admin, emite `REASON_CHANGE` în audit) |

### Conectare Frontend
- Se leagă modalele și vederile deja testate din `dashboard.html` (`formProgramare`, `formRespingere`, `modalConfirm` cu **butoane-bifă rapide 1-tap** pentru motivele de ștergere/anulare/neprezentare, `Istoric Imutabil`, `Motive`) direct la endpoint-urile de mai sus, fără a schimba designul aprobat.

- **Gata când:** întregul flux din agendă (creare, editare colaborativă, anti-suprapunere, duplicat, revenire, revocare, reprogramare peste/sub 30 zile, ștergere cu motiv, export CSV) rulează end-to-end pe SQLite real.

---

## Etapa 7 — Testare Automată și Manuală (~1.5h)

### Suită automată (`pytest` + `fastapi.testclient.TestClient`, DB temporar per test)
1. **Auth & Securitate:**
   - login PIN corect / greșit;
   - rate-limit `429` la 5 încercări PIN uzual și blocare `30 min` la 3 încercări greșite PIN Secret;
   - `master-recovery` invalidează toate sesiunile anterioare (`session_version`) și vechiul PIN uzual;
   - cereri fără `X-CSRF-Token` respinse cu `403`;
   - acces fără permisiune (`programari.delete` / rute Admin pentru non-admin) respins cu `403`;
   - `First-Start` respins cu `403` dacă are header de proxy (`CF-Connecting-IP` / `X-Forwarded-For`).
2. **Programări & Concurență:**
   - duminica respinsă (`422 INVALID_DAY`);
   - slot dublu pe aceeași `(date, time, line)` respins cu `409 SLOT_TAKEN` (inclusiv la 2 cereri concurente);
   - soft-delete eliberează slotul (recreare pe același slot reușește);
   - duplicat nr. auto în aceeași zi → `409 DUPLICATE_PLATE`, apoi reușește cu `confirm_duplicate: true`;
   - `STALE_VERSION` la editare simultană cu versiune veche (`409`);
   - flux complet `revenire` → `reprogram` (sub 30 zile și peste 30 zile cu `409 OVER_DEADLINE` + trecere `NORMAL`) → `revoke`.
3. **Imutabilitate & Backup:**
   - `UPDATE` și `DELETE` directe pe `appointment_history` și `core_audit_log` aruncă eroare din trigger;
   - crearea unui backup trece `PRAGMA integrity_check == 'ok'`.

**Comandă rulare teste:**
```powershell
.venv\Scripts\python -m pytest -q
```

### Checklist testare manuală (PC + Telefon + Cloudflare)
- [ ] Acces de pe PC (`http://localhost:8000`) — creare, editare, filtre, export CSV.
- [ ] Acces de pe telefon pe Wi-Fi (`http://<IP-LOCAL-PC>:8000`) — login cu tastatura numerică, carduri 1-tap, apel `tel:`, WhatsApp.
- [ ] Acces extern prin `cloudflared` — verificat că `First-Start` nu este expus și cookie-ul primește `Secure`.
- [ ] Editare simultană de pe 2 telefoane pe aceeași programare — verificat mesajul clar de conflict (`STALE_VERSION`).
- [ ] Login PIN pentru `Laur`, `Vlad`, `Gabi` + test complet `PIN Secret Admin (Preia controlul)`.
- [ ] Deschidere CSV exportat în Microsoft Excel — diacriticele (`ăâîșț`) se afișează corect.

---

## Etapa 8 — Livrare și Rulare Locală (~0.5h)

1. **Script `start.bat`:**
   - Verifică/creează `.venv` dacă lipsește;
   - Instalează `requirements.txt` la nevoie;
   - Pornește serverul cu `.venv\Scripts\python -m uvicorn core.app:app --host 0.0.0.0 --port 8000`.
2. **Windows Firewall:** adăugare regulă de intrare pentru portul TCP `8000` (profil *Private network*) ca telefoanele din Wi-Fi să se poată conecta fără blocaj.
3. **Expunere demo prin `cloudflared.exe`:**
   ```powershell
   tools\cloudflared.exe tunnel --url http://127.0.0.1:8000 --protocol http2 --no-autoupdate
   ```
   *(URL-ul de Quick Tunnel `*.trycloudflare.com` se schimbă la fiecare repornire; configurarea unui tunel cu nume fix pe domeniu propriu intră în etapa de găzduire ulterioară.)*
4. **Backup extern:** recomandată copierea periodică a folderului `backups/` pe stick USB sau în cloud.
5. **Actualizare [README.md](README.md):** adăugarea instrucțiunilor finale de instalare, `First-Start`, `start.bat` și `reset_admin_pin`.

---

## Etapa 9 — Deploy în Producție pe VPS Linux (România) cu Docker & Caddy HTTPS (~0.5h)

Decizia finală de găzduire este **VPS Linux KVM (Ubuntu 24.04) cu disc local NVMe/SSD** (ex. România: Romarg, cyber_Folks, MxHost, Hostico, ~5–10 €/lună), rulând aplicația în **Docker** în spatele **Caddy** (HTTPS/SSL automat).

### Fișiere de containerizare adăugate în rădăcină
1. **`Dockerfile`:**
   - Bazat pe `python:3.14-slim`, instalează `requirements.txt` (inclusiv `tzdata`), rulează ca utilizator non-root și pornește `uvicorn core.app:app --host 0.0.0.0 --port 8000 --workers 1` *(strict 1 worker pentru SQLite WAL)*.
2. **`.dockerignore`:**
   - Exclude `.git/`, `.venv/`, `references/`, `tools/`, `data/`, `backups/`, `tests/`, `__pycache__/` astfel încât imaginea să fie mică și curată.
3. **`docker-compose.yml`:**
   - Serviciul **`app`**:
     - `restart: unless-stopped`
     - `environment`: `TZ=Europe/Bucharest`, `ITP_TRUSTED_PROXIES=127.0.0.1,172.16.0.0/12`, `ITP_COOKIE_SECURE=auto`
     - `volumes`: `./data:/app/data` și `./backups:/app/backups` *(obligatoriu pe NVMe/SSD local al VPS-ului, niciodată pe storage de rețea/NFS)*
   - Serviciul **`caddy`** (`caddy:alpine`):
     - Porturi `80:80` și `443:443`
     - `Caddyfile`: SSL/HTTPS automat (Let's Encrypt) pentru domeniul stației (ex. `programari.domeniultau.ro`) + `reverse_proxy app:8000` + servire `robots.txt` (`Disallow: /`) + header `X-Robots-Tag: noindex, nofollow`.

### Securizare VPS & Lansare
1. **Firewall UFW pe VPS:** deschise doar porturile `22` (SSH cu cheie), `80` și `443` (`ufw allow 22,80,443/tcp`). Portul `8000` **nu** se expune direct în internet (este accesibil doar intern de către `caddy`).
2. **Inițializare Admin pe VPS (`First-Start` securizat):** pe VPS, contul Admin (`Laur` + PIN uzual + PIN Secret) se creează prin consolă SSH (`docker compose exec app python -m core.reset_admin_pin`) sau tunel SSH local `ssh -L 8000:127.0.0.1:8000`, fără a expune `/api/setup/first-start` pe internet public.
3. **DNS:** domeniu `.ro` (sau subdomeniu existent) cu nameservere în **Cloudflare (plan Free)** sau DNS direct către IP-ul VPS-ului.
4. **Backup extern:** job zilnic `cron` / `rclone` pe VPS care copiază arhiva din `./backups/` în afara serverului (Google Drive / PC stație).

---

## Definition of Done (Faza 0 + Faza 1)
- [ ] Toate migrările rulează curat de la zero pe o bază goală și pe reporniri succesive.
- [ ] Autentificare cu selecție utilizator + PIN + Dual-PIN Admin (`master-recovery`) funcționale și acoperite de teste.
- [ ] `core_audit_log` și `appointment_history` blocate la `UPDATE`/`DELETE` prin trigger SQLite.
- [ ] Modulul `programari` respectă programul Luni–Sâmbătă (`08:00–17:30`, 3 linii × 30 min), editarea colaborativă, bifele rapide de motive și anti-suprapunerea.
- [ ] Suită `pytest` 100% verde + checklist manual PC/Telefon/Cloudflare bifat.
- [ ] `start.bat` (local) și `docker compose up -d` (VPS Linux + Caddy HTTPS) pornesc aplicația curat fără pași manuali.

---

## Riscuri Identificate și Soluții

| Risc | Soluție preventivă |
| :--- | :--- |
| Lipsește wheel `bcrypt` pe un alt PC cu Python 3.14 | Fallback automat pe `hashlib.scrypt` în `core/security.py` |
| Eroare `ZoneInfoNotFoundError` pentru `Europe/Bucharest` pe Windows | Includere obligatorie `tzdata` în `requirements.txt` |
| URL-ul `trycloudflare.com` se schimbă la repornire | Acceptat pentru demo; rute 100% relative pregătite pentru tunel cu nume + domeniu |
| Blocaje `database is locked` la 2–3 dispozitive simultan | `PRAGMA journal_mode=WAL`, `busy_timeout=5000` și tranzacții `BEGIN IMMEDIATE` |
| Datele test din `localStorage` (`dashboard.html`) | Nu se migrează; baza SQLite reală pornește curat cu seed de setări și motive |
| Telefoanele de pe Wi-Fi nu văd `PC:8000` | Regulă Windows Firewall pe portul `8000` (Private network) |

---

## Ce NU intră în Faza 1 (rămâne pentru Etapa 2+)
- Modulul dedicat `clienti-vehicule` (registru separat și istoric complet per VIN).
- Modulul `inspectii` (fișe tehnice detaliate și calcul expirare 6/12/24 luni).
- Modulul `expirari-itp` (alerte 7/15/30 zile și urmărire contactări).
- Modulul `tarife-incasari` (prețuri, rapoarte financiare Admin).
- Trimitere SMS automată de pe server și tunel Cloudflare cu nume fix / domeniu propriu.
