-- LOCIFY Seed Data v1.0
-- Run after schema.sql. Idempotent.

USE locify;

-- ============================================================
-- PERMISSIONS
-- ============================================================

INSERT IGNORE INTO permission (id, name, resource, action) VALUES
  (UUID(), 'CITIZEN:VIEW_SELF',    'citizen',    'view_self'),
  (UUID(), 'CITIZEN:EDIT_SELF',    'citizen',    'edit_self'),
  (UUID(), 'CITIZEN:VIEW',         'citizen',    'view'),
  (UUID(), 'CITIZEN:CREATE',       'citizen',    'create'),
  (UUID(), 'CITIZEN:EDIT',         'citizen',    'edit'),
  (UUID(), 'CITIZEN:SEARCH',       'citizen',    'search'),
  (UUID(), 'CITIZEN:VIEW_FAMILY',  'citizen',    'view_family'),
  (UUID(), 'CITIZEN:VERIFY_INITIATE', 'citizen', 'verify_initiate'),
  (UUID(), 'CITIZEN:VERIFY_APPROVE',  'citizen', 'verify_approve'),
  (UUID(), 'APPLICATION:CREATE',   'application', 'create'),
  (UUID(), 'APPLICATION:VIEW',     'application', 'view'),
  (UUID(), 'APPLICATION:PROCESS',  'application', 'process'),
  (UUID(), 'APPLICATION:APPROVE',  'application', 'approve'),
  (UUID(), 'APPLICATION:CANCEL',   'application', 'cancel'),
  (UUID(), 'DOCUMENT:CREATE',      'document',    'create'),
  (UUID(), 'DOCUMENT:EDIT',        'document',    'edit'),
  (UUID(), 'DOCUMENT:VIEW',        'document',    'view'),
  (UUID(), 'DOCUMENT:VIEW_OWN',    'document',    'view_own'),
  (UUID(), 'DOCUMENT:SIGN',        'document',    'sign'),
  (UUID(), 'DOCUMENT:VERIFY',      'document',    'verify'),
  (UUID(), 'DOCUMENT:REVOKE',      'document',    'revoke'),
  (UUID(), 'PAYMENT:INITIATE',     'payment',     'initiate'),
  (UUID(), 'PAYMENT:CONFIRM',      'payment',     'confirm'),
  (UUID(), 'PAYMENT:VIEW',         'payment',     'view'),
  (UUID(), 'NOTIFICATION:SEND',    'notification','send'),
  (UUID(), 'NOTIFICATION:VIEW',    'notification','view'),
  (UUID(), 'APPOINTMENT:CREATE',   'appointment', 'create'),
  (UUID(), 'APPOINTMENT:MANAGE',   'appointment', 'manage'),
  (UUID(), 'QUEUE:ISSUE',          'queue',       'issue'),
  (UUID(), 'QUEUE:CALL',           'queue',       'call'),
  (UUID(), 'COMPLAINT:CREATE',     'complaint',   'create'),
  (UUID(), 'COMPLAINT:VIEW',       'complaint',   'view'),
  (UUID(), 'COMPLAINT:PROCESS',    'complaint',   'process'),
  (UUID(), 'REPORT:VIEW',          'report',      'view'),
  (UUID(), 'AUDIT:VIEW',           'audit',       'view'),
  (UUID(), 'USER:MANAGE',          'user',        'manage'),
  (UUID(), 'ROLE:ASSIGN',          'role',        'assign'),
  (UUID(), 'OFFICE:MANAGE',        'office',      'manage'),
  (UUID(), 'SERVICE:CONFIGURE',    'service',     'configure'),
  (UUID(), 'WORKFLOW:CONFIGURE',   'workflow',    'configure'),
  (UUID(), 'SYSTEM:MANAGE',        'system',      'manage'),
  (UUID(), 'INTEGRATION:MANAGE',   'integration', 'manage'),
  (UUID(), 'SECURITY:MANAGE',      'security',    'manage');

-- ============================================================
-- ROLES
-- ============================================================

INSERT IGNORE INTO role (id, name, description) VALUES
  (UUID(), 'citizen', 'Citizen with self-service access'),
  (UUID(), 'registration_officer', 'Creates and updates citizen records'),
  (UUID(), 'document_officer', 'Drafts, reviews and issues documents'),
  (UUID(), 'verification_officer', 'Validates citizen identity and documents'),
  (UUID(), 'records_officer', 'Manages archives and data correction'),
  (UUID(), 'finance_officer', 'Payment reconciliation and receipts'),
  (UUID(), 'supervisor', 'Approves high-value documents and workflow exceptions'),
  (UUID(), 'kebele_admin', 'Local user, office and service configuration'),
  (UUID(), 'woreda_admin', 'Read-only oversight of woreda-level reports'),
  (UUID(), 'system_admin', 'Central system, integration and security management');

-- Role → permission mapping (by permission name, for idempotency)

SET @citizen        = (SELECT id FROM role WHERE name = 'citizen');
SET @reg_officer    = (SELECT id FROM role WHERE name = 'registration_officer');
SET @doc_officer    = (SELECT id FROM role WHERE name = 'document_officer');
SET @ver_officer    = (SELECT id FROM role WHERE name = 'verification_officer');
SET @supervisor     = (SELECT id FROM role WHERE name = 'supervisor');
SET @kebele_admin   = (SELECT id FROM role WHERE name = 'kebele_admin');
SET @woreda_admin   = (SELECT id FROM role WHERE name = 'woreda_admin');
SET @system_admin   = (SELECT id FROM role WHERE name = 'system_admin');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM role r JOIN permission p ON 1=1 WHERE r.name = 'citizen'
  AND p.name IN ('CITIZEN:VIEW_SELF','CITIZEN:EDIT_SELF','APPLICATION:CREATE','APPLICATION:VIEW','DOCUMENT:VIEW_OWN','PAYMENT:INITIATE','APPOINTMENT:CREATE','COMPLAINT:CREATE','NOTIFICATION:VIEW');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM role r JOIN permission p ON 1=1 WHERE r.name = 'registration_officer'
  AND p.name IN ('CITIZEN:CREATE','CITIZEN:VIEW','CITIZEN:EDIT','CITIZEN:SEARCH','CITIZEN:VERIFY_INITIATE','APPLICATION:VIEW','NOTIFICATION:VIEW');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM role r JOIN permission p ON 1=1 WHERE r.name = 'document_officer'
  AND p.name IN ('DOCUMENT:CREATE','DOCUMENT:EDIT','DOCUMENT:VIEW','APPLICATION:VIEW','APPLICATION:PROCESS','NOTIFICATION:VIEW');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM role r JOIN permission p ON 1=1 WHERE r.name = 'verification_officer'
  AND p.name IN ('CITIZEN:VERIFY_APPROVE','DOCUMENT:VERIFY','CITIZEN:VIEW','DOCUMENT:VIEW','APPLICATION:VIEW');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM role r JOIN permission p ON 1=1 WHERE r.name = 'records_officer'
  AND p.name IN ('CITIZEN:VIEW','CITIZEN:EDIT','CITIZEN:SEARCH','DOCUMENT:VIEW','AUDIT:VIEW');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM role r JOIN permission p ON 1=1 WHERE r.name = 'finance_officer'
  AND p.name IN ('PAYMENT:VIEW','PAYMENT:CONFIRM','APPLICATION:VIEW','REPORT:VIEW');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM role r JOIN permission p ON 1=1 WHERE r.name = 'supervisor'
  AND p.name IN ('APPLICATION:APPROVE','APPLICATION:CANCEL','DOCUMENT:SIGN','DOCUMENT:REVOKE','DOCUMENT:VIEW','AUDIT:VIEW','CITIZEN:VIEW','APPLICATION:VIEW','COMPLAINT:PROCESS','QUEUE:CALL','APPOINTMENT:MANAGE');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM role r JOIN permission p ON 1=1 WHERE r.name = 'kebele_admin'
  AND p.name IN ('USER:MANAGE','ROLE:ASSIGN','OFFICE:MANAGE','SERVICE:CONFIGURE','WORKFLOW:CONFIGURE','REPORT:VIEW','AUDIT:VIEW','NOTIFICATION:SEND');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM role r JOIN permission p ON 1=1 WHERE r.name = 'woreda_admin'
  AND p.name IN ('REPORT:VIEW','AUDIT:VIEW');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM role r JOIN permission p ON 1=1 WHERE r.name = 'system_admin';

-- ============================================================
-- ADMINISTRATIVE HIERARCHY (Federal → Region → Zone → Woreda → Kebele)
-- ============================================================

SET @federal = UUID();
SET @region  = UUID();
SET @zone    = UUID();
SET @woreda  = UUID();
SET @kebele  = UUID();

INSERT IGNORE INTO admin_unit (id, name, local_name, code, type, parent_id, status, config) VALUES
  (@federal, 'Federal Democratic Republic of Ethiopia', 'የኢትዮጵያ ፌዴራላዊ ዲሞክራሲያዊ ሪፐብሊክ', 'ET', 'federal', NULL, 'active',
   JSON_OBJECT('languages', JSON_ARRAY('am','en','om','ti'), 'default_language', 'am')),
  (@region,  'Addis Ababa City Administration', 'አዲስ አበባ ከተማ አስተዳደር', 'ET-AA', 'region', @federal, 'active',
   JSON_OBJECT('timezone', 'Africa/Addis_Ababa')),
  (@zone,    'Addis Ababa Zone 6', 'የአዲስ አበባ ዞን 6', 'ET-AA-06', 'zone', @region, 'active', NULL),
  (@woreda,  'Addis Ketema Woreda', 'አዲስ ከተማ ወረዳ', 'ET-AA-06-01', 'woreda', @zone, 'active', NULL),
  (@kebele,  'Addis Ketema Kebele 01', 'አዲስ ከተማ ቀበሌ 01', 'ET-AA-06-01-01', 'kebele', @woreda, 'active',
   JSON_OBJECT('office_hours', JSON_OBJECT('start', '08:30', 'end', '17:30')));

SET @office = UUID();
INSERT IGNORE INTO office (id, admin_unit_id, name, local_name, type, address, working_hours, capacity) VALUES
  (@office, @kebele, 'Kebele 01 Service Office', 'የቀበሌ 01 አገልግሎት ጽሕፈት ቤት', 'kebele_office', 'Addis Ketema, Kebele 01',
   JSON_OBJECT('start', '08:30', 'end', '17:30', 'days', JSON_ARRAY(1,2,3,4,5)), 30);

-- ============================================================
-- WORKFLOWS
-- ============================================================

SET @wf_birth = UUID();
INSERT IGNORE INTO workflow (id, name, version, definition_json, status) VALUES
  (@wf_birth, 'Birth Certificate Issuance', 1,
   JSON_OBJECT('workflow_id', 'wf_birth_cert', 'version', 1,
     'steps', JSON_ARRAY(
       JSON_OBJECT('step_id','step_1','name','Identity Verification','role','verification_officer','action','VERIFY_IDENTITY','next','step_2','on_reject','end_rejected','sla_hours',4),
       JSON_OBJECT('step_id','step_2','name','Document Review','role','document_officer','action','REVIEW_DOCS','next','step_3','on_reject','returned','sla_hours',8),
       JSON_OBJECT('step_id','step_3','name','Supervisor Approval','role','supervisor','action','APPROVE','next','step_4','on_reject','end_rejected','sla_hours',24),
       JSON_OBJECT('step_id','step_4','name','Document Generation & Sign','role','document_officer','action','GENERATE_AND_SIGN','next','end_approved','sla_hours',4)
     )),
   'active');

SET @wf_residence = UUID();
INSERT IGNORE INTO workflow (id, name, version, definition_json, status) VALUES
  (@wf_residence, 'Proof of Residence', 1,
   JSON_OBJECT('workflow_id', 'wf_residence', 'version', 1,
     'steps', JSON_ARRAY(
       JSON_OBJECT('step_id','step_1','name','Identity Verification','role','verification_officer','action','VERIFY_IDENTITY','next','step_2','on_reject','end_rejected','sla_hours',4),
       JSON_OBJECT('step_id','step_2','name','Address Verification','role','registration_officer','action','VERIFY_ADDRESS','next','step_3','on_reject','returned','sla_hours',8),
       JSON_OBJECT('step_id','step_3','name','Document Generation & Sign','role','document_officer','action','GENERATE_AND_SIGN','next','end_approved','sla_hours',4)
     )),
   'active');

-- ============================================================
-- SERVICE CATALOG
-- ============================================================

INSERT IGNORE INTO service_catalog (id, name, local_name, description, eligibility, required_docs, workflow_id, admin_unit_id, fee_amount, currency, is_active) VALUES
  (UUID(), 'Birth Certificate Issuance', 'የልደት የምስክር ወረቀት', 'Issuance of birth certificate for citizens', '{"min_age": 0}', '["identity_document"]', @wf_birth, @kebele, 50.00, 'ETB', 1),
  (UUID(), 'Proof of Residence', 'የመኖሪያ ማረጋገጫ', 'Confirmation of place of residence', '{"min_age": 0}', '["identity_document","address_proof"]', @wf_residence, @kebele, 25.00, 'ETB', 1);

-- ============================================================
-- NOTIFICATION TEMPLATES
-- ============================================================

INSERT IGNORE INTO notification_template (id, channel, locale, subject, body) VALUES
  ('app_received', 'sms', 'am', NULL, '{application_number} ማመልከቻዎ ተቀብለናል። የአገልግሎት ሂደት ተጀምሯል።'),
  ('app_received', 'sms', 'en', NULL, 'Your application {application_number} has been received.'),
  ('doc_ready', 'sms', 'am', NULL, '{document_type} ዝግጁ ነው። በቀበሌ ጽሕፈት ቤት ይቀበሉ።'),
  ('doc_ready', 'sms', 'en', NULL, 'Your {document_type} is ready for collection.'),
  ('appt_reminder', 'sms', 'am', NULL, 'የቀጠሮ ማስታወሻ፦ {slot_start}'),
  ('appt_reminder', 'sms', 'en', NULL, 'Appointment reminder: {slot_start}'),
  ('pay_confirmed', 'sms', 'am', NULL, 'ክፍያዎ ተረጋግጧል።'),
  ('pay_confirmed', 'sms', 'en', NULL, 'Your payment has been confirmed.');

-- ============================================================
-- DEMO USERS
-- ============================================================
-- Passwords below are Argon2id hashes of "Locify@2026".
-- Create via the API (POST /api/v1/auth/register-user) in production.

SET @sys_admin_user = UUID();
INSERT IGNORE INTO user (id, username_hash, password_hash, status, mfa_enabled) VALUES
  (@sys_admin_user,
   SHA2('admin', 256),
   '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHRzb21lc2FsdHNvbWVzYWx0c29tZXNhbHQ$Q/6uD2pG8l6M5F0pX1zY3eNv3V9kZ0aR9bJ7mT2sW3E', -- placeholder, replaced by cli/admin:create
   'active', 0);

INSERT IGNORE INTO user_role (user_id, role_id, admin_unit_id, is_active) VALUES
  (@sys_admin_user, @system_admin, @federal, 1);
