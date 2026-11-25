# ITP-Programari — Aplicație Locală pentru Stație ITP

Aplicație web modulară (`localhost` + LAN/Wi-Fi + expunere demo prin `cloudflared.exe`) destinată gestionării programărilor și activității unei stații ITP, cu interfață **Tabler Light** optimizată pentru **PC** și **telefon**.

> **Stadiu curent:** Machetă interactivă completă (`dashboard.html`, 100% offline pe baza `references/ui-libs/tabler/`) + plan de arhitectură (`PLAN.md`) și memorie tehnică (`memory.md`), pregătită pentru dezvoltarea backend-ului **Faza 0 (FastAPI + SQLite WAL)**.

---

## Ce este implementat în Demo (`dashboard.html`)

### 1. Autentificare & Gestiune Utilizatori
- **Ecran de Login (`#loginScreen`):**
  - Identitate vizuală **ACW - Fălticeni (`Automotive Center Werkstat • Stație ITP`)** cu sigla oficială [`references/ui-libs/aw-logo.png`](references/ui-libs/aw-logo.png) pe temă deschisă (`Tabler Light`).
  - Cont principal implicit: utilizator **`admin`**, parolă **`admin`** (**Laur — Administrator**), plus buton `Arată / Ascunde` parolă.
  - Butoane dinamice de **acces rapid 1-click** pentru conturile configurate (`Laur`, `Vali`, `Gabi` și orice utilizator nou adăugat).
  - **Deconectare** cu reconfirmare explicită înainte de părăsirea sesiunii.
- **Panou `Utilizatori & Drepturi` (doar Administrator):**
  - **`+ Adaugă utilizator`** și **`Editează`** (nume, user login unic, parolă, profil `Administrator` / `Utilizator`).
  - **Permisiuni granulare per cont:** `Editare programări` (controlează inclusiv vizibilitatea butonului `+ Programare` și accesul la modale), `Revenire 30 zile / Reprogramare`, `Ștergere programări`.
  - **`Blochează` / `Activează` cont** (cu reconfirmare și protecție împotriva blocării adminului principal); conturile blocate sunt respinse imediat din ecranul de Login.

### 2. Modul `Programări` (3 linii × 30 min)
- **Capacitate & Filtrare:** `Linia 1`, `Linia 2`, `Linia 3`, interval `08:00–17:30` (60 sloturi/zi), filtru rapid pe linie (`Toate liniile / Linia 1 / Linia 2 / Linia 3`) și afișare sloturi libere per linie direct în modal (`Linie (libere)`).
- **Vedere unificată pe zile (dată dinamică):** pornește automat pe data locală curentă, cu butoane `‹` / `›`, selector calendaristic, indicator `Azi` / `Zi viitoare` / `Istoric`, blocare creare/editare în trecut pentru non-admin și bifă `Caută în toate zilele`.
- **Validări, Autocomplete și Anti-duplicare:**
  - Blocaj pe `(dată, oră, linie)` cu afișare `(Ocupat)` direct în selectorul de ore.
  - Validare strictă număr auto (`min. 4` caractere alfanumerice, normalizat `b458itp` / `B-458-ITP` → `B 458 ITP`) și telefon România (`10 cifre` `07xx`/`02xx`/`03xx` sau `+40...`, normalizat curat).
  - **Autocomplete client/telefon** la tastarea unui număr de înmatriculare existent în baza stației.
  - **Notificare live în formular (`#duplicateLiveAlert`)** imediat ce se tastează un număr care are deja programare în ziua selectată (inclusiv exemplu demo `SV 08 LZB`), plus reconfirmare la salvare (ignorând fals-pozitivul față de vizita-părinte la reprogramarea unei reveniri).
- **Flux complet de `Revenire` (30 zile) și operare rapidă:**
  - Statusuri simple: **`Programat`** și **`Revenire`**.
  - **Motive hibride:** 8 cauze rapide bifabile (`Frâne`, `Direcție / Suspensie`, `Lumini / Semnalizare`, `Noxe / Emisii`, `Anvelope / Jante`, `Caroserie / Parbriz`, `Scurgeri lichide`, `Acte / Dotări`) + observații opționale; editare motiv prin click direct pe text.
  - **`Revocă → Programat`:** readuce o programare marcată greșit ca revenire înapoi la `Programat` (cu reconfirmare și trasabilitate în istoric).
  - **`+ Reprogramează`:** preia automat datele mașinii + verificările de făcut, propune implicit ziua următoare pentru o revenire din ziua curentă, calculează termenul de 30 zile și **avertizează roșu** dacă data aleasă depășește cele 30 de zile (oferind trecerea pe ITP complet standard).
  - **Acțiuni rapide:** `Editează`, `Sună` (`tel:`), `WhatsApp` (mesaj precompletat pentru confirmare sau motiv revenire) și `Șterge` (cu selector de motiv: `Anulat de client`, `Neprezentare (No-show)`, `Programare dublată / Greșeală`).

### 3. `Istoric Imutabil` (Append-only) & Persistență Demo
- Păstrează **starea inițială protejată** a fiecărei programări chiar dacă aceasta este mutată, editată, revocată sau ștearsă ulterior din agendă.
- Afișează cronologic toate modificările (`oră • utilizator • modificare`), fără posibilitate de ștergere din UI.
- Include filtrare după stare (`Toate`, `Modificate`, `Reveniri`, `Șterse`), filtru după utilizator (`Laur`, `Vali`, `Gabi`, conturi noi), căutare globală și **Export CSV** (pentru Administrator).
- **Persistență locală (`localStorage`) + `Resetează Demo`:** modificările făcute în demo rezistă la reîncărcarea paginii (`F5`), iar Administratorul poate reveni oricând la datele inițiale prin opțiunea reconfirmată `Resetează Demo` din meniu.

### 4. Regula de Reconfirmare Universală (`#modalConfirm`)
- Nicio acțiune destructivă sau sensibilă (`Șterge` programare + motiv, salvare modificări `Editează`, `Revocă revenirea`, depășire termen 30 zile, programare dublă, `Blochează / Activează` utilizator, comutare `Module`, status `Contactat`, `Backup`, `Deconectare`) nu se execută dintr-un singur click accidental.

- **Secțiune `Motive` (doar Administrator):**
  - Administrare și salvare persistentă pentru **Motive Revenire (30 zile)** (butoanele bifabile din modalul `Revenire`) și **Motive Ștergere / Anulare** (selectorul din modalul de reconfirmare `Șterge`), cu adăugare, editare inline, protecție anti-duplicat și ștergere reconfirmată.

### 5. Module Pregătite pentru Etapa 2 (Demo Vizual)
- `Clienți & Vehicule`, `Expirări ITP` (`15 / 30 zile` + WhatsApp + `Contactat`), `Module` (comutator activare/dezactivare), `Backup Bază Date`.

---

## Structura Repository-ului
- `dashboard.html` — macheta interactivă completă (PC + mobil).
- `PLAN.md` — planul de arhitectură modulară (`Core` Faza 0 + `programari` Faza 1 + Etapa 2).
- `memory.md` — jurnalul deciziilor tehnice și progresului.
- `references/` — resurse UI locale (`tabler`, `fullcalendar`, `htmx`) și referințe arhitecturale.

## Previzualizare Locală Rapidă
Deschide `dashboard.html` direct în browser sau servește folderul prin Python:
```bash
python -m http.server 8000
```
Apoi accesează `http://localhost:8000/dashboard.html` (`user: admin`, `parolă: admin`).
