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
| **Tip aplicație** | Localhost + acces rețea locală (LAN/Wi-Fi), offline-first |
| **Arhitectură** | Modulară: Bază (`core/`) + module independente (`modules/<id>/`) |
| **Backend** | Python 3.14 + FastAPI + Uvicorn + SQLite (`data/itp.db`) |
| **Frontend** | HTML5 + CSS3 + JavaScript curat, responsive (PC + telefon) |
| **Aspect UI** | Modern luminos (fără build step, librării UI salvate local) |
| **Utilizatori & Acces** | Administrator (drepturi depline) + Utilizatori restrânși (permisiuni granulare per modul, verificate pe server) |
| **Audit Log** | Esențial, centralizat în Bază, imutabil (*append-only*), before/after + IP + export Admin |
| **Stadiu cod** | Doar proiectare, analiză și referințe — fără cod de producție încă |

---

## 4. Decizii Deschise (de discutat în următoarea sesiune)

1. **Modelul exact de permisiuni:** profiluri predefinite (ex. `Administrator`, `Inspector ITP`, `Recepție`) cu posibilitate de ajustare per utilizator, sau doar `Administrator` + `Utilizator` cu bife per modul; dacă utilizatorii restrânși pot edita programările create de colegi.
2. **Dimensionarea și fluxul stației:** câți utilizatori simultani, câte linii ITP, durata standard a unui slot (ex. 30 min).
3. **Prioritizarea și extinderea modulelor:** ordinea exactă pentru `clienti-vehicule`, `programari`, `inspectii`, `expirari-itp`, `tarife-incasari` și eventuale module extra (poze/documente din camera telefonului, flote/firme, cheltuieli stație).
4. **Pornire pe PC-ul stației:** manual prin `start.bat` sau automat la pornirea Windows.
5. **Proiectele personale ale utilizatorului pe GitHub:** adăugarea link-urilor în `references/my-projects/` pentru analiză comparativă.

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
