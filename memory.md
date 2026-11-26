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
  - Delimitare stabilită pentru cele 4 categorii operaționale fără dublare: `Programări` (agenda activă), `Istoric Imutabil` (audit protejat), `Clienți & Vehicule` (fișă permanentă per mașină cu ultima vizită/scadență), `Expirări ITP` (coadă de contactare pentru scadențe fără programare viitoare).
