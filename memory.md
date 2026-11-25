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
   - Multi-utilizator: un **Administrator** cu drepturi depline și **utilizatori** cu drepturi restrânse.
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
| **Dimensionare stație** | **3 linii ITP (`Linia 1`, `Linia 2`, `Linia 3`)** și **slot de 30 minute**, cu blocaj strict anti-suprapunere |
| **Backend & DB** | Python 3.14 + FastAPI + Uvicorn + SQLite WAL (`sqlite3` + migrări `.sql` per modul); reutilizare utilitare/pattern-uri testate din `D:\Proiecte\ITP-Sentinel` (backup WAL, `bcrypt`, CSRF, rate-limit, validări RO) |
| **Frontend & UI** | HTML5 + CSS3 + JS + Tabler UI salvat local (`references/ui-libs/`), exclusiv **Temă Deschisă (Light Theme)**, responsive PC + telefon |
| **Utilizatori & Acces** | Administrator (drepturi depline) + Utilizatori restrânși (permisiuni granulare per modul verificate pe server; `First-Start` strict local fără proxy) |
| **Audit Log** | Esențial, centralizat în Bază, imutabil (*append-only*), before/after + IP real (inclusiv prin `cloudflared`), filtrabil după utilizator/acțiune + export CSV |
| **Stadiu cod** | Doar proiectare, plan, memorie și machetă `dashboard.html` — fără cod de producție încă |

---

## 4. Decizii Rămase Deschise (Detalii Operaționale)

1. **Programul orar al stației:** intervalul exact de programări pe zi (ex. `08:00–18:00`) și zilele lucrătoare.
2. **Editarea programărilor colegilor:** dacă utilizatorii restrânși pot edita programările create de alți colegi sau doar ștergerea rămâne la Admin.
3. **Pornirea pe PC-ul stației:** manual prin `start.bat` sau automat la pornirea Windows.

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
benavlabs/crudadmin — Admin HTML pentru FastAPI + HTMX, cu autentificare pe sesiune/cookie, RBAC (admin vs. utilizatori restrânși) și audit log (jurnal de acțiuni). Foarte bun ca referință pentru securitate, sesiuni și audit.
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
  - Include comutator demo **Administrator vs. Utilizator restrâns**, modal simplu `+ Programare` în 4 câmpuri cu verificare suprapuneri, **Audit Log live** cu filtrare după utilizator și acțiune + export CSV și comutator activare/dezactivare **Module**.
  - **Ajustare UI/UX & Aliniere Faza 1 (seară):**
    - Eliminat blocul `Ultimele evenimente Audit Log` din pagina `Programările de Azi` pentru a economisi spațiu vertical (logurile rămân exclusiv în secțiunea dedicată `Audit Log`), și adăugată filtrarea după **utilizatori** (selector cu contor evenimente + resetare filtre + export CSV al listei filtrate).
    - Confirmat scopul Fazei 1: **strict modulul `programari`** (**3 linii ITP `Linia 1–3`**, **slot 30 min**, blocaj strict anti-suprapunere, **vedere unificată `Programări` cu butoane `‹ / ›` și selector de dată integrate** + tab **`Istoric Imutabil`** append-only care păstrează intactă varianta inițială la orice editare/ștergere), restul modulelor urmând să fie adăugate modular în Etapa 2.
    - Ajustare machetă `dashboard.html`: utilizatori demo dedicați în antet și în secțiunea `Utilizatori & Drepturi` (**`Laud (Admin)`** cu drepturi depline, **`Vali (Restrâns)`** și **`Gabi (Restrâns)`** cu drepturi restrânse; utilizatorul activ evidențiat bold în antet și trasabil în `Istoric Imutabil` + `Audit Log`), butoane coerente (`Editează`, `Revenire` sau `+ Reprogramează` fără buton duplicat `Motiv` — motivul se editează cu click direct pe etichetă), tab **`Istoric Imutabil`** read-only (exemple live `CJ 22 MAX` modificat + `B 888 VEC` șters din agendă cu snapshot-ul inițial păstrat intact), statusuri `Programat` și `Revenire`, fără `Categorie` și **layout ultra-compact desktop + telefon**.
    - **Ecran de Login demo (`#loginScreen`, Tabler Light) + Gestiune Conturi & Blocare (`Utilizatori & Drepturi`):** pornește cu autentificare (`user: admin` / `parolă: admin` → `Laud`), include butoane dinamice de acces rapid, reconfirmare la `Deconectare`, plus **`+ Adaugă utilizator`**, **`Editează`** (parolă + permisiuni granulare `Editare`, `Revenire`, `Ștergere`) și **`Blochează / Activează`** cu blocaj efectiv pe ecranul de Login.
    - Verificare **duplicare nr. auto în aceeași zi** (`normalizePlateKey` / `formatRoPlate` — ex. `b458itp` / `B-458-ITP` = `B 458 ITP`) cu **notificare live în formular** (`#duplicateLiveAlert` imediat la tastarea numărului/schimbarea datei) + dialog de reconfirmare (`#modalConfirm`, `warning`) la creare și editare.
    - Regula de **reconfirmare obligatorie (`#modalConfirm`)**: aplicată uniform în `dashboard.html` pentru `Șterge` programare, salvarea modificărilor din `Editează` (cu listă clară a câmpurilor modificate și revenire pe form la `Renunță`), activare/dezactivare `Module`, `Backup` și status `Expirări`.
    - Eliminat tab-ul separat `Audit Log` din UI (`dashboard.html`) pentru simplitate maximă (rămân 4 tab-uri curate pe mobil: `Programări`, `Istoric`, `Mașini`, `Expirări`); trasabilitatea s-a mutat integral în **`Istoric Imutabil`** (scenarii demo complete cu `Laud`, `Vali`, `Gabi`: editări multiple `CJ 22 MAX`, ștergere `B 888 VEC`, revenire + reprogramare `B 305 TAX` pe `26.11`, mutare linie `AG 18 VTR`, filtru pe utilizator + `Export CSV`), iar în backend rămâne doar un log intern SQLite de securitate/backup.
    - Confirmată strategia de livrare: `localhost` + LAN + **`cloudflared.exe`** pentru demo live către beneficiar, apoi migrare pe domeniu + găzduire; actualizate `PLAN.md`, `memory.md` și `dashboard.html` corespunzător.
