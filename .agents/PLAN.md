# Plan Aplicație Locală Stație ITP (Arhitectură Modulară)

## 1. Obiectiv și Cerințe Principale

- **Aplicație locală (`localhost`) cu acces în rețeaua locală (LAN/Wi-Fi), expunere demo prin `cloudflared.exe` și pregătită pentru domeniu + găzduire:**
  - Rulează pe PC-ul principal al stației ITP și poate fi accesată simultan de pe mai multe dispozitive (PC, laptop, telefon, tabletă) conectate la același router/Wi-Fi, fără costuri de hosting.
  - În prima fază, aplicația poate fi expusă securizat către beneficiarul proiectului prin **`cloudflared.exe`** (Cloudflare Tunnel HTTPS) pentru verificarea progresului în timp real.
  - Arhitectura folosește rute 100% relative și configurare prin `.env`, astfel încât mutarea ulterioară pe un **domeniu propriu și găzduire (VPS/cloud)** să se facă fără rescrierea codului.
- **Livrare incrementală (Faza 1 strict `Programări`):**
  - Prima versiune operațională acoperă **exclusiv Baza (Core) + Modulul `programari`** (**3 linii ITP** implicite `Linia 1`, `Linia 2`, `Linia 3`, program **Luni – Sâmbătă, `08:00–17:30`**, durată slot **30 min**, blocaj strict anti-suprapunere).
  - Modulele ulterioare (`clienti-vehicule`, `inspectii`, `expirari-itp`, `tarife-incasari` etc.) se adaugă independent peste Bază în **Etapa 2**, fără a modifica nucleul.
- **Frontend HTML5 + CSS3 + JavaScript, Responsive (PC + Telefon):**
  - **Aspect UI:** **exclusiv modern luminos (Light Theme)** (paletă clară, contrast ridicat, lizibilitate foarte bună în lumină puternică la birou sau pe hala de inspecție).
  - **Pe PC:** meniu lateral fix (Sidebar), bară superioară, tabele detaliate și filtre pe un singur ecran.
  - **Pe Telefon:** meniu inferior / sertar lateral, carduri ușor de citit și butoane mari pentru operare cu o singură mână.
- **Multi-utilizator cu niveluri de acces și Istoric Imutabil:**
  - **Administrator (`Laur`):** drepturi depline asupra tuturor modulelor, utilizatorilor, setărilor, exportului și backup-urilor.
  - **Utilizatori (`Vlad`, `Gabi`):** acces controlat granular per modul și acțiune; cei cu drept de `Editare` pot modifica **orice programare** din agendă (inclusiv cele create de colegi).
  - **Istoric Imutabil al programărilor (trasabilitate completă):** orice creare, editare (oră/linie/dată/client), marcare `Revenire`, revocare, reprogramare 30 zile sau ștergere din agendă păstrează **starea inițială intactă** + evenimentele ulterioare cu utilizatorul activ (tabel `appointment_history` append-only în SQLite cu `TRIGGER`-e de blocare `UPDATE`/`DELETE`), încărcat implicit pe ultimele **30–60 de zile** (cu căutare globală pe tot istoricul după nr. înmatriculare) și completat în spate de `core_audit_log` accesibil discret doar Administratorului (`Jurnal securitate / Admin`), fără tab separat în meniul zilnic.

---

## 2. Arhitectură Tehnică

- **Acces în rețea și tunnel:**
  - Pe PC-ul gazdă: `http://localhost:8000`
  - De pe telefoane/tablete din același Wi-Fi: `http://<IP-LOCAL-PC>:8000` (ex. `http://192.168.1.50:8000`)
  - Demo extern către beneficiar (opțional, cât timp PC-ul este pornit): `https://<subdomeniu>.trycloudflare.com` via `cloudflared.exe tunnel --url http://localhost:8000`
  - Ulterior (producție cloud): domeniu propriu + HTTPS prin reverse-proxy, folosind aceleași rute relative și variabile `.env`.
- **Backend (confirmat):**
  - **Python 3.14 + FastAPI + Uvicorn + SQLite (`sqlite3` integrat în mod WAL)** — server rapid, ușor de pornit local, cu separare curată pe `APIRouter` per modul.
  - Reutilizare componente verificate din `D:\Proiecte\ITP-Sentinel`: configurare WAL + backup consistent `sqlite3.Connection.backup()` cu `PRAGMA integrity_check`, hashing `bcrypt`, CSRF, rate-limiting login, protecție `First-Start` strict local și normalizare plăcuțe/telefon România.
- **Frontend:**
  - **HTML5 + CSS3 + JavaScript + Tabler UI offline** (`references/ui-libs/`), servit direct de FastAPI din `/static/` (fără Node.js și fără pași de build).
- **Bază de date:**
  - **SQLite WAL** într-un singur fișier local (`data/itp.db`), cu sistem automat de migrări `.sql` per modul și backup prin copiere consistentă la cald.
- **Securitate, Sesiuni și Proxy (`cloudflared` / Hosting):**
  - **Autentificare prin selecție utilizator + PIN numeric (4–6 cifre)** (model rapid POS/atelier, cu tastatură numerică touch-friendly pe telefon/tabletă).
  - **Dual-PIN pentru Administratorul principal (`Laur`):**
    1. **PIN Uzual (4–6 cifre):** utilizat zi de zi (programări, reveniri, ștergeri, motive, export, backup).
    2. **PIN Secret / Master Recovery (8–12 cifre):** dedicat strict pentru **preluarea comenzii și recuperare** (`PIN Secret Admin (Preia controlul)` pe ecranul de Login) și protejarea schimbării propriului PIN Secret (nu poate fi văzut în UI și nu poate fi modificat cu PIN-ul uzual). La introducerea PIN-ului Secret valid, backend-ul incrementează `session_version` (delogând instant toate celelalte sesiuni active ale Adminului), invalidează vechiul PIN uzual, obligă setarea unui PIN uzual nou și scrie evenimentul critic `ADMIN_MASTER_RECOVERY` în `core_audit_log`.
  - PIN-urile (uzuale și Master) sunt stocate exclusiv ca hash `bcrypt` în SQLite.
  - Sesiuni autentificate prin cookie `HttpOnly` (cu flag `Secure` activat automat când cererea vine pe HTTPS / `X-Forwarded-Proto: https`) + protecție CSRF și rate-limiting per utilizator/IP la introducerea PIN-ului.
  - **First-Start / Setup inițial permis exclusiv local fără proxy** (blocat dacă există headere `CF-Connecting-IP` / `X-Forwarded-For`), pentru ca nimeni din internet să nu poată prelua inițializarea prin `cloudflared`.
  - Extragere controlată a IP-ului real (`CF-Connecting-IP` / `X-Forwarded-For`) doar pentru cererile venite de pe proxy local de încredere, pentru acuratețea Audit Log-ului.
  - Verificarea rolului și a permisiunilor se face **strict pe server (backend)** la fiecare cerere API.

---

## 3. Baza (Core / Platforma — Faza 0)

Baza nu conține logică de programări sau clienți; este platforma peste care rulează toate modulele:

1. **Server Local LAN & Pornire Rapidă**
   - Server HTTP local configurat să asculte pe rețeaua locală (`0.0.0.0:PORT`) și script de pornire simplă cu dublu-click (`start.bat`).
2. **Motor de Module (Module Loader / Registry)**
   - La pornire, Baza scanează automat directorul `modules/`.
   - Pentru fiecare modul valid, citește `module.json`, rulează migrările SQL neaplicate ale modulului, montează rutele API (`/api/m/<id-modul>/...`) și îi înregistrează intrările în meniu și permisiunile.
   - Administratorul poate **activa / dezactiva module** din panoul Admin.
3. **Bază de Date Core & Migrări (`data/itp.db`)**
   - `_migrations` — evidența migrărilor rulate pentru Core și pentru fiecare modul.
   - `users` — conturi utilizatori, `pin_hash` (`bcrypt`), `master_pin_hash` (`bcrypt`, doar pentru Admin principal), `session_version` (invalidare instantanee a sesiunilor la preluare control), rol (`admin` / `user`), status activ/blocat.
   - `user_permissions` — permisiuni granulare per modul pentru utilizatori.
   - `modules_state` — starea activ/inactiv a modulelor descoperite.
   - `settings` — configurări generale ale stației ITP (nume stație, adresă, telefon, program **Luni–Sâmbătă `08:00–17:30`**, linii ITP configurabile — implicit `Linia 1`, `Linia 2`, `Linia 3`, durată slot — implicit `30 min`).
   - `core_audit_log` — jurnal intern de securitate și administrare **imutabil (append-only cu `TRIGGER`-e SQLite `BEFORE UPDATE`/`DELETE`)**: `timestamp`, `user_id`/`username`, `module_id`, `action` (`LOGIN_OK`, `LOGIN_FAIL`, `LOGOUT`, `USER_CREATE`, `USER_UPDATE`, `PERMISSION_CHANGE`, `USER_BLOCK`, `REASON_CHANGE`, `MODULE_TOGGLE`, `SETTINGS_UPDATE`, `EXPORT`, `BACKUP`), `entity_type`, `entity_id`, `before_json`, `after_json`, `ip_address`, `user_agent`.
4. **Jurnal Securitate & Administrare (`core_audit_log`)**
   - Politică strictă **append-only**: intrările din `core_audit_log` sunt blocate la editare/ștergere direct în SQLite prin `TRIGGER`.
   - Orice modul poate emite evenimente de audit printr-un helper central (`core.audit.log_event(...)`) în aceeași tranzacție cu operația.
   - În UI nu ocupă un tab separat în meniul zilnic; este accesibil exclusiv **Administratorului** printr-un buton discret **`Jurnal securitate / Admin`** (în `Utilizatori` / `Istoric`), cu filtre și **Export CSV**.
5. **Roluri și Permisiuni**
   - **Administrator:** acces complet automat pe toate modulele + Panou Admin (`Utilizatori`, `Permisiuni per modul`, `Activare/Dezactivare Module`, `Setări stație`, `Jurnal activitate / Audit Log`, `Backup DB`).
   - **Utilizator:** acces configurabil per modul (`view`, `create`, `edit`, `delete`), plus un profil implicit „Utilizator standard” pentru creare rapidă de conturi.
6. **App Shell Responsive (UI Modern Luminos)**
   - Layout adaptiv PC (sidebar) și telefon (meniu inferior / sertar mobil + carduri).
   - Meniu generat dinamic din modulele active la care utilizatorul curent are drepturi.
   - Dashboard modular: colectează automat widget-uri (carduri rezumat) expuse de modulele active.
   - Kit UI reutilizabil inclus în Bază (formulare, tabele/carduri responsive, modale, notificări toast, dialoguri de confirmare) astfel încât toate modulele să păstreze același design luminos unitar.
7. **Backup Bază de Date**

- Descărcare directă a fișierului `itp.db` din panoul Admin și salvare de copii consistente la cald (`sqlite3.Connection.backup()` + `PRAGMA integrity_check` + rotație automată) în folderul `backups/`.

---

## 4. Standardul unui Modul (Contractul cu Baza)

Fiecare modul nou este izolat în propriul director și respectă aceeași structură standard:

```text
d:\ITP programari\
├── core/                        # BAZA (platforma comună)
│   ├── app.py                   # Aplicația FastAPI, middleware auth & permisiuni
│   ├── db.py                    # Conexiune SQLite + runner migrări
│   ├── module_loader.py         # Descoperire automată module/*/module.json, migrări, montare APIRouter
│   ├── routes/                  # auth.py, admin.py (utilizatori, permisiuni, module, setări, audit, backup)
│   └── static/                  # App Shell HTML/CSS/JS responsive (UI modern luminos) + kit UI
├── modules/                     # MODULELE (adăugate incremental peste Bază)
│   ├── <id-modul>/
│   │   ├── module.json          # Manifest: id, name, icon, version, order, dependsOn, permissions, menu, widget
│   │   ├── migrations/          # Scripturi SQL proprii (ex. 001_init.sql)
│   │   ├── router.py            # APIRouter propriu al modulului (/api/m/<id-modul>/...)
│   │   └── frontend/            # Interfața HTML/JS/CSS a modulului
├── data/
│   └── itp.db                   # Baza de date locală SQLite
├── backups/                     # Copii de siguranță ale bazei de date
├── requirements.txt             # Dependențe Python (fastapi, uvicorn etc.)
└── start.bat                    # Script de pornire cu .venv și Uvicorn pe 0.0.0.0:8000
```

### Ce declară `module.json`

- `id`, `name`, `icon`, `version`, `order` — identificare și poziție în meniu.
- `dependsOn` — dependențe opționale față de alte module (ex. `programari` se poate lega de `clienti-vehicule`).
- `permissions` — lista permisiunilor declarate de modul (ex. `programari.view`, `programari.create`, `programari.edit`, `programari.delete`), afișate automat în panoul Admin.
- `menu` — ruta și eticheta din navigație.
- `widget` — configurație opțională pentru cardul afișat pe Dashboard-ul principal.

---

## 5. Roadmap / Ordinea Modulelor

1. **Faza 0 – Baza (Core)**
   - Platforma modulară completă: server LAN + suport `cloudflared.exe` / domeniu viitor, SQLite WAL + migrări `.sql`, login `bcrypt` + cookie `HttpOnly` + CSRF + rate-limit, `First-Start` strict local, roluri & permisiuni per modul, App Shell UI modern luminos PC + telefon, **jurnal tehnic intern SQLite** (login, permisiuni, backup) și panou Admin (Module, Utilizatori, Setări stație, Backup consistent `sqlite3.Connection.backup()`), fără tab separat de Audit Log în meniu (trasabilitatea operațională este centralizată în **`Istoric Imutabil`** cu filtru după utilizator și export CSV).
2. **Faza 1 (Livrarea Inițială MVP) – Modul 1: `programari`**
   - Aplicația pornește concentrată **strict pe programări**:
     - Configurare implicită: **3 linii ITP (`Linia 1`, `Linia 2`, `Linia 3`)**, program **Luni – Sâmbătă (`08:00–17:30`)**, durată slot **30 min** (extensibil din Setări stație fără modificări de cod).
     - **Editare colaborativă:** utilizatorii cu permisiune de `Editare` (`Vlad`, `Gabi`) pot modifica **orice programare** din agendă, nu doar pe cele create de ei.
     - **Blocaj strict anti-suprapunere & soft-delete:** `appointments` folosește soft-delete (`deleted_at`) + index unic parțial `CREATE UNIQUE INDEX idx_appt_slot ON appointments(date, time, line) WHERE deleted_at IS NULL;` — garantat tranzacțional pe server chiar și la acces simultan de pe mai multe telefoane, păstrând intacte referințele în istoric. Verificare duplicare nr. auto în aceeași zi (`b458itp` = `B 458 ITP`) cu avertizare live și reconfirmare.
     - **Operare ultra-rapidă prin bife (1-tap chips) & reconfirmare (`#modalConfirm`):** atât motivele de **`Revenire 30 zile`**, cât și motivele de **`Ștergere / Anulare / Neprezentare`** folosesc **butoane-bifă rapide (1 click/tap)** (în loc de liste dropdown lente) + detaliu opțional. Nicio acțiune destructivă sau sensibilă (`Șterge` programare, `Editează` cu rezumatul modificărilor, `Revocă revenirea`, depășire termen `30 zile`, comutare `Module`/`Utilizatori`, status `Contactat`, `Backup`, `Deconectare`) nu se execută dintr-un singur click accidental.
     - Vedere unificată **`Programări`** cu navigator pe zile (`‹`, dată calendaristică, `›`, comutator `Caută în toate zilele`; modificare pe zile trecute doar Admin), acțiuni coerente (`Editează`, `Revenire` / `+ Reprogramează`, editare motiv prin click pe etichetă, `Sună`, `WA`, `Șterge`), secțiune dedicată Admin **`Motive`** (adăugare/editare/ștergere motive salvate pentru `Revenire 30 zile` și `Ștergere/Anulare`), plus tab dedicat **`Istoric Imutabil`** (tabel `appointment_history` append-only în SQLite cu `TRIGGER`-e `BEFORE UPDATE`/`DELETE`, scris **în aceeași tranzacție** cu orice acțiune pe `appointments`, păstrând permanent `initial_snapshot` intact, cu afișare implicită pe ultimele 30–60 zile și căutare globală după nr. auto pe tot istoricul). Fără câmp de `Categorie` pe formularul de programare (Data, Ora, Linie, Nr. auto, Telefon, Client/Mașină).
3. **Etapa 2 (Module viitoare adăugate incremental peste Bază)**
   - **Modul 2 – `clienti-vehicule`:** registru clienți și vehicule, istoric complet per număr de înmatriculare / serie șasiu.
   - **Modul 3 – `inspectii`:** rezultat ITP (`Admis`, `Respins`, `Revenire 30 zile`), inspector, calcul automat data următoarei expirări (6 luni / 1 an / 2 ani).
   - **Modul 4 – `expirari-itp`:** listă filtrabilă 7 / 15 / 30 zile, status „Client contactat”, mesaj precompletat WhatsApp/SMS.
   - **Modul 5 – `tarife-incasari` (doar Admin):** configurare tarife pe categorii, evidență încasări, rapoarte și export CSV/Excel.
   - **Opționale:** `flote-firme`, `documente-poze` (poze din camera telefonului), `cheltuieli-statie`.

---

## 6. Decizii Operaționale Confirmate (Pregătit pentru Faza 0)

1. **Programul orar al stației:** **Luni – Sâmbătă**, interval implicit `08:00–17:30` (sloturi de 30 min pe 3 linii, configurabil ulterior din Setări).
2. **Editarea programărilor colegilor:** utilizatorii cu drept de `Editare` (`Vlad`, `Gabi`) pot modifica **orice programare** din agendă; ștergerea rămâne separată prin permisiunea dedicată (`Ștergere`).
3. **Pornirea pe PC-ul stației:** variantă temporară de lucru local prin `start.bat` (după modelul `ITP-Sentinel/start_server.bat`) până la trecerea pe găzduire + domeniu propriu.
4. **Modelul de Istoric & Audit:** tab curat **`Istoric Imutabil`** dedicat programărilor (30–60 zile implicit + căutare globală după nr. auto, cu `TRIGGER` SQLite anti-modificare) + **`core_audit_log`** silențios accesibil exclusiv Adminului printr-un buton discret **`Jurnal securitate / Admin`**.
5. **Operare rapidă motive:** motivele de **`Revenire`** și **`Ștergere / Anulare / Neprezentare`** sunt implementate ca **butoane-bifă rapide (1 click/tap)** pentru viteză pe telefon și PC.
6. **Autentificare rapidă cu PIN & Dual-PIN Admin:** utilizatorul își alege profilul din ecranul de Login (`Laur`, `Vlad`, `Gabi` etc.) și introduce PIN-ul numeric uzual (4–6 cifre, inclusiv prin tastatură numerică pe ecran); Administratorul principal (`Laur`) are în plus un **PIN Secret (8–12 cifre)** strict pentru recuperare și preluare control (deloghează toate sesiunile vechi + resetează PIN-ul uzual), iar ca ultimă rezervă există script fizic pe server (`python -m core.reset_admin_pin`).
7. **Găzduire 24/7 pe VPS Linux (România) cu Docker & Caddy HTTPS:** aplicația + baza SQLite WAL (`./data` și `./backups` montate persistent pe NVMe local) vor rula 24/7 într-un container Docker (`--workers 1`) în spatele lui Caddy cu SSL automat pe domeniul stației și `robots.txt` (`Disallow: /`), păstrând `start.bat` pentru testare/pornire locală.

---

## 7. Referințe și Memorie de Proiect

- [IMPLEMENTARE.md](IMPLEMENTARE.md) — pașii detaliați de implementare Faza 0 (Core) + Faza 1 (programari), cu schema SQL, endpointuri, reguli, teste și livrare.
- [references/README.md](references/README.md) — indexul proiectelor de referință descărcate (arhitectură modulară FastAPI, RBAC, Audit Log, domeniu auto/inspecții și biblioteci UI offline).
- [memory.md](memory.md) — jurnalul complet al discuțiilor, deciziilor luate și punctelor deschise pentru reluarea ușoară a proiectării și dezvoltării de acasă.
