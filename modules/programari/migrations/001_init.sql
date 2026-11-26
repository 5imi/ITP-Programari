-- Modul programari ITP (MariaDB InnoDB)
-- Protectie anti race-condition: uq_appt_slot pe slot activ + optimistic locking (version).

CREATE TABLE IF NOT EXISTS appointments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    appt_date DATE NOT NULL,
    appt_time CHAR(5) NOT NULL,
    line VARCHAR(32) NOT NULL,
    plate VARCHAR(24) NOT NULL,
    plate_key VARCHAR(24) NOT NULL,
    phone VARCHAR(24) NOT NULL,
    client VARCHAR(160) NOT NULL DEFAULT '',
    status ENUM('Programat', 'Revenire') NOT NULL DEFAULT 'Programat',
    reject_reasons_json JSON NOT NULL,
    reject_note VARCHAR(500) NOT NULL DEFAULT '',
    visit_type ENUM('NORMAL', 'REVENIRE') NOT NULL DEFAULT 'NORMAL',
    parent_id BIGINT UNSIGNED NULL,
    resolved_by_reinspection_id BIGINT UNSIGNED NULL,
    reinspection_deadline DATE NULL,
    verify_notes VARCHAR(500) NOT NULL DEFAULT '',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    deleted_by INT UNSIGNED NULL,
    delete_reason VARCHAR(255) NULL,
    active_slot_flag TINYINT GENERATED ALWAYS AS (IF(deleted_at IS NULL, 1, NULL)) STORED,
    UNIQUE KEY uq_appt_slot (appt_date, appt_time, line, active_slot_flag),
    KEY idx_appt_date (appt_date, deleted_at),
    KEY idx_appt_plate_key (plate_key),
    CONSTRAINT fk_appt_parent FOREIGN KEY (parent_id) REFERENCES appointments(id) ON DELETE SET NULL,
    CONSTRAINT fk_appt_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appointment_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    appointment_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    actor_user_id INT UNSIGNED NULL,
    actor_name VARCHAR(120) NOT NULL,
    action_at DATETIME NOT NULL,
    summary VARCHAR(700) NOT NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    reason VARCHAR(255) NULL,
    ip_address VARCHAR(64) NOT NULL DEFAULT '',
    KEY idx_ah_appt (appointment_id, id),
    KEY idx_ah_time (action_at DESC),
    KEY idx_ah_type (event_type),
    CONSTRAINT fk_ah_appt FOREIGN KEY (appointment_id) REFERENCES appointments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reasons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('revenire', 'stergere') NOT NULL,
    label VARCHAR(80) NOT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_reason_kind_label (kind, label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO reasons (kind, label, sort_order) VALUES
    ('revenire', 'Frâne', 10),
    ('revenire', 'Direcție / Suspensie', 20),
    ('revenire', 'Lumini / Semnalizare', 30),
    ('revenire', 'Noxe / Emisii', 40),
    ('revenire', 'Anvelope / Jante', 50),
    ('revenire', 'Caroserie / Parbriz', 60),
    ('revenire', 'Scurgeri lichide', 70),
    ('revenire', 'Acte / Dotări', 80),
    ('stergere', 'Anulat de client', 10),
    ('stergere', 'Neprezentare (No-show)', 20),
    ('stergere', 'Programare dublată / Greșeală', 30),
    ('stergere', 'Alt motiv', 40);
