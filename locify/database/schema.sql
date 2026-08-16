-- LOCIFY Database Schema
-- Project ARWE — Master schema v1.0
-- Engine: MySQL/MariaDB, UTF-8, InnoDB
-- Note: UUIDs stored as CHAR(36). Sensitive fields encrypted/hashed at application level.

CREATE DATABASE IF NOT EXISTS locify
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE locify;

-- ============================================================
-- 1. ADMINISTRATIVE HIERARCHY
-- ============================================================

CREATE TABLE IF NOT EXISTS admin_unit (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  name          VARCHAR(255) NOT NULL,
  local_name    VARCHAR(255) NULL,
  code          VARCHAR(64)  NULL UNIQUE,
  type          ENUM('federal','region','zone','woreda','kebele','other') NOT NULL,
  parent_id     CHAR(36)     NULL,
  geo_data      JSON         NULL,
  status        ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
  config        JSON         NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by    CHAR(36)     NULL,
  updated_by    CHAR(36)     NULL,
  CONSTRAINT fk_admin_unit_parent FOREIGN KEY (parent_id) REFERENCES admin_unit(id)
) ENGINE=InnoDB;

CREATE INDEX idx_admin_unit_parent ON admin_unit(parent_id);
CREATE INDEX idx_admin_unit_type ON admin_unit(type);

CREATE TABLE IF NOT EXISTS office (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  admin_unit_id CHAR(36)     NOT NULL,
  name          VARCHAR(255) NOT NULL,
  local_name    VARCHAR(255) NULL,
  type          VARCHAR(64)  NULL,
  address       VARCHAR(255) NULL,
  working_hours JSON         NULL,
  capacity      INT          NOT NULL DEFAULT 20,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_office_admin_unit FOREIGN KEY (admin_unit_id) REFERENCES admin_unit(id)
) ENGINE=InnoDB;

CREATE INDEX idx_office_admin_unit ON office(admin_unit_id);

-- ============================================================
-- 2. CITIZENS & IDENTITY
-- ============================================================

CREATE TABLE IF NOT EXISTS citizen (
  id                CHAR(36)      NOT NULL PRIMARY KEY,
  uuid              CHAR(36)      NOT NULL UNIQUE,
  national_id_hash  CHAR(64)      NULL,
  national_id_mask  VARCHAR(32)   NULL,
  first_name_enc    VARBINARY(512) NULL, -- application-level encrypted
  middle_name_enc   VARBINARY(512) NULL,
  last_name_enc     VARBINARY(512) NULL,
  local_name_enc    VARBINARY(512) NULL,
  dob_eth           DATE          NULL,
  dob_greg          DATE          NULL,
  sex               ENUM('M','F','O') NULL,
  photo_url_enc     VARBINARY(512) NULL,
  phone_hash        CHAR(64)      NULL,
  email_hash        CHAR(64)      NULL,
  status            ENUM('pending_verification','active','inactive','archived','verification_failed') NOT NULL DEFAULT 'pending_verification',
  consent_json      JSON          NULL,
  created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by        CHAR(36)      NULL,
  updated_by        CHAR(36)      NULL
) ENGINE=InnoDB;

CREATE INDEX idx_citizen_nid_hash ON citizen(national_id_hash);
CREATE INDEX idx_citizen_phone_hash ON citizen(phone_hash);
CREATE INDEX idx_citizen_status ON citizen(status);

CREATE TABLE IF NOT EXISTS citizen_address (
  id            CHAR(36)   NOT NULL PRIMARY KEY,
  citizen_id    CHAR(36)   NOT NULL,
  admin_unit_id CHAR(36)   NOT NULL,
  village_enc   VARBINARY(512) NULL,
  house_no_enc  VARBINARY(512) NULL,
  gps_lat       DECIMAL(10,7) NULL,
  gps_long      DECIMAL(10,7) NULL,
  is_primary    TINYINT(1) NOT NULL DEFAULT 0,
  created_at    TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_cit_addr_citizen FOREIGN KEY (citizen_id) REFERENCES citizen(id),
  CONSTRAINT fk_cit_addr_admin FOREIGN KEY (admin_unit_id) REFERENCES admin_unit(id)
) ENGINE=InnoDB;

CREATE INDEX idx_cit_addr_citizen ON citizen_address(citizen_id);

CREATE TABLE IF NOT EXISTS citizen_relationship (
  id                CHAR(36)  NOT NULL PRIMARY KEY,
  citizen_id        CHAR(36)  NOT NULL,
  related_citizen_id CHAR(36) NOT NULL,
  relation_type     VARCHAR(64) NOT NULL,
  start_date        DATE      NULL,
  end_date          DATE      NULL,
  verified          TINYINT(1) NOT NULL DEFAULT 0,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by        CHAR(36)  NULL,
  CONSTRAINT fk_rel_citizen FOREIGN KEY (citizen_id) REFERENCES citizen(id),
  CONSTRAINT fk_rel_related FOREIGN KEY (related_citizen_id) REFERENCES citizen(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS identity_verification (
  id                CHAR(36) NOT NULL PRIMARY KEY,
  citizen_id        CHAR(36) NOT NULL,
  verification_type VARCHAR(64) NOT NULL,
  status            ENUM('pending','success','failed','expired') NOT NULL DEFAULT 'pending',
  verified_at       TIMESTAMP NULL,
  verified_by       CHAR(36) NULL,
  external_ref      VARCHAR(128) NULL,
  notes             VARCHAR(512) NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_idv_citizen FOREIGN KEY (citizen_id) REFERENCES citizen(id)
) ENGINE=InnoDB;

CREATE INDEX idx_idv_citizen ON identity_verification(citizen_id);

CREATE TABLE IF NOT EXISTS consent (
  id         CHAR(36)   NOT NULL PRIMARY KEY,
  citizen_id CHAR(36)   NOT NULL,
  scope      VARCHAR(128) NOT NULL,
  version    INT        NOT NULL DEFAULT 1,
  granted    TINYINT(1) NOT NULL DEFAULT 0,
  granted_at TIMESTAMP  NULL,
  revoked_at TIMESTAMP  NULL,
  created_at TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_consent_citizen FOREIGN KEY (citizen_id) REFERENCES citizen(id)
) ENGINE=InnoDB;

-- ============================================================
-- 3. USERS, ROLES & PERMISSIONS (RBAC)
-- ============================================================

CREATE TABLE IF NOT EXISTS user (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  username_hash CHAR(64)     NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  citizen_id    CHAR(36)     NULL,
  email_enc     VARBINARY(512) NULL,
  phone_enc     VARBINARY(512) NULL,
  status        ENUM('active','inactive','locked') NOT NULL DEFAULT 'active',
  mfa_enabled   TINYINT(1)   NOT NULL DEFAULT 0,
  mfa_secret    VARCHAR(255) NULL,
  failed_attempts INT        NOT NULL DEFAULT 0,
  locked_until  TIMESTAMP    NULL,
  last_login    TIMESTAMP    NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_citizen FOREIGN KEY (citizen_id) REFERENCES citizen(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  name        VARCHAR(64)  NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS permission (
  id       CHAR(36)     NOT NULL PRIMARY KEY,
  name     VARCHAR(100) NOT NULL UNIQUE,
  resource VARCHAR(64)  NOT NULL,
  action   VARCHAR(64)  NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_role (
  user_id       CHAR(36) NOT NULL,
  role_id       CHAR(36) NOT NULL,
  admin_unit_id CHAR(36) NOT NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (user_id, role_id, admin_unit_id),
  CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES user(id),
  CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES role(id),
  CONSTRAINT fk_ur_admin FOREIGN KEY (admin_unit_id) REFERENCES admin_unit(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permission (
  role_id       CHAR(36) NOT NULL,
  permission_id CHAR(36) NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES role(id),
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permission(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS delegation (
  id            CHAR(36)   NOT NULL PRIMARY KEY,
  delegator_id  CHAR(36)   NOT NULL,
  delegatee_id  CHAR(36)   NOT NULL,
  admin_unit_id CHAR(36)   NULL,
  from_date     TIMESTAMP  NOT NULL,
  to_date       TIMESTAMP  NOT NULL,
  status        ENUM('pending','approved','rejected','active','expired') NOT NULL DEFAULT 'pending',
  approved_by   CHAR(36)   NULL,
  created_at    TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_del_user1 FOREIGN KEY (delegator_id) REFERENCES user(id),
  CONSTRAINT fk_del_user2 FOREIGN KEY (delegatee_id) REFERENCES user(id)
) ENGINE=InnoDB;

-- ============================================================
-- 4. SERVICES, WORKFLOWS & APPLICATIONS
-- ============================================================

CREATE TABLE IF NOT EXISTS workflow (
  id             CHAR(36)  NOT NULL PRIMARY KEY,
  name           VARCHAR(128) NOT NULL,
  version        INT       NOT NULL DEFAULT 1,
  definition_json JSON     NOT NULL,
  status         ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_catalog (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  name          VARCHAR(255) NOT NULL,
  local_name    VARCHAR(255) NULL,
  description   TEXT         NULL,
  eligibility   JSON         NULL,
  required_docs JSON         NULL,
  workflow_id   CHAR(36)     NULL,
  admin_unit_id CHAR(36)     NOT NULL,
  fee_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency      VARCHAR(8)   NOT NULL DEFAULT 'ETB',
  slot_duration_min INT      NOT NULL DEFAULT 15,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sc_workflow FOREIGN KEY (workflow_id) REFERENCES workflow(id),
  CONSTRAINT fk_sc_admin FOREIGN KEY (admin_unit_id) REFERENCES admin_unit(id)
) ENGINE=InnoDB;

CREATE INDEX idx_sc_admin ON service_catalog(admin_unit_id);

CREATE TABLE IF NOT EXISTS application (
  id                 CHAR(36)      NOT NULL PRIMARY KEY,
  uuid               CHAR(36)      NOT NULL UNIQUE,
  application_number VARCHAR(32)   NOT NULL UNIQUE,
  citizen_id         CHAR(36)      NOT NULL,
  service_catalog_id CHAR(36)      NOT NULL,
  admin_unit_id      CHAR(36)      NOT NULL,
  status             ENUM('draft','submitted','in_review','approved','rejected','completed','cancelled','returned') NOT NULL DEFAULT 'draft',
  current_step       VARCHAR(64)   NULL,
  assigned_officer_id CHAR(36)     NULL,
  form_data          JSON          NULL,
  submitted_at       TIMESTAMP     NULL,
  completed_at       TIMESTAMP     NULL,
  created_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by         CHAR(36)      NULL,
  CONSTRAINT fk_app_citizen FOREIGN KEY (citizen_id) REFERENCES citizen(id),
  CONSTRAINT fk_app_service FOREIGN KEY (service_catalog_id) REFERENCES service_catalog(id),
  CONSTRAINT fk_app_admin FOREIGN KEY (admin_unit_id) REFERENCES admin_unit(id)
) ENGINE=InnoDB;

CREATE INDEX idx_app_citizen ON application(citizen_id);
CREATE INDEX idx_app_status ON application(status);
CREATE INDEX idx_app_admin_unit ON application(admin_unit_id);

CREATE TABLE IF NOT EXISTS application_step (
  id             CHAR(36)   NOT NULL PRIMARY KEY,
  application_id CHAR(36)   NOT NULL,
  step_id        VARCHAR(64) NOT NULL,
  step_name      VARCHAR(128) NULL,
  status         ENUM('pending','in_progress','completed','rejected','skipped') NOT NULL DEFAULT 'pending',
  started_at     TIMESTAMP  NULL,
  completed_at   TIMESTAMP  NULL,
  officer_id     CHAR(36)   NULL,
  comments       TEXT       NULL,
  CONSTRAINT fk_apstep_app FOREIGN KEY (application_id) REFERENCES application(id)
) ENGINE=InnoDB;

CREATE INDEX idx_apstep_app ON application_step(application_id);

-- ============================================================
-- 5. DOCUMENTS, SIGNATURES & VERIFICATION
-- ============================================================

CREATE TABLE IF NOT EXISTS document (
  id                CHAR(36)     NOT NULL PRIMARY KEY,
  uuid              CHAR(36)     NOT NULL UNIQUE,
  document_number   VARCHAR(32)  NOT NULL UNIQUE,
  document_type     VARCHAR(64)  NOT NULL,
  title             VARCHAR(255) NULL,
  application_id    CHAR(36)     NULL,
  citizen_id        CHAR(36)     NOT NULL,
  issuing_office_id CHAR(36)     NULL,
  file_path_enc     VARBINARY(512) NULL,
  file_hash         CHAR(64)     NULL,
  status            ENUM('draft','submitted','reviewed','approved','signed','issued','verified','revoked','expired') NOT NULL DEFAULT 'draft',
  version           INT          NOT NULL DEFAULT 1,
  verification_code CHAR(14)     NULL UNIQUE,
  issued_at_eth     DATE         NULL,
  issued_at_greg    DATE         NULL,
  expires_at        DATE         NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by        CHAR(36)     NULL,
  CONSTRAINT fk_doc_application FOREIGN KEY (application_id) REFERENCES application(id),
  CONSTRAINT fk_doc_citizen FOREIGN KEY (citizen_id) REFERENCES citizen(id)
) ENGINE=InnoDB;

CREATE INDEX idx_doc_citizen ON document(citizen_id);
CREATE INDEX idx_doc_status ON document(status);

CREATE TABLE IF NOT EXISTS document_version (
  id           CHAR(36)   NOT NULL PRIMARY KEY,
  document_id  CHAR(36)   NOT NULL,
  version_no   INT        NOT NULL,
  file_path_enc VARBINARY(512) NULL,
  created_by   CHAR(36)   NULL,
  created_at   TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_docver_doc FOREIGN KEY (document_id) REFERENCES document(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS digital_signature (
  id               CHAR(36)   NOT NULL PRIMARY KEY,
  document_id      CHAR(36)   NOT NULL,
  signer_user_id   CHAR(36)   NULL,
  signer_name_enc  VARBINARY(512) NULL,
  signature_value  TEXT       NULL,
  certificate_ref  VARCHAR(255) NULL,
  timestamp        TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  hash_algorithm   VARCHAR(32) NOT NULL DEFAULT 'SHA-256',
  document_hash    CHAR(64)   NULL,
  CONSTRAINT fk_sig_doc FOREIGN KEY (document_id) REFERENCES document(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS verification_record (
  id                  CHAR(36)  NOT NULL PRIMARY KEY,
  document_id         CHAR(36)  NULL,
  verified_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verification_method ENUM('qr','code','portal','api') NOT NULL DEFAULT 'code',
  result              ENUM('valid','invalid','revoked','expired') NOT NULL,
  source_ip_hash      CHAR(64)  NULL,
  CONSTRAINT fk_vrec_doc FOREIGN KEY (document_id) REFERENCES document(id)
) ENGINE=InnoDB;

-- ============================================================
-- 6. PAYMENTS
-- ============================================================

CREATE TABLE IF NOT EXISTS payment (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  application_id CHAR(36)    NULL,
  amount        DECIMAL(12,2) NOT NULL,
  currency      VARCHAR(8)   NOT NULL DEFAULT 'ETB',
  status        ENUM('pending','confirmed','failed','refunded','expired') NOT NULL DEFAULT 'pending',
  provider_ref  VARCHAR(128) NULL,
  provider_name VARCHAR(64)  NULL,
  idempotency_key CHAR(64)   NULL UNIQUE,
  initiated_at  TIMESTAMP    NULL,
  confirmed_at  TIMESTAMP    NULL,
  receipt_path_enc VARBINARY(512) NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pay_app FOREIGN KEY (application_id) REFERENCES application(id)
) ENGINE=InnoDB;

CREATE INDEX idx_pay_app ON payment(application_id);
CREATE INDEX idx_pay_status ON payment(status);

-- ============================================================
-- 7. APPOINTMENTS & QUEUE
-- ============================================================

CREATE TABLE IF NOT EXISTS appointment (
  id           CHAR(36)   NOT NULL PRIMARY KEY,
  citizen_id   CHAR(36)   NOT NULL,
  office_id    CHAR(36)   NOT NULL,
  service_catalog_id CHAR(36) NOT NULL,
  slot_start   DATETIME   NOT NULL,
  slot_end     DATETIME   NOT NULL,
  status       ENUM('booked','confirmed','cancelled','completed','no_show') NOT NULL DEFAULT 'booked',
  created_at   TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_appt_citizen FOREIGN KEY (citizen_id) REFERENCES citizen(id),
  CONSTRAINT fk_appt_office FOREIGN KEY (office_id) REFERENCES office(id),
  CONSTRAINT fk_appt_service FOREIGN KEY (service_catalog_id) REFERENCES service_catalog(id)
) ENGINE=InnoDB;

CREATE INDEX idx_appt_office_time ON appointment(office_id, slot_start);

CREATE TABLE IF NOT EXISTS queue_ticket (
  id            CHAR(36)   NOT NULL PRIMARY KEY,
  office_id     CHAR(36)   NOT NULL,
  service_catalog_id CHAR(36) NULL,
  ticket_number INT       NOT NULL,
  citizen_id    CHAR(36)   NULL,
  status        ENUM('waiting','called','completed','no_show','cancelled') NOT NULL DEFAULT 'waiting',
  priority      ENUM('normal','elderly','disabled') NOT NULL DEFAULT 'normal',
  created_at    TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  called_at     TIMESTAMP  NULL,
  completed_at  TIMESTAMP  NULL,
  CONSTRAINT fk_qt_office FOREIGN KEY (office_id) REFERENCES office(id),
  CONSTRAINT fk_qt_service FOREIGN KEY (service_catalog_id) REFERENCES service_catalog(id)
) ENGINE=InnoDB;

CREATE INDEX idx_qt_office_status ON queue_ticket(office_id, status);

-- ============================================================
-- 8. NOTIFICATIONS
-- ============================================================

CREATE TABLE IF NOT EXISTS notification (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  user_id     CHAR(36)     NULL,
  citizen_id  CHAR(36)     NULL,
  channel     ENUM('sms','email','in_app','push') NOT NULL DEFAULT 'in_app',
  template_id VARCHAR(64)  NULL,
  subject     VARCHAR(255) NULL,
  body        TEXT         NULL,
  data_json   JSON         NULL,
  status      ENUM('pending','sent','failed','delivered','read') NOT NULL DEFAULT 'pending',
  sent_at     TIMESTAMP    NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_notif_user ON notification(user_id);
CREATE INDEX idx_notif_status ON notification(status);

CREATE TABLE IF NOT EXISTS notification_template (
  id          VARCHAR(64)  NOT NULL PRIMARY KEY,
  channel     VARCHAR(16)  NOT NULL,
  locale      VARCHAR(16)  NOT NULL DEFAULT 'am',
  subject     VARCHAR(255) NULL,
  body        TEXT         NOT NULL,
  version     INT          NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- 9. COMPLAINTS
-- ============================================================

CREATE TABLE IF NOT EXISTS complaint (
  id                 CHAR(36)   NOT NULL PRIMARY KEY,
  citizen_id         CHAR(36)   NULL,
  category           ENUM('service_delay','officer_behavior','document_error','bribery_fraud','other') NOT NULL DEFAULT 'other',
  description        TEXT       NOT NULL,
  priority           ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  status             ENUM('submitted','acknowledged','in_progress','resolved','rejected') NOT NULL DEFAULT 'submitted',
  assigned_officer_id CHAR(36)  NULL,
  anonymous          TINYINT(1) NOT NULL DEFAULT 0,
  sla_deadline       DATETIME   NULL,
  resolution         TEXT       NULL,
  feedback           ENUM('satisfied','dissatisfied') NULL,
  resolved_at        TIMESTAMP  NULL,
  created_at         TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_comp_citizen FOREIGN KEY (citizen_id) REFERENCES citizen(id)
) ENGINE=InnoDB;

CREATE INDEX idx_comp_status ON complaint(status);
CREATE INDEX idx_comp_citizen ON complaint(citizen_id);

-- ============================================================
-- 10. AUDIT & SECURITY
-- ============================================================

CREATE TABLE IF NOT EXISTS audit_log (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid                CHAR(36)   NOT NULL,
  timestamp           TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id             CHAR(36)   NULL,
  role_id             CHAR(36)   NULL,
  admin_unit_id       CHAR(36)   NULL,
  ip_address          VARCHAR(45) NULL,
  device_id           VARCHAR(128) NULL,
  action              VARCHAR(64) NOT NULL,
  resource_type       VARCHAR(64) NULL,
  resource_id         VARCHAR(64) NULL,
  previous_value_json JSON       NULL,
  new_value_json      JSON       NULL,
  result              ENUM('success','denied','error') NOT NULL DEFAULT 'success',
  reason              VARCHAR(512) NULL
) ENGINE=InnoDB;

CREATE INDEX idx_audit_timestamp ON audit_log(timestamp);
CREATE INDEX idx_audit_user ON audit_log(user_id);
CREATE INDEX idx_audit_action ON audit_log(action);
CREATE INDEX idx_audit_admin_unit ON audit_log(admin_unit_id);

CREATE TABLE IF NOT EXISTS security_event (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  timestamp    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  event_type   VARCHAR(64)  NOT NULL,
  severity     ENUM('info','low','medium','high','critical') NOT NULL DEFAULT 'info',
  source_ip    VARCHAR(45)  NULL,
  user_id      CHAR(36)     NULL,
  details_json JSON         NULL
) ENGINE=InnoDB;

CREATE INDEX idx_sev_timestamp ON security_event(timestamp);
CREATE INDEX idx_sev_type ON security_event(event_type);

-- ============================================================
-- 11. OFFLINE SYNC
-- ============================================================

CREATE TABLE IF NOT EXISTS sync_queue (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id     VARCHAR(128) NOT NULL,
  entity_type   VARCHAR(64)  NOT NULL,
  entity_uuid   CHAR(36)     NOT NULL,
  operation     ENUM('insert','update','delete') NOT NULL,
  payload_json  JSON         NOT NULL,
  status        ENUM('pending','synced','failed','conflict') NOT NULL DEFAULT 'pending',
  retries       INT          NOT NULL DEFAULT 0,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  synced_at     TIMESTAMP    NULL,
  conflict_resolution VARCHAR(32) NULL
) ENGINE=InnoDB;

CREATE INDEX idx_sync_status ON sync_queue(status);
CREATE INDEX idx_sync_device ON sync_queue(device_id);

-- ============================================================
-- 12. RATE LIMITING
-- ============================================================

CREATE TABLE IF NOT EXISTS rate_limit (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  bucket_key VARCHAR(128) NOT NULL,
  window_start TIMESTAMP NOT NULL,
  count      INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_rate_limit (bucket_key, window_start)
) ENGINE=InnoDB;

-- ============================================================
-- 12b. SEQUENCES (atomic per-year counters for document numbers)
-- ============================================================

CREATE TABLE IF NOT EXISTS number_sequence (
  seq_key    VARCHAR(64)  NOT NULL PRIMARY KEY,
  last_num   BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- ============================================================
-- 12c. TOKEN DENYLIST (logout invalidation for stateless JWTs)
-- ============================================================

CREATE TABLE IF NOT EXISTS token_denylist (
  jti        CHAR(36)     NOT NULL PRIMARY KEY,
  user_id    CHAR(36)     NULL,
  expires_at DATETIME     NOT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_deny_expiry (expires_at)
) ENGINE=InnoDB;

-- ============================================================
-- 13. APPEND-ONLY PROTECTION FOR AUDIT
-- ============================================================

CREATE TRIGGER prevent_audit_update BEFORE UPDATE ON audit_log
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only';

CREATE TRIGGER prevent_audit_delete BEFORE DELETE ON audit_log
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only';
