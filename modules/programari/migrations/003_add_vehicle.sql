-- Adauga coloana separata `vehicle` (Masina) pe tabela `appointments`

ALTER TABLE appointments
    ADD COLUMN vehicle VARCHAR(120) NOT NULL DEFAULT '' AFTER plate_key;
