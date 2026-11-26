-- Trigger-e Append-Only pe core_audit_log (optional pe shared hosting fara SUPER privilege)

CREATE TRIGGER trg_core_audit_no_update
BEFORE UPDATE ON core_audit_log
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'core_audit_log este append-only';

CREATE TRIGGER trg_core_audit_no_delete
BEFORE DELETE ON core_audit_log
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'core_audit_log este append-only';
