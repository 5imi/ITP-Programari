# Memoria Proiectului — Aplicație Locală Stație ITP

## 1. Identitate și Stadiu Curent

- **Proiect:** Aplicație web locală (`localhost` + acces LAN/Wi-Fi) pentru gestionarea unei stații ITP.
- **Workspace local:** `d:\ITP programari`
- **Ramură Git:** `main` (inițializată local; pregătită pentru push pe GitHub).
- **Stadiu actual:** **Faza de proiectare, discuții și analiză** — **NU** s-a scris încă cod de aplicație.

---

## 2. Cronologia Discuției și Deciziilor (25 noiembrie 2026)

1. **Cerința inițială:**
   - Aplicație locală (`localhost`) pentru stația ITP, cu interfață HTML responsive care să funcționeze atât pe **PC**, cât și pe **telefon** (accesată din rețeaua locală / Wi-Fi).
   - Multi-utilizator: un **Administrator** cu drepturi depline și **utilizatori** cu permisiuni configurabile.
2. **Strategia de dezvoltare (Arhitectură Modulară):**
   - Utilizatorul a decis dezvoltare **modulară**: mai întâi se proiectează și construiește **Baza (Core)** stabilă, iar apoi se adaugă **module independente** peste Bază.
3. **Aspect UI (Interfață):**
   - S-a ales **aspect UI modern luminos** (contrast ridicat, ușor de citit în lumină puternică, layout adaptiv PC cu sidebar + telefon cu navigare compactă și carduri), cu resurse UI păstrate **local** (offline-first, fără dependență de internet).
4. **Stack Tehnic Backend:**
   - Verificat pe PC: Node.js `v26.13.0` și Python `3.14.3` disponibile.
   - Utilizatorul a confirmat alegerea **Python** (**Python 3.14 + FastAPI + Uvicorn + SQLite**).
   - A subliniat că suntem încă în faza de **proiectare, discuții și analiză**, fără scriere de cod.
5. **Cerință critică confirmată — Audit Log esențial:**
   - Utilizatorul a evidențiat că **Audit Log-ul este esențial**.
   - S-a integrat în `PLAN.md` ca pilon central în **Bază (Core)**: tabel `audit_log` imutabil (*append-only*), helper centralizat `core.audit.log_event(...)` obligatoriu pentru toate modulele, captare `before_json` / `after_json`, acțiuni (`LOGIN_OK`, `LOGIN_FAIL`, `LOGOUT`, `CREATE`, `UPDATE`, `DELETE`, `STATUS_CHANGE`, `PERMISSION_CHANGE`, `MODULE_TOGGLE`, `EXPORT`, `BACKUP`), IP și dispozitiv, vizualizare/filtrare/export CSV exclusiv pentru Administrator.
6. **Referințe GitHub și Pregătire Continuare de Acasă:**
   - Utilizatorul a menționat că are proiecte proprii pe GitHub ce pot fi folosite ca referință și a cerut descărcarea referințelor open-source identificate într-un folder dedicat (`references/`), salvarea întregii interacțiuni în `memory.md` și urcarea pe GitHub pentru a continua dezvoltarea de acasă.

---

## 3. Rezumatul Deciziilor Confirmate

| Domeniu | Decizie confirmată |
| :--- | :--- |
| **Tip aplicație & Acces** | `localhost` + LAN/Wi-Fi; expunere demo către beneficiar prin `cloudflared.exe`; pregătit din start (rute relative + `.env`) pentru migrare ulterioară pe domeniu + găzduire |
| **Arhitectură** | Modulară: Bază (`core/`) + module independente (`modules/<id>/`), ușor de extins și întreținut |
| **Scop Faza 1 (MVP)** | **Strict `Core` + modulul `programari`**; modulele `clienti-vehicule`, `inspectii`, `expirari-itp`, `tarife-incasari` trec în Etapa 2 |
| **Dimensionare & Program stație** | **3 linii ITP (`Linia 1`, `Linia 2`, `Linia 3`)**, program **Luni – Sâmbătă (`08:00–17:30`)** și **slot de 30 minute**, cu blocaj strict anti-suprapunere |
| **Backend & DB** | Python 3.14 + FastAPI + Uvicorn + SQLite WAL (`sqlite3` + migrări `.sql` per modul); reutilizare utilitare/pattern-uri testate din `D:\Proiecte\ITP-Sentinel` (backup WAL, `bcrypt`, CSRF, rate-limit, validări RO) |
| **Frontend & UI** | HTML5 + CSS3 + JS + Tabler UI salvat local (`references/ui-libs/`), exclusiv **Temă Deschisă (Light Theme)**, responsive PC + telefon, **bife rapide (1-tap)** inclusiv pentru motivele de ștergere/neprezentare |
| **Utilizatori & Acces** | Administrator (`Laur`) + Utilizatori (`Vlad`, `Gabi` + conturi noi dinamice cu permisiuni granulare `Editare` — pe orice programare a stației / `Revenire` / `Ștergere`, blocare/reactivare și `First-Start` strict local) |
| **Istoric Imutabil & Siguranță** | **`Istoric Imutabil`** pe programări (`appointment_history` cu trigger SQLite + soft-delete `WHERE deleted_at IS NULL` + fereastră 30–60z + căutare globală nr. auto) + **`core_audit_log`** silențios accesibil Adminului printr-un buton discret `Jurnal securitate / Admin`, plus **reconfirmare obligatorie (`#modalConfirm`)** |
| **Stadiu curent** | Toate deciziile operaționale confirmate; `PLAN.md`, `memory.md`, `README.md` și `dashboard.html` aliniate — gata pentru începerea **Fazei 0 (Backend)** |

---

## 4. Decizii Operaționale Confirmate (26 noiembrie 2026)

1. **Programul orar al stației:** **Luni – Sâmbătă**, `08:00–17:30` (configurabil în Setări).
2. **Editarea programărilor colegilor:** `Vlad` și `Gabi` (cu drept de `Editare`) pot modifica **orice programare** din agendă.
3. **Pornirea pe PC-ul stației:** temporar prin `start.bat` până la migrarea pe domeniu + găzduire.
4. **Istoric Imutabil + Jurnal Securitate:** `Istoric Imutabil` rămâne strict pe programări (cu trigger SQLite + tranzacție atomică), iar `core_audit_log` are acces discret doar pentru Admin (`Jurnal securitate / Admin`).
5. **Motive rapide cu bife:** atât la `Revenire`, cât și la `Ștergere / Neprezentare / Anulare`, motivele folosesc **butoane-bifă rapide (1 click/tap)** în locul listei dropdown.
6. **Autentificare prin selecție utilizator + PIN numeric (4–6 cifre) & Dual-PIN Admin:** pe ecranul de Login, utilizatorul își alege numele (ex. `Vlad`) și introduce PIN-ul (inclusiv prin tastatura numerică de pe ecran). Administratorul principal (`Laur`) are **2 PIN-uri**: un **PIN uzual** (4–6 cifre) pentru munca zilnică și un **PIN Secret / Master Recovery** (8–12 cifre, invizibil în UI și nemodificabil cu PIN-ul uzual) care, introdus la recuperare, incrementează `session_version` (delogând instant toate sesiunile vechi), invalidează PIN-ul uzual compromis, cere setarea unui PIN uzual nou și scrie `ADMIN_MASTER_RECOVERY` în `core_audit_log`. Ambele PIN-uri sunt hash-uite `bcrypt` cu rate-limit, iar `dashboard.html` include simularea completă (`Laur 1111`, Secret `12345678`).

---

## 5. Structura Actuală a Repository-ului

- `PLAN.md` — planul detaliat al Bazei (Core), contractul modulelor și roadmap-ul.
- `memory.md` — acest fișier cu memoria completă a discuției și deciziilor.
- `.gitignore` — reguli de ignorare pentru medii virtuale, baze `.db`, loguri și backup-uri.
- `references/` — folder dedicat cu referințele descărcate (`core-fastapi/`, `auto-domain/`, `ui-libs/`) și indexul lor în `references/README.md`.

---

## 6. Cum reluăm lucrul de acasă

1. Clonează / actualizează repository-ul pe PC-ul de acasă (`git pull`).
2. Citește mai întâi `memory.md` și `PLAN.md` pentru contextul complet.
3. Analizează codul din `references/` (în special audit log-ul, RBAC-ul, structura modulară și modelele auto) și adaugă în `references/my-projects/` proiectele proprii de referință.
4. Răspunde la punctele din secțiunea **Decizii Deschise** și validează contractul final al **Bazei (Faza 0)** înainte de a trece la scrierea codului.

Referințe pentru BAZA Modulară (FastAPI + RBAC + Audit + SQLite)
benavlabs/crudadmin — Admin HTML pentru FastAPI + HTMX, cu autentificare pe sesiune/cookie, RBAC (admin vs. utilizatori) și audit log (jurnal de acțiuni). Foarte bun ca referință pentru securitate, sesiuni și audit.
arctikant/fastapi-modular-monolith-starter-kit — Structură curată de monolit modular în FastAPI, exact pe principiul nostru de izolare între core/ și module independente.
amariwan/base-fastApi — Boilerplate FastAPI cu separare pe module, cicluri de viață clare, RBAC fin și compatibilitate nativă SQLite.
kwantabit-Technologies/fastapi-rbac — Model de permisiuni și roluri stocate dinamic în baza de date (util pentru sistemul nostru de bife per modul în panoul Admin).
2. Referințe pe Domeniul Auto / Inspecții / Programări
faizansaiyed123/autofix_backend — Backend FastAPI pentru service auto, cu structură de date excelentă pentru: clienți, vehicule, kilometraj, programări cu verificare suprapuneri în calendar și inspecții digitale (cu poze/rapoarte).
Greal-dev/car-carer — Aplicație locală (self-hosted) în FastAPI + SQLite + Alpine.js pentru evidența mașinilor; referință foarte bună pentru o aplicație ușoară, 100% locală.
ChanMeng666/automotive-repair-management-system — Exemplu practic de separare clară a interfeței Administrator vs. Tehnician/Inspector.
3. Referințe pentru UI Modern Luminos (HTML Responsive PC + Telefon)
Tabler UI (HTML/CSS pe Bootstrap 5) — Unul dintre cele mai curate și profesionale design-uri luminoase pentru panouri de administrare, responsive din start pe PC și telefon, fără React/Node.
yashhere/fastapi-htmx-starter — Model foarte curat pentru FastAPI + HTML + HTMX (interacțiuni rapide fără reîncărcarea întregii pagini și fără build complicat).
FullCalendar (pentru viitorul Modul de Programări) — Calendar zi/săptămână pe intervale orare, foarte ușor de conectat la un endpoint JSON din FastAPI.

---

## 7. Actualizare Progres (25 noiembrie 2026)

- **Repository GitHub configurat:** `https://github.com/5imi/ITP-Programari` (ramură `main`).
- **Obiectiv de realizare:** **maxim 2 zile** pentru versiunea simplă, intuitivă și ușor de folosit (reutilizând baza și proiectele existente din contul GitHub `5imi`).
- **Machetă UI/UX aprobată (`dashboard.html`):**
  - Model interactiv 100% offline bazat pe `references/ui-libs/tabler/`.
  - Aspect modern luminos, responsive PC (tabel + sidebar) și telefon (carduri mari + bară de navigare jos).
  - Include comutator demo **Administrator vs. Utilizator**, modal simplu `+ Programare` în 4 câmpuri cu verificare suprapuneri, **Audit Log live** cu filtrare după utilizator și acțiune + export CSV și comutator activare/dezactivare **Module**.
  - **Ajustare UI/UX & Aliniere Faza 1 (seară):**
    - Eliminat blocul `Ultimele evenimente Audit Log` din pagina `Programările de Azi` pentru a economisi spațiu vertical (logurile rămân exclusiv în secțiunea dedicată `Audit Log`), și adăugată filtrarea după **utilizatori** (selector cu contor evenimente + resetare filtre + export CSV al listei filtrate).
    - Confirmat scopul Fazei 1: **strict modulul `programari`** (**3 linii ITP `Linia 1–3`**, **slot 30 min**, blocaj strict anti-suprapunere, **vedere unificată `Programări` cu butoane `‹ / ›` și selector de dată integrate** + tab **`Istoric Imutabil`** append-only care păstrează intactă varianta inițială la orice editare/ștergere), restul modulelor urmând să fie adăugate modular în Etapa 2.
    - Ajustare machetă `dashboard.html`: utilizatori demo dedicați în antet și în secțiunea `Utilizatori & Drepturi` (**`Laur (Admin)`** cu drepturi depline, **`Vlad (User)`** și **`Gabi (User)`** cu permisiuni configurabile; utilizatorul activ evidențiat bold în antet și trasabil în `Istoric Imutabil`), butoane coerente (`Editează`, `Revenire` sau `+ Reprogramează` fără buton duplicat `Motiv` — motivul se editează cu click direct pe etichetă), tab **`Istoric Imutabil`** read-only (exemple live `CJ 22 MAX` modificat + `B 888 VEC` șters din agendă cu snapshot-ul inițial păstrat intact), statusuri `Programat` și `Revenire`, fără `Categorie` și **layout ultra-compact desktop + telefon**.
    - **Acoperire scenarii operaționale complete ITP:** adăugate în `dashboard.html` funcțiile de **revocare `Revenire` → `Programat`** (cu reconfirmare și înregistrare în `Istoric Imutabil`), **motiv obligatoriu/selectabil la `Șterge`** (`Anulat de client`, `Neprezentare (No-show)`, `Programare dublată`) salvat în `Istoric`, și **blocare/avertisment live la depășirea termenului de 30 zile** la reprogramare revenire (cu trecere pe ITP complet).
    - **Identitate & Logo (`ACW - Fălticeni`):** integrată sigla oficială [`references/ui-libs/aw-logo.png`](references/ui-libs/aw-logo.png) pe ecranul de Login, favicon și antetul sidebar/mobil (`ACW - Fălticeni • Stație ITP`) cu accente roșu Werkstat (`#c8141c`) pe Light Theme.
    - **Ecran de Login demo (`#loginScreen`, Tabler Light) + Gestiune Conturi & Blocare (`Utilizatori & Drepturi`):** pornește cu autentificare (`user: admin` / `parolă: admin` → `Laur`), include butoane dinamice de acces rapid, reconfirmare la `Deconectare`, plus **`+ Adaugă utilizator`**, **`Editează`** (parolă + permisiuni granulare `Editare`, `Revenire`, `Ștergere`) și **`Blochează / Activează`** cu blocaj efectiv pe ecranul de Login.
    - **Optimizare densitate mobilă (iPhone/Android):** `+ Programare` mutat pe bara de sus lângă hamburger (`☰`), `Deconectare` + user-ul activ mutate în meniul `☰`, comutator `Toate zilele` lângă bara de căutare, eliminat antetul duplicat pe `Programări` mobil, filtre comprimate pe 2 rânduri (`‹ [Dată] › [Azi] [54 lib.]` și `[Liniile ▾] [Toate] [Prog.] [Rev.]`), și toate acțiunile de pe fiecare card pe **o singură linie** (`Editează`, `Revenire`/`+ Reprog.`, `☎`, `WA`, `🗑`) — rezultând **4–5 programări complete** vizibile simultan pe telefon fără scroll (PC neschimbat).
    - **Secțiune `Motive` (Administrare) & Terminologie `User / Utilizator`:** eliminat complet termenul `restrâns` (`Laur (Admin)`, `Vlad (User)`, `Gabi (User)`, profil `Utilizator`) și adăugată secțiunea dedicată **`Motive`** (Admin) pentru gestionarea și salvarea persistentă (`localStorage`) a listelor **Motive Revenire (30 zile)** și **Motive Ștergere / Anulare**, sincronizate live în modalele `Revenire` și `Șterge`.
    - **Audit & Polish demo (`dashboard.html`):** dată curentă dinamică (`TODAY_DATE` calculată din data locală reală + date relative pentru seed/istoric/expirări), gardă completă `canEdit` / `canRevenire` (ascundere `+ Programare` + verificare la intrare în modale), blocare programări în trecut pentru non-admin, validare strictă `Nr. auto` și telefon România (`07xx/02xx/03xx` / `+40...`), autocomplete client/telefon la tastarea `Nr. auto`, filtru pe linie (`Toate liniile / Linia 1 / Linia 2 / Linia 3`) + locuri libere per linie în modal, fix `+ Reprogramează` (ziua următoare implicit pentru revenire din ziua curentă, fără alertă falsă duplicat pe vizita-părinte), și persistență `localStorage` (`acw_itp_demo_v2`) + `Resetează Demo` reconfirmat pentru Admin.
    - Verificare **duplicare nr. auto în aceeași zi** (`normalizePlateKey` / `formatRoPlate` — ex. `b458itp` / `B-458-ITP` = `B 458 ITP`) cu **notificare live în formular** (`#duplicateLiveAlert` imediat la tastarea numărului/schimbarea datei) + dialog de reconfirmare (`#modalConfirm`, `warning`) la creare și editare.
    - Regula de **reconfirmare obligatorie (`#modalConfirm`)**: aplicată uniform în `dashboard.html` pentru `Șterge` programare, salvarea modificărilor din `Editează` (cu listă clară a câmpurilor modificate și revenire pe form la `Renunță`), activare/dezactivare `Module`, `Backup`, `Resetează Demo` și status `Expirări`.
    - Eliminat tab-ul separat `Audit Log` din UI (`dashboard.html`) pentru simplitate maximă (rămân 4 tab-uri curate pe mobil: `Programări`, `Istoric`, `Mașini`, `Expirări`); trasabilitatea s-a mutat integral în **`Istoric Imutabil`** (scenarii demo complete cu `Laur`, `Vlad`, `Gabi`: editări multiple `CJ 22 MAX`, ștergere `B 888 VEC`, revenire + reprogramare `B 305 TAX` pe ziua următoare, mutare linie `AG 18 VTR`, filtru pe utilizator + `Export CSV`), iar în backend rămâne doar un log intern SQLite de securitate/backup.
    - Confirmată strategia de livrare: `localhost` + LAN + **`cloudflared.exe`** pentru demo live către beneficiar, apoi migrare pe domeniu + găzduire; actualizate `PLAN.md`, `memory.md` și `dashboard.html` corespunzător.

---

## 8. Actualizare Progres (26 noiembrie 2026)

- **Sincronizat cu versiunea de acasă (`f6b03b9`):** `dashboard.html` complet (Login ACW Fălticeni, logo `aw-logo.png`, useri `Laur`/`Vlad`/`Gabi` + CRUD & blocare, validări RO, autocomplete, duplicat live, revenire 30 zile + revocare + reprogramare, secțiune `Motive`, `Istoric Imutabil`, densitate mobilă optimizată).
- **Decizii operaționale închise și trecute în `PLAN.md`:**
  - Arhitectură pe 2 straturi pentru trasabilitate: `appointment_history` (strict pe programări în UI, scris atomic în aceeași tranzacție cu `appointments` + soft-delete `WHERE deleted_at IS NULL` + trigger SQLite `BEFORE UPDATE`/`DELETE`, afișare implicită 30–60z + căutare globală nr. auto) + `core_audit_log` silențios accesibil Adminului printr-un buton discret `Jurnal securitate / Admin`.
  - Program stație: **Luni – Sâmbătă (`08:00–17:30`)**.
  - `Vlad` și `Gabi` (cu drept `Editare`) pot modifica **orice programare** din agendă.
  - Pornirea prin `start.bat` este soluție temporară până la mutarea pe găzduire.
  - **UX rapid pentru motive:** și motivele de **ștergere / anulare / neprezentare** vor fi implementate cu **butoane-bifă rapide (1 click/tap)** (similar motivelor de revenire), nu dropdown lent.

---

## 9. Actualizare Progres (26 noiembrie 2026 — după-amiază)

- **Actualizări UI/UX & Securitate integrate în machetă (`dashboard.html`) și documentație:**
  - Logare rapidă prin **selecție utilizator + PIN numeric (4–6 cifre)** cu tastatură numerică pe ecran (model POS/atelier).
  - **Dual-PIN pentru Administratorul principal (`Laur`):** PIN uzual (`4–6` cifre) + **PIN Secret / Master Recovery (`8–12` cifre)** care deloghează toate celelalte sesiuni (`session_version += 1`), invalidează vechiul PIN uzual, obligă setarea unui PIN uzual nou și scrie `ADMIN_MASTER_RECOVERY` în `core_audit_log`.
  - **Motive de ștergere / anulare / neprezentare** transformate în **butoane-bifă rapide (1-tap)** în `#modalConfirm`.
  - **Fundal stil WhatsApp** (`#efeae2` + textură SVG auto/ITP subtilă 100% offline) peste care cardurile albe ies clar în evidență.
  - Redenumire utilizator demo `Vali` → **`Vlad`** (PIN `2222`).
  - Demo live expus securizat prin `tools/cloudflared.exe` + `tools/serve_demo.py` (`tools/` ignorat din Git prin `.gitignore`).
- **Ghid detaliat de implementare creat (`IMPLEMENTARE.md`):** conține pașii exacți pentru **Faza 0 (`Core`) + Faza 1 (`programari`)** — Etapele `0–8`, schemele SQL complete cu trigger-e append-only, regulile și codurile de eroare, endpoint-urile API, planul de testare `pytest` + manual și livrarea (`start.bat` + Firewall).
- **Decizie finală găzduire (26 noiembrie 2026 — seară):**
  - Adăugat `robots.txt` (`Disallow: /`) pentru neindexare în motoarele de căutare.
  - Confirmată găzduirea 24/7 a aplicației și bazei de date pe **VPS Linux KVM (România, NVMe local)** folosind **Docker (`--workers 1` + volume persistente `./data` și `./backups`) + Caddy HTTPS**, adăugată ca **Etapa 9** în `IMPLEMENTARE.md` și în `PLAN.md`.
  - **Opțiune alternativă analizată înainte de start cod:** Găzduire Web cPanel (`gazduire-web.html`, ~2–4 €/lună cu backup Acronis și email-uri incluse) — necesită adaptarea backend-ului din `IMPLEMENTARE.md` pe **PHP 8 + MariaDB** (păstrând neschimbat frontend-ul `dashboard.html`). Alegerea finală (**VPS `RMGP-1` Python/Docker** vs. **cPanel `PHP 8 + MariaDB`**) se confirmă la reluare de acasă.

---

## 10. Actualizare Progres (26 noiembrie 2026 — seară, de acasă)

- **Sincronizat cu GitHub (`origin/main` `681bf88`)** și validată decizia finală de producție:
  - **Backend confirmat:** **PHP 8.2+ pur + MariaDB InnoDB** pe **Romarg Găzduire Web cPanel** (pachetul de bază de la `1,95 €/lună`, cu `UNIQUE KEY` pe slot activ + `SELECT ... FOR UPDATE` + `version` pentru protecție completă la race conditions).
  - Documentația Python/VPS (`PLAN.md` și `IMPLEMENTARE.md`) se păstrează intactă pentru o eventuală migrare ulterioară pe VPS; au fost create fișierele dedicate **`PLAN_PHP.md`** și **`IMPLEMENTARE_PHP.md`** (actualizat și `README.md` cu referințele către ambele variante).
- **Audit & completări `dashboard.html` (`acw_itp_demo_v3`):**
  - **Istoric Imutabil complet (cu Dată + Oră + Utilizator + Tip acțiune):** reparat logarea `CREAT`, separat tipul **`REVOCAT`** de `MODIFICAT`, adăugat filtrul **`Revocate`** și **`Intacte`**, consemnare explicită a datei (`DD.MM.YYYY`) + orei (`HH:MM`) + actorului pe fiecare eveniment (inclusiv în `Export CSV`).
  - **Jurnal Securitate / Admin (`#modalSecurityLog`):** buton discret în `Istoric` și `Utilizatori & Drepturi` care înregistrează cu dată + oră + utilizator toate acțiunile de sistem (`LOGIN_OK`, `LOGIN_FAIL`, `LOGOUT`, `PIN_CHANGE`, `ADMIN_MASTER_RECOVERY`, `USER_CREATE/UPDATE/BLOCK/UNBLOCK`, `REASON_*`, `MODULE_TOGGLE`, `EXPIRARE_CONTACTATA`, `BACKUP`, `DEMO_RESET`) + Export CSV.
  - **Reparații scenarii & UX:** eliberare automată `resolvedByReinspectionId` la ștergerea unei vizite `REVENIRE` (redeschide `+ Reprogramează`), verificare termen 30 zile la `Editează` pe `REVENIRE`, regulă **Duminică stație închisă** (`Luni–Sâmbătă`), buton `+ Programează` direct din `Expirări ITP` & `Clienți & Vehicule`, **`Schimbă PIN`** propriu pentru utilizatorul logat, bară clickabilă grad ocupare pe linii (`L1 / L2 / L3`) și readucere buton `🔍` pliabil pe mobil.
  - **Păstrare context dată curentă la programări în viitor (`showToast` cu buton `Vezi ziua`):** la crearea sau editarea unei programări pe o altă dată decât ziua vizualizată (sau din alte ecrane ca `Expirări ITP`), aplicația păstrează `state.selectedDate` și view-ul curent neschimbate și afișează în toast butonul rapid **`Vezi ziua`** (5 secunde, cu salt opțional în agenda acelei zile). Testat cu Playwright pe Desktop (`1280x800`) și Mobil (`375x812`): toate scenariile au trecut cu `0` erori JS și `overflow=0px`.
  - **Secțiune „Programări în viitor” direct la finalul agendei de Azi (`dashboard.html`):** când vizualizezi ziua de `Azi`, sub lista zilei curente apare un separator clar `Programări în viitor (N)` și programările din zilele următoare, cu badge-ul `Programat` și **data afișată direct dedesubt** (`.itp-future-date-note`), fără a afecta ocuparea pe linii (`L1/L2/L3`) de Azi. Populat implicit cu 4 programări în zile lucrătoare viitoare (`addOpenDaysIso` — evită Duminica: `B 305 TAX`, `SV 42 KLM`, `BT 19 VWS`, `IS 63 TDN`). Verificat vizual + funcțional Playwright pe Desktop (`1280x800`) și Mobil (`375x812`): `PASS`, `0` erori JS, `overflow=0px`.

---

## 11. Implementare Producție PHP 8.2+ & MariaDB InnoDB (26 noiembrie 2026 — seară)

- **Core PHP & Securitate (`config/`, `core/`, `.htaccess`, `index.php`):**
  - Autoloader PSR-4 propriu (`core/Autoloader.php`), configurare din `config/env.php` (`config/env.example.php` în Git), protecție `.htaccess` (blochează accesul HTTP direct la `config/`, `core/`, `modules/`, `backups/`, `tests/`, `*.sql`, `*.md`, `.env`).
  - `Core\Database`: PDO MariaDB InnoDB (`ATTR_EMULATE_PREPARES => false`, aliniere `time_zone` pe `Europe/Bucharest`, retry pe deadlock `1213/40001`).
  - `Core\Migrator`: runner SQL incremental (`_migrations` cu checksum SHA-256) pentru `core/migrations/*` și `modules/*/migrations/*`, cu tratare sigură a trigger-elor append-only (`*_triggers.sql`) pe shared hosting.
  - `Core\Auth` + `Core\Controllers\AuthController`: setup unic `POST /api/setup/first-start` (când `users` e gol — fără credențiale hardcodate), login cu PIN `password_hash`, **Dual-PIN Master Recovery** (`SELECT ... FOR UPDATE` + invalidare sesiuni vechi prin `session_version`), `POST /api/auth/change-pin`, rate-limit în `login_attempts`, `X-CSRF-Token`, audit imutabil (`core_audit_log`).
  - `Core\Controllers\AdminController` + `Core\BackupService` + `Core\SettingsService`: CRUD utilizatori & permisiuni granulare, activare/dezactivare module (`ModuleLoader`), setări stație persistate în MariaDB, export CSV UTF-8 BOM pentru `Jurnal Securitate`, snapshot-uri JSON descărcabile în `backups/`.
- **Modulul `programari` (`modules/programari/`):**
  - `001_init.sql` + `002_history_triggers.sql`: tabele `appointments` (slot atomic `uq_appt_slot` cu coloană generată `active_slot_flag` + `version`), `appointment_history` (append-only `VARCHAR(32)`), `reasons` (`revenire` / `stergere`).
  - `AppointmentService`: protecție concurență (`1062` → `409 SLOT_TAKEN`, `FOR UPDATE` + `version` → `409 STALE_VERSION`), validări domeniu (`Validators`), `CREAT`, `MODIFICAT`, `REVENIRE`, `REVOCAT`, `REPROGRAMAT` (inclusiv termen 30 zile), soft-delete `ȘTERS` cu eliberare automată `resolved_by_reinspection_id` pe părinte.
- **Frontend producție (`assets/shell.html`, `assets/css/app.css`, `assets/js/api.js`, `assets/js/app.js`, `manifest.json`):**
  - 100% conectat la REST API PHP/MariaDB, fără date demo sau PIN-uri hardcodate (`#setupScreen`, `#loginScreen` cu tastatură PIN + `Master Recovery`, `#appShell` cu `Programări` + secțiunea `Programări în viitor`, `Istoric Imutabil`, panou Admin complet).
- **Verificări realizate:**
  - Audit static Python pe cele 25 fișiere PHP (sintaxă/acolade/strict_types + zero `UPDATE`/`DELETE` pe tabelele append-only) + verificare zero date demo în `assets/`: **PASS**.
  - Test E2E Playwright pe `assets/shell.html` + `assets/js/app.js` + `assets/js/api.js` (Desktop `1280×800` și Mobil `375×812`) contra mock REST stateful (First-Start Setup, creare programări azi + viitor, secțiunea `Programări în viitor`, `Istoric Imutabil`, `Utilizatori`): **PASS (`overflow=0px`, `0` erori JS/console)**.
  - Suita backend PHP + MariaDB (`tests/run_tests.php`, 10 cereri paralele `curl_multi_exec` pentru race condition) este pregătită; pe acest PC nu sunt instalate încă `php` și `mysql` (se va rula odată cu Laragon/XAMPP sau pe Romarg cPanel).
- **Acțiune rapidă `SMS` & semnătură mesaje (`dashboard.html`, `assets/js/app.js`, `core/SettingsService.php`):**
  - Adăugat helper `buildSmsHref()` (`sms:+40...?body=` pe Android / `&body=` pe iOS) cu ordinea confirmată de butoane **`Editează` → `Revenire` → `Sună` → `WA` → `SMS` → `Șterge`**.
  - Semnătură oficială inclusă în mesajele WA & SMS: **`AUTOMOTIVE CENTER WERKSTAT SRL Falticeni Str. Magazia Garii 3 F`** (stocată și editabilă din `Setări Stație` prin `station_signature` în MariaDB `settings`). Verificat Playwright pe Desktop & Mobil (`overflow=0px`, `0` erori JS).
  - Delimitare stabilită pentru cele 4 categorii operaționale fără dublare: `Programări` (agenda activă), `Istoric Imutabil` (audit protejat), `Clienți & Vehicule` (fișă permanentă per mașină cu `Ultima vizită` & `Scadență ITP`, fără duplicare `Ultim status`), `Expirări ITP` (coadă de contactare `De contactat` / `Deja programate` care mută automat mașinile programate în viitor cu `findActiveScheduledForPlate`). Verificat Playwright pe Desktop & Mobil (`overflow=0px`, `0` erori JS).
  - **Mediu local PHP 8.3.37 + MariaDB 13.1 (`127.0.0.1:8080` + `router.php`) & format `zz/ll/aaaa` + câmpuri separate `Client` și `Mașină`:**
    - Instalat local PHP 8.3 + MariaDB (`acw_itp`), rulată suita `tests/run_tests.php` (`1` câștigător + `9 x 409 SLOT_TAKEN`, `STALE_VERSION`, `Revenire/REVOCAT/Reprogramare/Ștergere`, `Istoric Imutabil`).
    - Adăugată migrarea `modules/programari/migrations/003_add_vehicle.sql` (`vehicle VARCHAR(120)` separat pe `appointments`), actualizat `AppointmentRepo`/`AppointmentService` și formularul din `assets/shell.html`, `assets/js/api.js`/`assets/js/app.js` cu câmpuri separate **Client** (`#inpClient`) + **Mașină** (`#inpVehicle`) și wrapper uniform **`zz/ll/aaaa` (`DD/MM/YYYY`)** cu selector calendar nativ (`.itp-ro-date-wrap`). Verificat `php -l`, migrare MariaDB `MIGRATIONS_OK warnings=0` și Playwright Desktop/Mobil (`26/11/2026->28/11/2026`, `overflow=0px`).

---

## 12. Curățenie Producție, Asset-uri Self-Contained & Fix Meniu Mobil (26 noiembrie 2026)

- **Simplificare UI (fără jargon tehnic):** eliminate din `assets/shell.html` și `assets/js/app.js` toate referințele interne vizibile utilizatorului (`MariaDB`, `InnoDB`, `core_audit_log`, `Europe/Bucharest`, `Snapshot`, `cPanel`), păstrând texte curate orientate spre operarea stației ITP (ex: `Salvează setările`, `Backup Bază Date`, `Copiere JSON`, `Audit protejat`).
- **Curățenie & structurare pentru urcare directă pe server:**
  - Mutate documentele tehnice interne (`PLAN.md`, `PLAN_PHP.md`, `IMPLEMENTARE.md`, `IMPLEMENTARE_PHP.md`, `memory.md` -> `MEMORY.md`) în `.agents/` (păstrând doar `README.md` la rădăcină conform `AGENTS.md`).
  - Mutate fișierele demo/arhivă (`dashboard.html`, `start_demo_tunnel.bat`, `references/`) în `d:\Proiecte\.agents\referinte\itp-programari-archive\`.
  - Copiate asset-urile UI active în aplicație: `assets/vendor/tabler/` (`tabler.min.css`, `tabler.min.js`, `LICENSE`) și `assets/img/aw-logo.png`, actualizând referințele din `assets/shell.html`, `manifest.json` și `index.php`.
  - Blocat în `.htaccess` accesul HTTP și spre `.agents/` și `router.php`; exclus în `.gitignore` fișierele temporare (`link_cloudflare*.txt`) și `.agents/*` (cu excepția `.agents/*.md`).
- **Fix închidere automată meniu mobil (`#sidebar-nav`):**
  - Actualizat `closeMobileSidebar(immediate = false)` în `assets/js/app.js` pentru reset complet al claselor/stării Collapse (`.show`, `.collapsing`, `height`, `aria-expanded="false"`).
  - Apelat `closeMobileSidebar(true)` la intrarea în aplicație (`loadSessionAndEnterApp`), delogare (`requestLogout`, `showLoginScreen`) și apelat la schimbarea secțiunii, deschiderea modalelor (`openNewAppointmentModal`, `openChangePinModal`, `openSecurityLogModal`) sau click în afara `#sidebarMenu` pe ecrane `< 992px`.
- **Indicator ocupare multi-linie & auto-selecție slot liber în modal (`AppointmentService.php`, `shell.html`, `app.js`):**
  - Extins `AppointmentService::getFreeSlots()` (`GET /api/m/programari/slots`) să returneze și `taken_by_slot` (liniile ocupate per oră) și `free_count_by_line` (locuri libere per linie în ziua selectată).
  - În `#modalProgramare`, `#inpHour` marchează `08:30 (ocupat)` (`disabled`) pe linia ocupată și `08:30 (L2 ocup.)` când altă linie are programare la acea oră; la schimbarea liniei/datei, dacă ora curentă devine ocupată (`!free.has(curVal)`), sare automat pe primul slot liber din `free_slots` în loc să păstreze opțiunea `disabled`.
  - Adăugat `onHourSelectChange()` (`updateLineOptionsLabels()`) care actualizează live etichetele din `#inpLine` (`Linia 2 (ocupat 08:30)` / `Linia 1 (20 lib.)`), și preselectarea filtrului activ de linie în `openNewAppointmentModal()` când `state.lineFilter !== 'Toate'`. Verificat `php -l`, `node --check assets/js/app.js` și `AppointmentService::getFreeSlots()` contra MariaDB.
- **Aliniere butoane pe o singură linie în tabelul desktop & eliminare dublură badge `Azi` (`assets/css/app.css`, `assets/js/app.js`):**
  - Adăugate clasele `.itp-actions-cell` (`width: 1%; white-space: nowrap;`), `.itp-desk-act` (butoane compacte `white-space: nowrap` într-un `d-inline-flex flex-nowrap gap-1`) și `.itp-status-cell` (`max-width: 260px`) ca toate acțiunile rapide (`Editează`, `Revenire`/`+ Reprogramează`, `Sună`, `WA`, `Șterge`) să rămână pe **un singur rând** în Chrome și Firefox; butonul `SMS` a fost păstrat exclusiv pe mobil (`buildMobileCard`), iar pe desktop rămâne `WA` (WhatsApp Web) + `Sună`.
  - Eliminat badge-ul redundant `Azi` de lângă butonul activ `Azi` (păstrat doar pentru `Istoric` / `Zi viitoare`).
- **Pachetul 1 — Stabilizare, Securitate & Pregătire Linux/cPanel (26 noiembrie 2026):**
  - **Core PHP & Performanță:** `Autoloader` rezolvă acum case-sensitive directoarele cu litere mici (`core/controllers/`) pentru producția Linux/cPanel; `Migrator` face un singur `SELECT` pe `_migrations` (fără DDL `CREATE TABLE` pe fiecare request și marchează trigger-ele refuzate `1227/1419` ca procesate); `ModuleLoader` face `UPDATE` doar la schimbări reale; `SettingsService` cache-uiește configurația pe request și validează strict `open_time`/`close_time`/`work_days`; `/api/health` nu mai expune `migrationWarnings`.
  - **Securitate Auth, Admin, Backup & HTTP:** fix critic rollback pe `masterRecovery()` (încercările eșuate pe PIN Secret Master se salvează acum în `login_attempts` + `core_audit_log` în afara tranzacției, activând efectiv rate-limit-ul `429`); `changePin()` are rate-limit și întoarce `422 INVALID_CURRENT_PIN` (nu `401`) ca să nu delogheze sesiunea; `AdminController::updateUser()` blochează modificarea `master_pin` din sesiunea standard (`403 MASTER_PIN_PROTECTED`) și protejează Adminul principal; `Auth::createSessionCookie()` folosește timestamp-ul exact (`getTimestamp()`) + curățare periodică `sessions`/`login_attempts`; `Auth::requireUser()` acceptă `$touchSession = false` la `?poll=1` pentru a nu anula `SESSION_IDLE_HOURS`; `Request` preferă `CF-Connecting-IP` / cel mai din dreapta IP ne-trusted din `X-Forwarded-For`; `Response::emit()` blochează și `+`/`-` la CSV injection; `BackupService` citește în tranzacție `REPEATABLE READ` consistentă, exclude `pin_hash`/`master_pin_hash`, folosește timezone-ul aplicației și păstrează ultimele 30 copii, iar `downloadBackup()` loghează `BACKUP_DOWNLOAD` cu headere de securitate; `.htaccess` (`[NC,F,L]`), `router.php` (`.agents` + `realpath`) și `.gitignore` igienizate.
  - **Modul `programari` & UTF-8:** înlocuit `substr()` cu `mb_substr(..., 'UTF-8')` în `AuditLogger`, `HistoryService`, `Validators`, `SettingsService` și `AppointmentService`; gardă `PAST_DAY_ADMIN_ONLY` adăugată și pe `softDelete()`; `revokeRevenire()` blochează revocarea dacă există reprogramare activă (`409 HAS_ACTIVE_REINSPECTION`); `reprogramFromRevenire()` validează `date >= parent.date` (`422 BEFORE_PARENT_DATE`); `getHistory()` întoarce lista completă `all_actors` neafectată de filtrarea pe un singur actor.
  - **Frontend & Paritate Mobil (`api.js`, `shell.html`, `app.js`):** `api.js` deloghează doar pe coduri reale de sesiune expirată + suport `AbortController`; cardurile mobile afișează `De verificat:`, `Termen N zile` și buton clickabil pe `Motiv:` pentru editare/revocare `Revenire`; înlocuite `onclick`-urile cu interpolare de string prin `data-itp-action` + event delegation (elimină riscul XSS / rupere pe apostrof `'`); fix `bg-amber-lt` → `bg-orange-lt`; fix `#btnMobAllDays` activ; fix `cleanPhoneWa()` pentru prefixe `0040`/`00...`; polling inteligent fără flicker/pierdere focus (doar când tab-ul e vizibil, fără câmp focusat, cu semnătură diff și `?poll=1`); fix redeschidere modal la eroare după confirmare `409` + handler `hidden.bs.modal` pe `#modalConfirm`; protecție double-submit (`withBusy`) și reconfirmări pe acțiuni sensibile (`Deconectare`, `Blochează`, `Șterge motiv`, `Revocă revenirea`, `Module`); filtre complete în `history.csv` și `audit.csv`.
  - **Verificări:** `php -l` pe toate fișierele PHP (`0` erori), `node --check` pe `api.js`/`app.js` (`0` erori), suită completă de teste izolate pe MariaDB (11/11 `PASS`: Autoloader Linux, Master PIN `429`, `changePin` `422`, `MASTER_PIN_PROTECTED`, `poll=1` idle preservation, `PAST_DAY_ADMIN_ONLY`, UTF-8 diacritice >900B, `BEFORE_PARENT_DATE`, `HAS_ACTIVE_REINSPECTION`, `all_actors`, `X-Forwarded-For`), extensie [tests/run_tests.php](file:///D:/Proiecte/ITP-Programari/tests/run_tests.php) și Playwright Desktop `1280×800` + Mobil `375×812` (`PASS`, `overflow=0px`, `0` erori JS).
- **Pasul 1 — Acces & Termen Corect pe `Revenire` (`R01`, `R02`, `R11`, `R15`):**
  - **Backend (`modules/programari/AppointmentService.php`):** adăugat helper `resolveReinspectionDeadline()` cu urcare pe lanțul `parent_id`; `markRevenire()` salvează explicit `reinspection_deadline` în MariaDB (`R15`) și păstrează termenul inițial (din prima vizită `A`) la a 2-a respingere în lanț `A → B → C` (`R02`), fără resetare cu +30 zile noi; `reprogramFromRevenire()` și `update()` folosesc termenul rezolvat din lanț; `revokeRevenire()` păstrează `reinspection_deadline` pe copiii `REVENIRE` și îl șterge doar pe vizitele `NORMAL`.
  - **Frontend (`assets/js/app.js`):** decuplat `canReprog` de restricția `a.date >= state.todayDate` astfel încât utilizatorii `User` cu drept `programari.revenire` să poată apăsa **`+ Reprogramează`** inclusiv pe respingerile din zilele trecute (`R01`); folosit `a.reinspection_deadline` salvat + adăugat `getLastOpenDayOnOrBefore()` și `diffDaysIso()` pentru afișarea `Termen inițial:` pe lanțuri și avertisment clar `(zi închisă — ultima zi utilă: DD.MM.YYYY)` pe agendă, banner modal și mesaj `WA`/`SMS` când termenul cade într-o zi închisă (`R11`).
  - **Teste Pasul 1:** extins [tests/run_tests.php](file:///D:/Proiecte/ITP-Programari/tests/run_tests.php); rulat `php -l` (`0` erori), `node --check` (`0` erori), 7 teste izolate MariaDB (`PASS`: `R15` salvare termen & imutabilitate la schimbare setări, `R01` reprogramare `User` din trecut, `R02` lanț `A→B→C` + `OVER_DEADLINE`, `revokeRevenire` pe `REVENIRE`) și Playwright Desktop `1280×800` + Mobil `375×812` cu utilizator non-admin pe dată trecută și termen într-o Duminică (`PASS`, `overflow=0px`, `0` erori JS).
- **Pasul 2 — Consistență Părinte ↔ Copil (`R03`, `R04`, `R05`, `R06`, `R07`, `R14`):**
  - **Backend (`modules/programari/AppointmentService.php`):** adăugate helper-ele `assertAfterParentSlot()` și `buildVerifySummary()`; `reprogramFromRevenire()` preia/validează `phone`, `client`, `vehicle` editate în modal (`R03`) și blochează reprogramarea în aceeași zi pe oră `<= parent.time` (`422 BEFORE_PARENT_TIME`, `R07`); `softDelete()` blochează ștergerea părintelui cât timp are copil activ (`409 HAS_ACTIVE_REINSPECTION` cu `FOR UPDATE`, `R04`) și diferențiază mesajul la ștergerea unui copil `NORMAL`; `markRevenire()` sincronizează automat `verify_notes` pe copilul activ `REVENIRE` (`R05`); `update()` aplică `assertAfterParentSlot()` pe copii, re-derivează `REVENIRE` + `verify_notes` dacă copilul trece înapoi în termen din `NORMAL` (`R06`), blochează mutarea părintelui la/după copil (`422 INVALID_PARENT_ORDER`) și propagă modificarea `plate`/termenului pe copilul activ (`R14`).
  - **Frontend (`assets/js/app.js`):** modalul `+ Reprogramează` trimite `phone`, `client`, `vehicle` (`R03`), setează `inpDate.min >= parent.date` și dezactivează/evită în `refreshHourOptions()` orele `<= parent.time` în aceeași zi (`R07`).
  - **Teste Pasul 2:** extins [tests/run_tests.php](file:///D:/Proiecte/ITP-Programari/tests/run_tests.php) (`R04`, `R05`); `php -l` & `node --check` (`0` erori), 7/7 teste izolate MariaDB (`R07`, `R03`, `R04`, `R05`, `R06`, `R14` — `PASS`) și Playwright Desktop `1280×800` + Mobil `375×812` (`PASS`, `overflow=0px`, `0` erori JS).
- **Pasul 3 (`R08`, `R09`, `R10`, `R13` — Filtrul `Reveniri deschise`, alertă lookup 1-click, stări vizuale/mesaje & blocare optimistă părinte):**
  - **Backend ([AppointmentRepo.php](file:///D:/Proiecte/ITP-Programari/modules/programari/AppointmentRepo.php), [AppointmentService.php](file:///D:/Proiecte/ITP-Programari/modules/programari/AppointmentService.php)):**
    - `R08`: `AppointmentRepo::listAgenda()` adaugă `LEFT JOIN appointments child ON child.id = a.resolved_by_reinspection_id AND child.deleted_at IS NULL` (returnând `reinspection_date`, `reinspection_time`, `reinspection_line`) și modul dedicat `$status === 'Reveniri deschise'` (`a.status = 'Revenire' AND a.resolved_by_reinspection_id IS NULL AND a.appt_date >= $sinceDate`, `LIMIT 200`, ordonat după `COALESCE(a.reinspection_deadline, a.appt_date) ASC, a.appt_date DESC, a.appt_time ASC`); `AppointmentRepo::countOpenReinspections()` și `AppointmentService::getAgenda()` returnează `open_reveniri_count` pe fereastra `max(60, $reinspDays + 30)`.
    - `R09`: `AppointmentService::lookupByPlate()` detectează ultima `Revenire` nerezolvată pentru `plate_key`, calculează termenul prin `resolveReinspectionDeadline()` dacă lipsește și atașează `open_revenire` în răspunsul lookup.
    - `R13`: `AppointmentService::reprogramFromRevenire()` verifică `parent_version` opțional și aruncă `409 STALE_VERSION` la conflict de versiune pe programarea părinte.
  - **Frontend ([assets/shell.html](file:///D:/Proiecte/ITP-Programari/assets/shell.html), [assets/js/app.js](file:///D:/Proiecte/ITP-Programari/assets/js/app.js)):**
    - `R08`: Filtru `Reveniri deschise (<count>)` în `statusFilters` cu afișare multi-zi (`showAllDatesCol`), reset la schimbarea datei (`selectDate`/`shiftSelectedDate`) și empty-state dedicat.
    - `R09`: Alertă `#plateOpenRevAlert` în `#modalProgramare` la potrivire de număr cu buton 1-click `Leagă ca Revenire` (`switchNewModalToLookupReprogram()`), păstrând slotul selectat.
    - `R10`: Vizualizare clară pentru părinte reprogramat (`Reprogramată: DD.MM.YYYY HH:MM (Linia X)` cu salt rapid pe dată), insignă `Revenire prog.` (`bg-indigo-lt text-indigo`) pe vizita copil `REVENIRE`, etichetă `Termen expirat`/`Expirat` când `state.todayDate > deadline`, plus mesaje WA/SMS dedicate.
    - `R13`: `submitProgramareWithFlags()` trimite `parent_version: state.reprogramParent.version` și reîncarcă agenda la `STALE_VERSION`.
  - **Teste Pasul 3:** extins [tests/run_tests.php](file:///D:/Proiecte/ITP-Programari/tests/run_tests.php) (testul `4b-1.5` pentru `status=Reveniri deschise`, `open_reveniri_count` și `reinspection_date`); `php -l` & `node --check` (`0` erori), 4/4 teste izolate MariaDB (`R08`, `R09`, `R13`, `R10 & R09` — `PASS`) și Playwright Desktop `1280×800` + Mobil `375×812` (`PASS`, `overflow=0px`, `0` erori JS).
- **Pasul 4 (`P01`, `P02`, `P03`, `P06`, `M01`, `M02`, `M03` — Flux programări standard & comunicare):**
  - **Backend ([AppointmentRepo.php](file:///D:/Proiecte/ITP-Programari/modules/programari/AppointmentRepo.php), [AppointmentService.php](file:///D:/Proiecte/ITP-Programari/modules/programari/AppointmentService.php), [Controller.php](file:///D:/Proiecte/ITP-Programari/modules/programari/Controller.php)):**
    - `P01`: `AppointmentService::getAgenda()` și `getFreeSlots()` returnează `now_time` (`H:i`, fus `Europe/Bucharest`) alături de `today_date`.
    - `P03`: `AppointmentRepo::buildPhoneSearchClause()` compară normalizat fără spații, cratime, puncte sau paranteze (plus echivalență `+40`/`0040`/`0`), aplicat atât în `listAgenda()`, cât și în `listHistoryEntries()`.
    - `P06`: `GET /api/m/programari/lookup` (`Controller.php` + `AppointmentService::lookupByPlate()`) acceptă `date`, `exclude_id`, `exclude_parent_id` și returnează `same_day_conflict` pentru avertizare live înainte de submit.
  - **Frontend ([assets/shell.html](file:///D:/Proiecte/ITP-Programari/assets/shell.html), [assets/js/app.js](file:///D:/Proiecte/ITP-Programari/assets/js/app.js)):**
    - `P01`: `refreshHourOptions()` preselectează pe ziua de azi primul slot liber `>= now_time` (`state.nowTime`) și marchează orele anterioare cu `(trecut)`.
    - `P02`: la deschiderea modalului sau schimbarea datei (`onModalDateChange`), dacă linia selectată are `0` locuri libere, comută automat pe prima linie disponibilă și afișează alertă informativă `#slotAvailabilityAlert`; când toate liniile sunt pline, afișează alertă `Zi complet ocupată`, golește `#inpHour` (`Fără locuri`) și dezactivează `#modalProgramareSubmitBtn` (inclusiv gardă la `SLOT_TAKEN`).
    - `P06`: `runPlateLookupAndAlerts()` verifică la tastare și la schimbarea datei în modal conflictul în aceeași zi și afișează `#plateDupAlert` (`exclude_id`/`exclude_parent_id` respectate).
    - `M01`, `M02`, `M03`: mesaje `WhatsApp` păstrează diacriticele prin `buildAppointmentMsgText()`, iar `SMS` (`buildSmsHref`) folosește `stripRoDiacritics()` (GSM-7 ASCII); linkul `tel:` folosește `cleanPhoneTel()`; pentru numere fixe `02x`/`03x` (`isLandlinePhoneRo()`), butonul `SMS` este ascuns pe cardul mobil păstrând `Tel`/`Sună` și `WA`.
  - **Teste Pasul 4:** extins [tests/run_tests.php](file:///D:/Proiecte/ITP-Programari/tests/run_tests.php) (secțiunea `5b` pentru `P01`, `P03`, `P06` + fix verificări `=== null` cu `array_key_exists`); `php -l` & `node --check` (`0` erori), suita completă pe MariaDB izolat `acw_itp_step4_tmp` (`PASS`), Playwright Desktop `1280×800` și Mobil `375×812` cu `/api/**` mock-uit (`PASS`, `overflow=0px`, `0` erori JS).
- **Pasul 5 (`A02`, `A03`, `C03`, `D01`, `D02`, `D03`, `D04`, `T01`, `T02` — Administrare, Securitate & Reziliență Teren):**
  - **Bază de date & Autentificare ([004_status_finalizat.sql](file:///D:/Proiecte/ITP-Programari/modules/programari/migrations/004_status_finalizat.sql), [Auth.php](file:///D:/Proiecte/ITP-Programari/core/Auth.php), [AuthController.php](file:///D:/Proiecte/ITP-Programari/core/controllers/AuthController.php)):**
    - `T02`: migrare idempotentă `004_status_finalizat.sql` care extinde `appointments.status` la `ENUM('Programat', 'Revenire', 'Finalizat')`; adăugat `Auth::requireAnyPermission()`.
    - `A02`: `AuthController::listLoginUsers()` omite `username` și `is_primary_admin` din răspunsul public `GET /api/auth/users`.
  - **Backend ([SettingsService.php](file:///D:/Proiecte/ITP-Programari/core/SettingsService.php), [AppointmentRepo.php](file:///D:/Proiecte/ITP-Programari/modules/programari/AppointmentRepo.php), [AppointmentService.php](file:///D:/Proiecte/ITP-Programari/modules/programari/AppointmentService.php), [Controller.php](file:///D:/Proiecte/ITP-Programari/modules/programari/Controller.php)):**
    - `D01`: `SettingsService::findFutureAppointmentConflicts()` + `updateStationConfig()` verifică programările active cu `appt_date >= today` vs liniile, sloturile și zilele lucrătoare propuse, cerând `409 SETTINGS_IMPACT_CONFIRM_REQUIRED` fără `confirm_impact`.
    - `D02`: `AppointmentService::deleteReason()` face soft-delete (`is_active = 0`), iar `addReason()` reactivează rândurile arhivate cu același `(kind, label)` și păstrează istoricul.
    - `D04`: `AppointmentRepo::listHistoryEntries()` adaugă filtrare SQL directă pentru `Șterse`/`Intacte`, `LIMIT maxEntries * 5` (max `500` intrări grupate) și returnează `truncated` + `limit` (plus filtru `Finalizate`).
    - `T02`: ruta `POST /api/m/programari/appointments/{id}/finalizat` (`AppointmentService::setFinalizat()`) comută `Programat ↔ Finalizat`, consemnează eveniment `FINALIZAT`/`MODIFICAT` în `Istoric Imutabil` și blochează tranziția directă `Revenire ↔ Finalizat` (`409 IN_REVENIRE` / `409 ALREADY_FINALIZAT`).
  - **Frontend ([assets/js/api.js](file:///D:/Proiecte/ITP-Programari/assets/js/api.js), [assets/shell.html](file:///D:/Proiecte/ITP-Programari/assets/shell.html), [assets/js/app.js](file:///D:/Proiecte/ITP-Programari/assets/js/app.js)):**
    - `T01`: timeout de `15s` cu `AbortController` și mesaje clare (`TIMEOUT` / `NETWORK_ERROR`) în `api.js` + banner `#offlineBanner` în `shell.html` sincronizat cu evenimentele `online`/`offline`.
    - `A03`: badge `#mobHeaderUserBadge` în antetul mobil cu utilizatorul logat și comutare rapidă coleg (`requestLogout()`).
    - `C03`: trecere automată pe `today_date` la poll/trezire peste miezul nopții când `state.selectedDate === prevToday`.
    - `D01`, `D02`, `D03`, `D04`, `T02`: `Setări Stație` expune `work_days` (`L–D`) + `reinspection_days` și confirmă `409 SETTINGS_IMPACT_CONFIRM_REQUIRED`; `Motive` permite editare inline (`startEditReason`/`saveEditReason`), iar modalul `Revenire` afișează eventualele motive arhivate încă bifate cu `(arhivat)` pentru a putea fi debifate; `Istoric Imutabil` afișează bannerul pentru `limit=500` și filtrul `Finalizate`; agenda afișează statusul `Finalizat (Admis)` cu acțiunea `Admis` / `Revocă Admis` și filtru dedicat.
  - **Teste Pasul 5:** extins [tests/run_tests.php](file:///D:/Proiecte/ITP-Programari/tests/run_tests.php) (secțiunea `5c` pentru `A02`, `T02`, `D01`, `D02`, `D04`); `php -l` pe toate cele 7 fișiere PHP și `node --check` pe `api.js`/`app.js` (`0` erori), suita completă pe MariaDB izolat `acw_itp_step5_tmp` (`PASS`), plus Playwright Desktop `1280×800` și Mobil `375×812` pe pașii 4 și 5 cu `/api/**` mock-uit (`PASS`, `overflow=0px`, `0` erori JS neașteptate).
- **Egalizare & Ordine Stânga → Dreapta Butoane Agendă (Client UX Feedback):**
  - **CSS & JS ([assets/css/app.css](file:///D:/Proiecte/ITP-Programari/assets/css/app.css), [assets/js/app.js](file:///D:/Proiecte/ITP-Programari/assets/js/app.js)):**
    - Egalizat toate butoanele pe o singură linie la aceeași înălțime compactă (`27px` Desktop `.itp-desk-act`, `28px` Mobil `.itp-mob-act` / `×` `28×28px`), reducând padding-ul orizontal al tabelului `.itp-compact-table` pentru `0px` scroll intern la `1280px`.
    - Ordine stânga → dreapta (cele mai folosite → cele mai puțin folosite): `WhatsApp`/`WA` (verde `.itp-btn-wa`) → `Telefon`/`Sună` (albastru `.itp-btn-tel`) → `SMS` *(mobil, ascuns pe fix)* → `Admis` / `Revocă` → `+ Reprog.` / `Revenire` → `Editează` → `Șterge`/`×`.
  - **Teste:** `node --check assets/js/app.js` (`0` erori), capturi vizuale verificate pe Desktop `1280×800` și Mobil `375×812` + 3 suite Playwright (`/api/**` mock-uit): înălțimi uniforme pe aceeași linie Y (`27px` Desktop, `28px` Mobil), `0px` scroll pe pagină și pe `.table-responsive`, `0` text tăiat, plus regresii Pasul 4 & Pasul 5 (`PASS`, `0` erori JS).
- **Polish Vizual Mobil pe 4 Rezoluții (`320`, `360`, `375`, `390`):**
  - **HTML, CSS & JS ([assets/shell.html](file:///D:/Proiecte/ITP-Programari/assets/shell.html), [assets/css/app.css](file:///D:/Proiecte/ITP-Programari/assets/css/app.css), [assets/js/app.js](file:///D:/Proiecte/ITP-Programari/assets/js/app.js)):**
    - Modal `#modalProgramare`: `Data` `col-12 col-sm-5`, `Ora` `col-5 col-sm-3`, `Linie` `col-7 col-sm-4` astfel încât pe mobil `10:30` și `Linia 1 (3 lib.)` să fie vizibile integral fără tăiere sub săgeată.
    - Card `Revenire`: butonul `Motiv:` folosește `d-block` (aliniere la stânga + spațiu păstrat după `Motiv:`).
    - Filtre & căutare mobilă: `.filter-strip` trece pe `flex-wrap` (`filterScroll = 0px`, pill-urile `Revenire` și `Reveniri deschise` vizibile complet), `#itpTopbar` păstrează `#globalSearch` + `Toate zilele` pe 1 linie, iar `@media (max-width: 359.98px)` ajustează header-ul pentru încadrarea curată a meniului `☰` și pe `320px`.
  - **Teste:** `node --check assets/js/app.js`, inspecție vizuală Playwright pe `320×568`, `360×800`, `375×812`, `390×844` + modale + căutare (`docOverflow=0px`, `filterScroll=0px`, `0` text tăiat, `0` erori JS) și regresii `BUTTON HIERARCHY` / Pasul 4 / Pasul 5 (`PASS`).
- **Acțiuni Exclusiv Sigle SVG (Varianta 2) & Rând Filtre Mobil (`54 lib.` / `Liniile`):**
  - **CSS & JS ([assets/css/app.css](file:///D:/Proiecte/ITP-Programari/assets/css/app.css), [assets/js/app.js](file:///D:/Proiecte/ITP-Programari/assets/js/app.js)):**
    - Introdus dicționarul `actionIcons` (SVG vector inline, offline-ready, `aria-label` + `title` explicite) pe toate butoanele de acțiune din tabelul desktop (`27px` înălțime, `40px`/`32px` lățime) și de pe cardurile mobile (`28px` înălțime, 1 singură linie inclusiv pe `320px`), păstrând ordinea stânga → dreapta (`WhatsApp` → `Telefon` → `SMS` *(mobil)* → `Admis`/`Revocă` → `Reprogramează`/`Revenire` → `Editează` → `Șterge`).
    - Corectat blocul filtrelor mobile: `.itp-filter-date-group` păstrează `[<] [Dată] [>] [Azi]` + badge-ul **`N lib.`** pe același rând (`flex-nowrap`, `min-width: 108px`), iar `.filter-strip { display: contents; }` pe mobil așază selectorul `Liniile` natural împreună cu pastilele de status (`filterScroll = 0px`).
  - **Teste:** `node --check assets/js/app.js` (`0` erori) + inspecție vizuală și suite Playwright (`320×568`, `360×800`, `375×812`, `390×844`, `1280×800` cu `/api/**` mock-uit): `docOverflow=0px`, `filterScroll=0px`, `freeSameRow=True`, `sameLine=True` pe toate cardurile inclusiv `320px`, plus `BUTTON HIERARCHY`, Pasul 4 & Pasul 5 (`PASS`, `0` erori JS).
- **Customizare butoane navigare calendar stânga/dreapta (Model B — Accent Albastru Soft):**
  - Înlocuit glifele `&lsaquo;` / `&rsaquo;` și clasele `.btn-outline-secondary.touch-btn` de pe butoanele `.itp-date-btn` din `renderAziView()` (`assets/js/app.js`) cu pictograme SVG `18×18px` (`stroke-width="2.5"`, `polyline` stânga/dreapta) și stil dedicated **Model B** în `assets/css/app.css` (`#eff6ff` fundal, `#bfdbfe` bordură, `#1d4ed8` icon, `border-radius: 8px`, stări `:hover` / `:focus-visible` `#dbeafe` și `:active` `#bfdbfe`).
  - Dimensiuni și aliniere uniformă cu `#agendaDateText` și butonul `Azi` (`.itp-azi-btn`): `38×32px` pe desktop, `38×34px` pe mobil (`@media (max-width: 991.98px)`), respectiv `34×34px` + padding compact pe `#agendaDateText` / `Azi` sub `360px` (`@media (max-width: 359.98px)`) astfel încât `26/11/2026` și badge-ul `N lib.` să rămână complete pe un singur rând fără suprapunere sau overflow.
  - **Teste:** `node --check assets/js/app.js` (`0` erori) + verificare vizuală pe `1280×800`, `375×812`, `320×568` și suite Playwright (`320×568`, `360×800`, `375×812`, `390×844`, `1280×800`): `docOverflow=0px`, `filterScroll=0px`, `freeSameRow=True`, `sameLine=True`, `clip=False`, `BUTTON HIERARCHY`, Pasul 4 & Pasul 5 (`PASS`, `0` erori JS).
- **Istoric Imutabil — filtre cronologice (`Azi` / `7 zile` / `Toate` / `Interval`) și paginare (10 rezultate/pagină):**
  - **Backend (`modules/programari/Controller.php`, `AppointmentService.php`, `AppointmentRepo.php`):**
    - Extins `GET /api/m/programari/history` și `GET /api/m/programari/history.csv` cu parametrii opționali `date_from`, `date_to` (`YYYY-MM-DD`, cu interschimbare automată dacă `date_from > date_to`), `page` și `per_page` (`0` pentru CSV și apeluri legacy fără paginare).
    - Filtrare SQL prin `EXISTS (SELECT 1 FROM appointment_history hx ...)` astfel încât fiecare programare activă în interval își păstrează cronologia completă și filtrul `Intacte` rămâne exact; răspunsul include `total`, `page`, `per_page`, `total_pages`.
  - **Frontend (`assets/js/app.js`, `assets/css/app.css`):**
    - Deschidere implicită pe preset-ul `Azi` cu comutare rapidă între `Azi`, `7 zile`, `Toate` și `Interval` (`De la – Până la` format `zz/ll/aaaa`), plus butoane rapide în starea goală (`Vezi ultimele 7 zile` / `Vezi tot istoricul`).
    - Paginare `10` programări/pagină (`renderHistoryPagination`, `.itp-page-btn` în stil Model B), contor `Afișate X–Y din Z programări`, reset automat la pagina `1` la schimbarea oricărui filtru/căutări, `Export CSV` sincronizat cu `date_from`/`date_to` și `padding-bottom: 84px` / `scroll-margin-bottom: 80px` pe mobil pentru eliberarea barei de navigație fixă de jos.
  - **Teste:** `php -l` (`Controller.php`, `AppointmentService.php`, `AppointmentRepo.php`) + `node --check assets/js/app.js` + `test_playwright_history.py` (`320×568`, `375×812`, `1280×800`: `overflow=0px`, `Azi->p2->Toate->7zile->Interval->Finalizate`, `CSV synced`, `jsErr=0`) + `mobile_visual_audit.py`, Pasul 4 & Pasul 5 (`PASS`).
- **Tema Light cald (Varianta 1, feedback client iPhone 17 Pro Max: „E cam mult alb”):**
  - Doar fundaluri/interioare, fara modificari la butoane, dimensiuni sau layout: `--itp-bg: #e7dfd5`, `--itp-cream: #f5f0e8` (sidebar, topbar, bara mobil jos), `--itp-cream-card: #faf7f2` (`.card`, `.modal-content`).
  - Clase noi `itp-tone-final` (verde menta `#e3f7df`) si `itp-tone-rev` (lavanda `#f9f1fb`) puse prin `toneCls` din `buildRowMeta` pe randurile desktop si cardurile mobile.
  - **Teste:** `node --check` + Playwright (audit mobil 320/360/375/390, desktop 1280, BUTTON HIERARCHY, Pasul 4, Pasul 5, Istoric): toate `PASS`, `overflow=0px`, `0` erori JS; verificat vizual `current_desktop.png` / `current_mobile.png`.
- **Spatiu redus deasupra titlului pe telefon (<= 991.98px):** `.page-body` margin-top `0.4rem`, `#pageHeaderBar` margin-bottom `0.35rem`, `#viewTitle` `1.15rem`. Cardurile de programari urca cu ~25px (ex. 375x812: `y=325` -> `y=300`). Teste: audit mobil 320-390, Pasul 4, Pasul 5, Istoric: `PASS`, `overflow=0px`, `0` erori JS.
- **Culoare carduri/randuri „Programat” (Varianta A — albastru ceata, feedback client „programarile au ramas albe”):** `toneCls` primeste `itp-tone-prog` pentru starile implicite (nu Finalizat/Revenire/Revenire prog.); CSS: `--itp-tone-prog-bg: #e6f0fb`, `--itp-tone-prog-border: #b9d2ef`; randurile/cardurile din sectiunea „Programari in viitor” isi pastreaza stilul propriu. Butoanele nu au fost modificate. Teste: `node --check` + audit mobil 320-390, desktop 1280, BUTTON HIERARCHY, Pasul 4, Pasul 5, Istoric: `PASS`, `overflow=0px`, `0` erori JS; verificat vizual.
- **Accent linie `Masina • Client • Telefon` si `• L1` pe cardul mobil (Varianta A, feedback client):** clase noi `.itp-mob-meta` (`#1e293b`, `font-weight: 500`, `0.81rem`) si `.itp-mob-line` (`#1e293b`, `500`, `0.78rem`) in locul `text-muted`; butoanele nu au fost modificate. Teste: `node --check` + audit mobil 320-390, desktop 1280, BUTTON HIERARCHY, Pasul 4, Pasul 5, Istoric: `PASS`, `overflow=0px`, `0` erori JS; verificat vizual.
- **Accent subtitlu header (`Joi, 26 noiembrie 2026 • Laur (Admin)`):** `#pageHeaderBar #viewSubtitle` -> `color: #1e293b`, `font-weight: 500` (Varianta A, ca la linia masina/client). Doar CSS. Teste: audit mobil 320-390, desktop 1280, Pasul 4, Istoric: `PASS`, `overflow=0px`, `0` erori JS.
- **Buton `+ Programare` (`#btnNewAppt` / `#btnNewApptMob`) — Varianta A (albastru inchis, bold):** doar CSS, cu variabilele `--itp-newappt-bg: #1e40af`, `--itp-newappt-border: #1e3a8a`, `--itp-newappt-shadow` in `assets/css/app.css`. **Varianta B (rosu ACW)** se activeaza schimband doar aceste 3 variabile in `#dc2626` / `#b91c1c` / `rgba(185, 28, 28, 0.35)` (la cererea clientului). Teste: audit mobil 320-390, desktop 1280, BUTTON HIERARCHY, Pasul 4, Pasul 5, Istoric: `PASS`, `overflow=0px`, `0` erori JS; verificat vizual.
