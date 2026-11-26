# ITP-Programari — Aplicație Locală pentru Stație ITP

Aplicație web modulară (`localhost` + LAN/Wi-Fi + expunere demo prin `cloudflared.exe`) destinată gestionării programărilor și activității unei stații ITP, cu interfață **Tabler Light** optimizată pentru **PC** și **telefon**.

> **Stadiu:** Aplicație web de producție (**PHP 8.2+ & MariaDB InnoDB**, interfață **Tabler Light** optimizată pentru **PC** și **telefon**, pregătită pentru încărcare directă în `public_html` pe cPanel sau rulare locală).

---

## Funcționalități Principale

### 1. Autentificare cu PIN & Gestiune Utilizatori
- **Configurare inițială (`#setupScreen`):** la prima pornire cu baza de date goală, administratorul își creează contul, PIN-ul uzual și **PIN-ul Master de recuperare**, fără credențiale implicite sau date demonstrative în cod.
- **Ecran de Login (`#loginScreen`):**
  - Identitate vizuală **ACW - Fălticeni (`Automotive Center Werkstat • Stație ITP`)** cu sigla oficială [`assets/img/aw-logo.png`](assets/img/aw-logo.png) pe temă deschisă (`Tabler Light`).
  - **Logare rapidă în 2 pași:** selecție utilizator + PIN numeric 4–6 cifre (de la tastatură sau de pe tastatura numerică de pe ecran).
  - **Dual-PIN Admin (Master Recovery):** preia controlul în siguranță, setează un PIN uzual nou și invalidează sesiunile anterioare.
- **Panou `Utilizatori & Drepturi` (doar Administrator):** CRUD utilizatori, permisiuni granulare (`Editare programări`, `Revenire 30 zile / Reprogramare`, `Ștergere programări`), blocare/activare cont.

### 2. Modul `Programări` (3 linii × 30 min)
- **Capacitate & Filtrare:** `Linia 1`, `Linia 2`, `Linia 3`, interval configurabil din `Setări Stație`, filtru pe linie, protecție atomică anti-dublare la nivel de bază de date (`uq_appt_slot` + `version` lock).
- **Dată românească `zz/ll/aaaa`:** navigare `‹` / `›`, indicator `Azi` / `Zi viitoare` / `Istoric`, secțiune **`Programări în viitor`** în agenda zilei curente, câmpuri separate **Client** și **Mașină**.
- **Validări & Autocomplete:** normalizare automată număr înmatriculare și telefon România, autocomplete din istoricul stației, notificare live la dublură în aceeași zi.
- **Flux `Revenire` (30 zile) & Acțiuni rapide:** motive bifabile administrabile (`Motive`), `Revocă → Programat`, `+ Reprogramează` cu calcul automat al termenului de 30 zile, `Sună`, `SMS` (cu semnătura stației configurabilă) și `Șterge` cu motiv obligatoriu.

### 3. `Istoric Imutabil` & Administrare (plus Roadmap Etapa 2)
- **`Istoric Imutabil`:** jurnal append-only protejat la nivel de bază de date, filtrabil după stare/utilizator/text, cu Export CSV.
- **Administrare:** `Motive`, `Setări Stație`, `Jurnal Securitate`, `Backup Bază Date` (export JSON consistent, fără hash-uri PIN, descărcabil în `backups/`).
- **Roadmap modular (Etapa 2):** modulele independente `Clienți & Vehicule` și `Expirări ITP` (coadă de rechemare) se adaugă în `modules/`.

---

## Structura Repository-ului (Gata de Upload)
- `index.php` — punctul unic de intrare (API REST + shell HTML).
- `assets/` — interfața producție (`shell.html`, `css/`, `js/`, `img/aw-logo.png`, `vendor/tabler/`).
- `config/` — `env.example.php` (și `env.php` local, exclus din Git).
- `core/` & `modules/` — nucleul aplicației (`Auth`, `Database`, `Migrator`, `BackupService`, `SettingsService`) și modulul `programari`.
- `backups/` — folder protejat HTTP pentru copii de siguranță JSON.
- `tests/` — suita de testare automată (`run_tests.php`, inclusiv concurență paralelă).
- `.htaccess`, `manifest.json`, `robots.txt`, `router.php` — reguli Apache/cPanel, PWA manifest și router PHP local.
- `.agents/` — documentația internă a proiectului ([`PLAN_PHP.md`](.agents/PLAN_PHP.md), [`IMPLEMENTARE_PHP.md`](.agents/IMPLEMENTARE_PHP.md), [`MEMORY.md`](.agents/MEMORY.md)).

## Pornire Locală (PHP 8.2+ & MariaDB)
1. Copiază `config/env.example.php` în `config/env.php` și configurează conexiunea MariaDB (`acw_itp`).
2. Pornește serverul PHP local:
   ```bash
   php -S 127.0.0.1:8080 router.php
   ```
3. Deschide `http://127.0.0.1:8080/` și parcurge ecranul de configurare inițială (`Setup Admin`).
