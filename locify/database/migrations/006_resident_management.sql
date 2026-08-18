-- LOCIFY Migration v2.2 — Resident management
-- Households, member records, residence timeline, move-in/move-out history.
-- Additive + idempotent.

USE locify;

-- ============================================================
-- HOUSEHOLDS
-- ============================================================

CREATE TABLE IF NOT EXISTS household (
  id              CHAR(36)     NOT NULL PRIMARY KEY,
  household_no    VARCHAR(32)  NOT NULL UNIQUE,
  admin_unit_id   CHAR(36)     NOT NULL,
  name_enc        VARBINARY(512) NULL,
  village_enc     VARBINARY(512) NULL,
  house_no_enc    VARBINARY(512) NULL,
  head_citizen_id CHAR(36)     NULL,
  status          ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by      CHAR(36)     NULL,
  updated_by      CHAR(36)     NULL,
  CONSTRAINT fk_hh_unit FOREIGN KEY (admin_unit_id) REFERENCES admin_unit(id),
  CONSTRAINT fk_hh_head FOREIGN KEY (head_citizen_id) REFERENCES citizen(id),
  KEY idx_hh_unit (admin_unit_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS household_member (
  id           CHAR(36)   NOT NULL PRIMARY KEY,
  household_id CHAR(36)   NOT NULL,
  citizen_id   CHAR(36)   NOT NULL,
  member_role  ENUM('head','spouse','child','parent','sibling','other') NOT NULL DEFAULT 'other',
  joined_at    DATE       NULL,
  left_at      DATE       NULL,
  created_at   TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by   CHAR(36)   NULL,
  CONSTRAINT fk_hhm_hh FOREIGN KEY (household_id) REFERENCES household(id) ON DELETE CASCADE,
  CONSTRAINT fk_hhm_citizen FOREIGN KEY (citizen_id) REFERENCES citizen(id),
  UNIQUE KEY uq_hhm (household_id, citizen_id),
  KEY idx_hhm_citizen (citizen_id)
) ENGINE=InnoDB;

-- ============================================================
-- RESIDENCE TIMELINE (current + historical residences)
-- ============================================================

CREATE TABLE IF NOT EXISTS residence_record (
  id              CHAR(36)   NOT NULL PRIMARY KEY,
  citizen_id      CHAR(36)   NOT NULL,
  admin_unit_id   CHAR(36)   NOT NULL,
  residence_type  ENUM('primary','secondary','rented','owned','temporary','other') NOT NULL DEFAULT 'primary',
  village_enc     VARBINARY(512) NULL,
  house_no_enc    VARBINARY(512) NULL,
  started_at      DATE       NOT NULL,
  ended_at        DATE       NULL,
  is_current      TINYINT(1) NOT NULL DEFAULT 1,
  created_by      CHAR(36)   NULL,
  created_at      TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rr_citizen FOREIGN KEY (citizen_id) REFERENCES citizen(id),
  CONSTRAINT fk_rr_unit FOREIGN KEY (admin_unit_id) REFERENCES admin_unit(id),
  KEY idx_rr_citizen (citizen_id, is_current),
  KEY idx_rr_unit (admin_unit_id)
) ENGINE=InnoDB;

-- ============================================================
-- MOVE-IN / MOVE-OUT / TRANSFER RECORDS
-- ============================================================

CREATE TABLE IF NOT EXISTS resident_move (
  id                CHAR(36)      NOT NULL PRIMARY KEY,
  citizen_id        CHAR(36)      NOT NULL,
  move_type         ENUM('move_in','move_out','transfer') NOT NULL,
  from_admin_unit_id CHAR(36)     NULL,
  to_admin_unit_id  CHAR(36)      NULL,
  reason            VARCHAR(255)  NULL,
  note              VARCHAR(500)  NULL,
  moved_on          DATE          NOT NULL,
  status            ENUM('recorded','verified','cancelled') NOT NULL DEFAULT 'recorded',
  recorded_by       CHAR(36)      NULL,
  created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rm_citizen FOREIGN KEY (citizen_id) REFERENCES citizen(id),
  CONSTRAINT fk_rm_from FOREIGN KEY (from_admin_unit_id) REFERENCES admin_unit(id),
  CONSTRAINT fk_rm_to FOREIGN KEY (to_admin_unit_id) REFERENCES admin_unit(id),
  KEY idx_rm_citizen (citizen_id, moved_on),
  KEY idx_rm_to (to_admin_unit_id, moved_on)
) ENGINE=InnoDB;

-- ============================================================
-- PERMISSIONS + ROLE GRANTS
-- ============================================================

INSERT IGNORE INTO permission (id, name, resource, action) VALUES
  (UUID(), 'RESIDENT:VIEW',       'resident',     'view'),
  (UUID(), 'RESIDENT:REGISTER',   'resident',     'register'),
  (UUID(), 'MOVE:RECORD',         'move',         'record'),
  (UUID(), 'HOUSEHOLD:VIEW',      'household',    'view'),
  (UUID(), 'HOUSEHOLD:MANAGE',    'household',    'manage');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM role r JOIN permission p ON 1=1
WHERE r.name IN ('registration_officer','kebele_admin','woreda_admin','system_admin')
  AND p.name IN ('RESIDENT:VIEW','RESIDENT:REGISTER','MOVE:RECORD','HOUSEHOLD:VIEW','HOUSEHOLD:MANAGE');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM role r JOIN permission p ON 1=1
WHERE r.name IN ('records_officer','verification_officer','supervisor','document_officer')
  AND p.name IN ('RESIDENT:VIEW','HOUSEHOLD:VIEW');