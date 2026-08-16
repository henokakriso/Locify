-- LOCIFY Migration v2.0 — Collaboration features
-- Chat, institutions, document links. Idempotent.

USE locify;

-- ============================================================
-- 14. CHAT (citizen ↔ office conversations)
-- ============================================================

CREATE TABLE IF NOT EXISTS conversation (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  citizen_id    CHAR(36)     NOT NULL,
  admin_unit_id CHAR(36)     NOT NULL,
  subject       VARCHAR(255) NOT NULL,
  status        ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_conv_citizen FOREIGN KEY (citizen_id) REFERENCES citizen(id),
  CONSTRAINT fk_conv_unit FOREIGN KEY (admin_unit_id) REFERENCES admin_unit(id),
  KEY idx_conv_citizen (citizen_id),
  KEY idx_conv_unit (admin_unit_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS message (
  id              CHAR(36)     NOT NULL PRIMARY KEY,
  conversation_id CHAR(36)     NOT NULL,
  sender_id       CHAR(36)     NOT NULL,
  sender_role     ENUM('citizen','officer') NOT NULL,
  body_enc        VARBINARY(1024) NOT NULL,
  is_read         TINYINT(1)   NOT NULL DEFAULT 0,
  read_at         DATETIME     NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id) REFERENCES conversation(id) ON DELETE CASCADE,
  KEY idx_msg_conv (conversation_id, created_at)
) ENGINE=InnoDB;

-- ============================================================
-- 15. GOVERNMENT INSTITUTIONS (cross-kebele / national)
-- ============================================================

CREATE TABLE IF NOT EXISTS institution (
  id             CHAR(36)     NOT NULL PRIMARY KEY,
  name           VARCHAR(255) NOT NULL,
  short_name     VARCHAR(64)  NULL,
  category       ENUM('kebele','woreda','zone','region','federal_agency','ministry','other_gov') NOT NULL DEFAULT 'other_gov',
  admin_unit_id  CHAR(36)     NULL,
  contact        VARCHAR(255) NULL,
  api_token_hash CHAR(64)     NULL,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inst_unit FOREIGN KEY (admin_unit_id) REFERENCES admin_unit(id),
  UNIQUE KEY uq_inst_name (name),
  KEY idx_inst_token (api_token_hash)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS institution_document_request (
  id             CHAR(36)     NOT NULL PRIMARY KEY,
  institution_id CHAR(36)     NOT NULL,
  document_id    CHAR(36)     NOT NULL,
  purpose        VARCHAR(500) NOT NULL,
  status         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  decided_by     CHAR(36)     NULL,
  decided_at     DATETIME     NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_idr_inst FOREIGN KEY (institution_id) REFERENCES institution(id),
  CONSTRAINT fk_idr_doc FOREIGN KEY (document_id) REFERENCES document(id),
  KEY idx_idr_status (institution_id, status)
) ENGINE=InnoDB;

-- ============================================================
-- 16. DOCUMENT LINKS (related records across services)
-- ============================================================

CREATE TABLE IF NOT EXISTS document_link (
  id             CHAR(36)     NOT NULL PRIMARY KEY,
  source_document_id CHAR(36) NOT NULL,
  target_document_id CHAR(36) NOT NULL,
  relation       VARCHAR(64)  NOT NULL DEFAULT 'related',
  created_by     CHAR(36)     NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dl_source FOREIGN KEY (source_document_id) REFERENCES document(id) ON DELETE CASCADE,
  CONSTRAINT fk_dl_target FOREIGN KEY (target_document_id) REFERENCES document(id) ON DELETE CASCADE,
  UNIQUE KEY uq_dl_pair (source_document_id, target_document_id, relation)
) ENGINE=InnoDB;