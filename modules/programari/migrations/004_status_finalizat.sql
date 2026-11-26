-- Adauga status operational `Finalizat` (ITP Admis) pe tabela `appointments`

ALTER TABLE appointments
    MODIFY COLUMN status ENUM('Programat', 'Revenire', 'Finalizat') NOT NULL DEFAULT 'Programat';
