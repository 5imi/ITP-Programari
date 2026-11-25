# Plan Aplicație Locală Stație ITP (Arhitectură Modulară)

## 1. Obiectiv și Cerințe Principale

- **Aplicație locală (`localhost`) cu acces în rețeaua locală (LAN/Wi-Fi):** rulează pe PC-ul principal al stației ITP și poate fi accesată simultan de pe mai multe dispozitive (PC, laptop, telefon, tabletă) conectate la același router/Wi-Fi, fără internet și fără costuri de hosting.
- **Frontend HTML5 + CSS3 + JavaScript, Responsive (PC + Telefon):**
  - **Aspect UI:** **modern luminos** (paletă clară, contrast ridicat, lizibilitate foarte bună în lumină puternică la birou sau pe hala de inspecție).
  - **Pe PC:** meniu lateral fix (Sidebar), bară superioară, tabele detaliate și filtre pe un singur ecran.
  - **Pe Telefon:** meniu inferior / sertar lateral (hamburger), carduri ușor de citit și butoane mari pentru operare cu o singură mână.
- **Multi-utilizator cu niveluri de acces și Audit Log esențial:**
  - **Administrator:** drepturi depline asupra tuturor modulelor, utilizatorilor, setărilor, jurnalului de activitate (Audit Log) și backup-urilor.
  - **Utilizatori restrânși:** acces controlat granular per modul și acțiune.
  - **Audit Log esențial (trasabilitate completă):** orice autentificare, creare, modificare, ștergere, schimbare de status sau modificare de permisiuni este înregistrată automat în Bază, cu identificarea utilizatorului, modulului și datelor modificate.
- **Dezvoltare modulară (Core + Module):** mai întâi construim **Baza (Core)** stabilă, apoi adăugăm module noi în mod independent peste Bază, fără a rescrie nucleul aplicației.

---

## 2. Arhitectură Tehnică

- **Acces în rețea:**
  - Pe PC-ul gazdă: `http://localhost:8000`
  - De pe telefoane/tablete din același Wi-Fi: `http://<IP-LOCAL-PC>:8000` (ex. `http://192.168.1.50:8000`)
- **Backend (confirmat):**
  - **Python 3.14 + FastAPI + Uvicorn + SQLite (`sqlite3` integrat)** — server rapid, ușor de pornit local, cu documentație API automată și separare curată pe `APIRouter` per modul.
- **Frontend:**
  - **HTML5 + CSS3 + JavaScript** curat, servit direct de FastAPI (fără Node.js și fără pași de build).
- **Bază de date:**
  - **SQLite** într-un singur fișier local (`data/itp.db`), cu sistem automat de migrări per modul (fără instalare de server SQL separat, suport bun pentru utilizare concurentă într-o stație ITP, backup prin copierea unui singur fișier).
- **Securitate și Sesiuni:**
  - Parole stocate exclusiv criptat cu hash (`scrypt` / `bcrypt`).
  - Sesiuni autentificate prin cookie `HttpOnly`.
  - Verificarea rolului și a permisiunilor se face **strict pe server (backend)** la fiecare cerere API, nu doar prin ascunderea butoanelor din interfața HTML.

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
   - `settings` — configurări generale ale stației ITP (nume stație, adresă, telefon, program).
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
   - Descărcare directă a fișierului `itp.db` din panoul Admin și salvare de copii în folderul `backups/`.

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

1. **Faza 0 – Baza (Core) + modul demo de validare**
   - Fundația completă (server LAN, SQLite + migrări, login, roluri & permisiuni, App Shell UI modern luminos PC + telefon, panou Admin, backup) și un mic modul demo pentru testarea încărcării modulare cap-coadă.
2. **Modul 1 – `clienti-vehicule`**
   - Registru clienți și vehicule: număr de înmatriculare (normalizat automat), marcă/model, categorie (M1, N1, moto etc.), serie șasiu (opțional), nume client, telefon, istoric.
3. **Modul 2 – `programari`**
   - Calendar pe zi și pe săptămână, suport pentru una sau mai multe linii ITP, prevenire suprapuneri pe același slot/linie, statusuri (`Programat`, `Pe linie`, `Admis`, `Respins`, `Revenire`, `Anulat`), butoane rapide de apel (`tel:`) și WhatsApp.
4. **Modul 3 – `inspectii`**
   - Înregistrare rezultat ITP (`Admis`, `Respins`, `Revenire 30 zile`), inspector, observații, calcul automat al datei următoarei expirări (6 luni / 1 an / 2 ani).
5. **Modul 4 – `expirari-itp`**
   - Listă filtrabilă cu ITP-urile care expiră în 7 / 15 / 30 zile, marcare status „Client contactat”, mesaj precompletat pentru WhatsApp/SMS.
6. **Modul 5 – `tarife-incasari` (doar Admin)**
   - Configurare tarife pe categorii, evidență încasări, rapoarte pe intervale de timp și export CSV/Excel.
7. **Module viitoare (opționale, la nevoie)**
   - `flote-firme`, `documente-poze` (încărcare poze direct din camera telefonului), `cheltuieli-statie` etc.

---

## 6. Decizii Deschise (Faza de Analiză & Proiectare)

1. **Modelul exact de permisiuni pentru utilizatorii restrânși:** profiluri predefinite (ex. `Administrator`, `Inspector ITP`, `Recepție`) cu ajustare fină per utilizator sau doar `Administrator` + `Utilizator` cu bife per modul; dacă utilizatorii pot modifica programările create de alți colegi.
2. **Fluxul operațional și dimensionarea stației:** număr de utilizatori simultani, număr de linii ITP și durata standard a unui slot de programare (ex. 30 min).
3. **Prioritizarea și completarea listei de module:** care dintre modulele propuse (`Clienți & Vehicule`, `Programări`, `Inspecții`, `Expirări ITP`, `Tarife & Încasări`) sunt prioritare și ce module suplimentare ar fi utile (ex. poze documente de pe telefon, reveniri 30 zile, flote/firme, cheltuieli stație).
4. **Modul de pornire pe PC-ul stației:** pornire manuală prin `start.bat` sau pornire automată la deschiderea Windows-ului.
5. **Repository GitHub:** URL-ul repository-ului + `user.name` / `user.email` pentru Git atunci când se dorește publicarea planului.

---

## 7. Referințe și Memorie de Proiect

- [references/README.md](references/README.md) — indexul proiectelor de referință descărcate (arhitectură modulară FastAPI, RBAC, Audit Log, domeniu auto/inspecții și biblioteci UI offline).
- [memory.md](memory.md) — jurnalul complet al discuțiilor, deciziilor luate și punctelor deschise pentru reluarea ușoară a proiectării și dezvoltării de acasă.
