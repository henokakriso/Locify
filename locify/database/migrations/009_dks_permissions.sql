-- 009_dks_permissions.sql
-- Digital Kebele Services: print queue operator permission.
-- PRINT:OPERATE is granted to kebele_admin and system_admin; citizens never
-- operate the print queue (only officers behind the desk may).

INSERT IGNORE INTO permission (id, name, resource, action)
VALUES (UUID(), 'PRINT:OPERATE', 'print', 'operate');

SET @print_operate = (SELECT id FROM permission WHERE name = 'PRINT:OPERATE');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, @print_operate FROM role r WHERE r.name IN ('kebele_admin', 'system_admin');