-- LOCIFY Permission additions — collaboration features (idempotent)

USE locify;

INSERT IGNORE INTO permission (id, name, resource, action) VALUES
  (UUID(), 'CHAT:SEND',         'chat',        'send'),
  (UUID(), 'CHAT:VIEW',         'chat',        'view'),
  (UUID(), 'CHAT:MANAGE',       'chat',        'manage'),
  (UUID(), 'INSTITUTION:VIEW',  'institution', 'view'),
  (UUID(), 'INSTITUTION:MANAGE','institution', 'manage'),
  (UUID(), 'DOCUMENT:LINK',     'document',    'link'),
  (UUID(), 'DOCUMENT:PULL',     'document',    'pull');

-- Officer roles get chat + institution + linking powers
INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM role r JOIN permission p ON 1=1
WHERE r.name IN ('registration_officer','document_officer','verification_officer','records_officer','supervisor','kebele_admin')
  AND p.name IN ('CHAT:SEND','CHAT:VIEW','DOCUMENT:LINK');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM role r JOIN permission p ON 1=1
WHERE r.name IN ('kebele_admin','supervisor')
  AND p.name IN ('CHAT:MANAGE','INSTITUTION:VIEW','DOCUMENT:PULL');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM role r JOIN permission p ON 1=1
WHERE r.name = 'system_admin'
  AND p.name IN ('CHAT:SEND','CHAT:VIEW','CHAT:MANAGE','INSTITUTION:VIEW','INSTITUTION:MANAGE','DOCUMENT:LINK','DOCUMENT:PULL');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM role r JOIN permission p ON 1=1
WHERE r.name = 'citizen'
  AND p.name IN ('CHAT:SEND','CHAT:VIEW');

-- ============================================================
-- Second kebele (cross-kebele collaboration demo)
-- ============================================================

SET @federal = (SELECT id FROM admin_unit WHERE code = 'ET');
SET @region  = (SELECT id FROM admin_unit WHERE code = 'ET-AA');
SET @zone    = (SELECT id FROM admin_unit WHERE code = 'ET-AA-06');
SET @woreda  = (SELECT id FROM admin_unit WHERE code = 'ET-AA-06-01');

SET @kebele2 = UUID();
INSERT IGNORE INTO admin_unit (id, name, local_name, code, type, parent_id, status) VALUES
  (@kebele2, 'Addis Ketema Kebele 02', 'አዲስ ከተማ ቀበሌ 02', 'ET-AA-06-01-02', 'kebele', @woreda, 'active');

INSERT IGNORE INTO office (id, admin_unit_id, name, local_name, type, address, working_hours, capacity) VALUES
  (UUID(), @kebele2, 'Kebele 02 Service Office', 'የቀበሌ 02 አገልግሎት ጽሕፈት ቤት', 'kebele_office', 'Addis Ketema, Kebele 02',
   JSON_OBJECT('start', '08:30', 'end', '17:30', 'days', JSON_ARRAY(1,2,3,4,5)), 30);

INSERT IGNORE INTO service_catalog (id, name, local_name, description, eligibility, required_docs, workflow_id, admin_unit_id, fee_amount, currency, is_active)
SELECT UUID(), 'Birth Certificate Issuance', 'የልደት የምስክር ወረቀት', 'Issuance of birth certificate for citizens', '{"min_age": 0}', '["identity_document"]', w.id, @kebele2, 50.00, 'ETB', 1
FROM workflow w WHERE w.name = 'Birth Certificate Issuance' AND NOT EXISTS (
  SELECT 1 FROM service_catalog sc WHERE sc.admin_unit_id = @kebele2 AND sc.name = 'Birth Certificate Issuance'
);

-- ============================================================
-- Government institutions (partners)
-- ============================================================

INSERT IGNORE INTO institution (id, name, short_name, category, admin_unit_id, contact) VALUES
  (UUID(), 'Ethiopian Federal Police — Lost Document Bureau', 'EFP-LDB', 'federal_agency', @federal, 'police.ldb@example.gov.et'),
  (UUID(), 'Addis Ababa City Administration — Civil Registry', 'AAC-CR', 'region', @region, 'registry@example.gov.et'),
  (UUID(), 'National Bank of Ethiopia — Customer Due Diligence', 'NBE-CDD', 'ministry', NULL, 'cdd@nbe.gov.et'),
  (UUID(), 'Commercial Bank of Ethiopia — KYC Unit', 'CBE-KYC', 'other_gov', NULL, 'kyc@cbe.com.et'),
  (UUID(), 'UNHCR Ethiopia — Registration Office', 'UNHCR', 'other_gov', NULL, 'registration@unhcr.org');