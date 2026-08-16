-- LOCIFY Migration v2.1 — Household records, workflow visibility, higher-government units.
-- Grants CITIZEN:VIEW_FAMILY to kebele roles so officers can manage household/community records.
-- Idempotent.

USE locify;

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM role r JOIN permission p ON 1=1
WHERE r.name IN ('registration_officer','document_officer','verification_officer','records_officer','supervisor','kebele_admin','woreda_admin')
  AND p.name IN ('CITIZEN:VIEW_FAMILY');