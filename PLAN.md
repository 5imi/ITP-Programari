# Plan Aplicație Locală Stație ITP (Arhitectură Modulară)

## 1. Obiectiv și Cerințe Principale

- **Aplicație locală (`localhost`) cu acces în rețeaua locală (LAN/Wi-Fi), expunere demo prin `cloudflared.exe` și pregătită pentru domeniu + găzduire:**
  - Rulează pe PC-ul principal al stației ITP și poate fi accesată simultan de pe mai multe dispozitive (PC, laptop, telefon, tabletă) conectate la același router/Wi-Fi, fără costuri de hosting.
  - În prima fază, aplicația poate fi expusă securizat către beneficiarul proiectului prin **`cloudflared.exe`** (Cloudflare Tunnel HTTPS) pentru verificarea progresului în timp real.
  - Arhitectura folosește rute 100% relative și configurare prin `.env`, astfel încât mutarea ulterioară pe un **domeniu propriu și găzduire (VPS/cloud)** să se facă fără rescrierea codului.
- **Livrare incrementală (Faza 1 strict `Programări`):**
  - Prima versiune operațională acoperă **exclusiv Baza (Core) + Modulul `programari`** (1 linie ITP implicită `Linia 1`, durată slot **30 min**, blocaj strict anti-suprapunere).
  - Modulele ulterioare (`clienti-vehicule`, `inspectii`, `expirari-itp`, `tarife-incasari` etc.) se adaugă independent peste Bază în **Etapa 2**, fără a modifica nucleul.
- **Frontend HTML5 + CSS3 + JavaScript, Responsive (PC + Telefon):**
  - **Aspect UI:** **exclusiv modern luminos (Light Theme)** (paletă clară, contrast ridicat, lizibilitate foarte bună în lumină puternică la birou sau pe hala de inspecție).
  - **Pe PC:** meniu lateral fix (Sidebar), bară superioară, tabele detaliate și filtre pe un singur ecran.
  - **Pe Telefon:** meniu inferior / sertar lateral, carduri ușor de citit și butoane mari pentru operare cu o singură mână.
- **Multi-utilizator cu niveluri de acces și Istoric Imutabil:**
  - **Administrator (`Laud`):** drepturi depline asupra tuturor modulelor, utilizatorilor, setărilor, exportului și backup-urilor.
  - **Utilizatori restrânși (`Vali`, `Gabi`):** acces controlat granular per modul și acțiune.
  - **Istoric Imutabil al programărilor (trasabilitate completă):** orice creare, editare (oră/linie/dată/client), marcare `Revenire`, reprogramare 30 zile sau ștergere din agendă păstrează **starea inițială intactă** + evenimentele ulterioare cu utilizatorul activ (tabel append-only în SQLite), completat în spate de log intern de securitate/login/backup fără un tab separat de Audit Log în UI.

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
  - Parole stocate exclusiv cu hash `bcrypt`.
  - Sesiuni autentificate prin cookie `HttpOnly` (cu flag `Secure` activat automat când cererea vine pe HTTPS / `X-Forwarded-Proto: https`) + protecție CSRF și rate-limiting la autentificare.
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
   - `users` — conturi utilizatori, hash parolă, rol (`admin` / `user`), status activ/blocat.
   - `user_permissions` — permisiuni granulare per modul pentru utilizatorii restrânși.
   - `modules_state` — starea activ/inactiv a modulelor descoperite.
   - `settings` — configurări generale ale stației ITP (nume stație, adresă, telefon, program orar, linii ITP configurabile — implicit `Linia 1`, `Linia 2`, `Linia 3`, durată slot — implicit `30 min`).
   - `audit_log` — jurnal de activitate **esențial și imutabil (append-only)**: `timestamp`, `user_id`/`username`, `module_id`, `action` (`LOGIN_OK`, `LOGIN_FAIL`, `LOGOUT`, `CREATE`, `UPDATE`, `DELETE`, `STATUS_CHANGE`, `PERMISSION_CHANGE`, `MODULE_TOGGLE`, `EXPORT`, `BACKUP`), `entity_type`, `entity_id`, `before_json`, `after_json`, `ip_address`, `user_agent`.
5. **Audit Log Centralizat (Cerință Critică în Bază)**
   - Politică strictă **append-only**: intrările din `audit_log` nu pot fi editate sau șterse din interfață (nici de către utilizatori, nici de către Admin).
   - Orice modul este obligat să emită evenimente de audit printr-un helper central din Bază (ex. `core.audit.log_event(...)`).
   - Vizualizare exclusivă pentru **Administrator**, cu filtre după utilizator, modul, interval de date, acțiune sau entitate, plus export CSV.
6. **Roluri și Permisiuni**
   - **Administrator:** acces complet automat pe toate modulele + Panou Admin (`Utilizatori`, `Permisiuni per modul`, `Activare/Dezactivare Module`, `Setări stație`, `Jurnal activitate / Audit Log`, `Backup DB`).
   - **Utilizator restrâns:** acces configurabil per modul (`view`, `create`, `edit`, `delete`), plus un profil implicit „Utilizator standard” pentru creare rapidă de conturi.
8. **App Shell Responsive (UI Modern Luminos)**
   - Layout adaptiv PC (sidebar) și telefon (meniu inferior / sertar mobil + carduri).
   - Meniu generat dinamic din modulele active la care utilizatorul curent are drepturi.
   - Dashboard modular: colectează automat widget-uri (carduri rezumat) expuse de modulele active.
   - Kit UI reutilizabil inclus în Bază (formulare, tabele/carduri responsive, modale, notificări toast, dialoguri de confirmare) astfel încât toate modulele să păstreze același design luminos unitar.
10. **Backup Bază de Date**
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
     - Configurare implicită: **3 linii ITP (`Linia 1`, `Linia 2`, `Linia 3`)**, durată slot **30 min** (extensibil din Setări stație fără modificări de cod).
     - **Blocaj strict anti-suprapunere** pe `(dată, oră, linie)` garantat tranzacțional pe server și reflectat vizual în selectorul de ore, plus **verificare duplicare nr. auto în aceeași zi** (normalizat fără spații/cratime: `b458itp` = `B 458 ITP`, cu avertizare și reconfirmare explicită înainte de salvare).
      - **Regulă obligatorie de reconfirmare (`#modalConfirm`)**: nicio acțiune destructivă sau sensibilă (`Șterge` programare cu selector de motiv/`Neprezentare`, `Editează` cu rezumatul modificărilor, `Revocă revenirea`, depășire termen `30 zile`, comutare `Module`/`Utilizatori`, status `Contactat`, `Backup`, `Deconectare`) nu se execută dintr-un singur click accidental.
      - Vedere unificată **`Programări`** cu navigator pe zile (`‹`, dată calendaristică, `›`, comutator `Caută în toate zilele`; modificare pe zile trecute doar Admin), acțiuni coerente (`Editează`, `Revenire` / `+ Reprogramează`, editare motiv prin click pe etichetă, `Sună`, `WA`, `Șterge`), plus tab dedicat **`Istoric Imutabil`** (tabel `appointment_history` append-only în SQLite blocat la `UPDATE`/`DELETE`, care păstrează permanent `initial_snapshot` intact chiar dacă programarea este modificată sau ștearsă din agendă). Fără câmp de `Categorie` pe formularul de programare (Data, Ora, Linie, Nr. auto, Telefon, Client/Mașină).
3. **Etapa 2 (Module viitoare adăugate incremental peste Bază)**
   - **Modul 2 – `clienti-vehicule`:** registru clienți și vehicule, istoric complet per număr de înmatriculare / serie șasiu.
   - **Modul 3 – `inspectii`:** rezultat ITP (`Admis`, `Respins`, `Revenire 30 zile`), inspector, calcul automat data următoarei expirări (6 luni / 1 an / 2 ani).
   - **Modul 4 – `expirari-itp`:** listă filtrabilă 7 / 15 / 30 zile, status „Client contactat”, mesaj precompletat WhatsApp/SMS.
   - **Modul 5 – `tarife-incasari` (doar Admin):** configurare tarife pe categorii, evidență încasări, rapoarte și export CSV/Excel.
   - **Opționale:** `flote-firme`, `documente-poze` (poze din camera telefonului), `cheltuieli-statie`.

---

## 6. Decizii Rămase de Confirmat (Detalii Operaționale)

1. **Programul orar al stației:** intervalul zilnic de lucru afișat în grila de sloturi (ex. `08:00–18:00`, luni–sâmbătă sau luni–vineri).
2. **Editarea programărilor colegilor:** dacă utilizatorii restrânși (`Inspector ITP`, `Recepție`) pot modifica/anula programările create de alți colegi sau doar Adminul are acest drept.
3. **Modul de pornire pe PC-ul stației:** pornire manuală prin `start.bat` (după modelul `ITP-Sentinel/start_server.bat`) sau pornire automată la deschiderea Windows-ului.

---

## 7. Referințe și Memorie de Proiect

- [references/README.md](references/README.md) — indexul proiectelor de referință descărcate (arhitectură modulară FastAPI, RBAC, Audit Log, domeniu auto/inspecții și biblioteci UI offline).
- [memory.md](memory.md) — jurnalul complet al discuțiilor, deciziilor luate și punctelor deschise pentru reluarea ușoară a proiectării și dezvoltării de acasă.
