# Plan Arhitectural: Încărcare Backup (Restore) Protejat cu Parolă

## 1. Context și Scop
Aplicația `ITP-Programari` folosește [BackupService.php](file:///D:/Proiecte/ITP-Programari/core/BackupService.php) pentru a genera snapshot-uri JSON consistente ale tabelelor MariaDB în directorul `backups/`.
- Backup-ul exclude tabela `sessions` și elimină câmpurile `pin_hash` / `master_pin_hash` din `users` (`pin_hashes_excluded: true`) pentru a proteja credențialele.
- Acest document definește arhitectura pentru funcționalitatea de **încărcare / restaurare (Restore)** a unui backup fără risc de pierdere accidentală a datelor sau suprascriere neautorizată.

---

## 2. Cerințe de Securitate și Siguranță Date

1. **Acces strict Administrator + Parolă de confirmare:**
   - Doar utilizatorul cu rol `admin` (sesiune validă + `X-CSRF-Token`) poate accesa restaurarea.
   - Executarea restaurării necesită introducerea **`PIN Secret Admin (Master PIN)`** (8–12 cifre, verificat prin `Security::verifyPin()` împotriva `master_pin_hash` cu rate-limit activ) și confirmare explicită (`confirm_text = "RESTAURARE"`).
   - Opțional, se poate activa o parolă suplimentară de server în `config/env.php` (`BACKUP_RESTORE_PASS`).

2. **Pre-Backup Automat Obligatoriu:**
   - Înainte de orice operațiune de scriere în MariaDB, serverul apelează automat `BackupService::createSnapshot()` și salvează starea curentă cu prefix `itp_auto_before_restore_YYYYMMDD_HHMMSS.json`.
   - Dacă restaurarea a fost făcută cu un fișier greșit, revenirea la starea anterioară este imediată.

3. **Previzualizare (Dry-Run / Preview) înainte de execuție:**
   - La încărcarea fișierului `.json`, backend-ul validează structura și returnează un rezumat comparativ **fără a modifica baza de date**:
     - Data backup-ului (`meta.created_at`) și autorul (`meta.created_by`).
     - Numărul de programări, evenimente de istoric și motive din backup **vs.** baza de date curentă.
     - Avertizare explicită dacă baza curentă conține programări mai noi decât backup-ul.

4. **Protecția Tabelei `users` (Păstrare PIN-uri):**
   - Deoarece snapshot-ul nu conține `pin_hash` și `master_pin_hash`, restaurarea **NU** șterge și **NU** suprascrie hash-urile de PIN ale utilizatorilor existenți.

---

## 3. Moduri de Restaurare

1. **Mod 1 — Restaurare Completă (cu Pre-Backup automat):**
   - Rulează într-o singură tranzacție atomică (`ROLLBACK` automat la orice eroare).
   - Restaurează tabelele operaționale (`appointments`, `appointment_history`, `reasons`, `settings`, `modules_state`) exact la starea din snapshot.
   - Încorporează gestiunea sigură a trigger-elor din `appointment_history` și a cheilor străine (`FOREIGN_KEY_CHECKS`).
2. **Mod 2 — Restaurare Sigură / Completare (Merge, fără ștergere):**
   - Adaugă din backup **doar** înregistrările care lipsesc din baza curentă (`INSERT IGNORE` / verificare chei), **fără a șterge** programările introduse azi.

---

## 4. Integritate și Audit

- **Checksum / Semnătură:** adăugare `meta.sha256` (sau HMAC cu cheie din `env.php`) la generarea backup-ului în `BackupService::createSnapshot()` și verificare obligatorie la upload.
- **Audit Log:** fiecare tentativă (reușită sau eșuată) de restaurare se consemnează în `core_audit_log` (`BACKUP_RESTORE_OK` / `BACKUP_RESTORE_FAIL`).

---

## 5. Structura Bazei de Date pe GitHub vs. Date Locale

- **Structura (Schema SQL) este versionată pe GitHub** în:
  - `core/migrations/001_core.sql`, `002_core_triggers.sql`
  - `modules/programari/migrations/001_init.sql` .. `004_status_finalizat.sql`
- **Datele efective (`backups/*.json`, `config/env.php`, `*.db`)** sunt excluse prin `.gitignore` și prin Regula de Aur Git din `AGENTS.md` (conțin date personale ale clienților și credențiale).
