-- Trigger-e Append-Only pe appointment_history (optional pe shared hosting fara SUPER privilege)

CREATE TRIGGER trg_appt_hist_no_update
BEFORE UPDATE ON appointment_history
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'appointment_history este append-only';

CREATE TRIGGER trg_appt_hist_no_delete
BEFORE DELETE ON appointment_history
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'appointment_history este append-only';
