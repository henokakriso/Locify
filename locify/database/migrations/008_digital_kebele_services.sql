-- 008_digital_kebele_services.sql
-- Digital Kebele Services: rich application lifecycle, status history,
-- correction workflow, service configuration (issuance mode, SLA, fields),
-- supporting-document uploads, print queue, appointment capacity/check-in,
-- document reissue chain, citizen notification templates.
-- Idempotent-safe for the current seed (columns/values that exist are left as-is).

-- ---------------------------------------------------------------------------
-- 1. Service configuration (spec §12, §24, §36, §41)
-- ---------------------------------------------------------------------------
ALTER TABLE service_catalog
    ADD COLUMN service_code VARCHAR(16) NULL AFTER name,
    ADD COLUMN issuance_mode ENUM('DIGITAL_ONLY','PRINT_ONLY','DIGITAL_AND_PRINT','MANUAL_REVIEW')
        NOT NULL DEFAULT 'DIGITAL_ONLY' AFTER required_docs,
    ADD COLUMN requires_appointment ENUM('REQUIRED','OPTIONAL','NONE') NOT NULL DEFAULT 'OPTIONAL'
        AFTER issuance_mode,
    ADD COLUMN requires_approval TINYINT(1) NOT NULL DEFAULT 1 AFTER requires_appointment,
    ADD COLUMN requires_signature TINYINT(1) NOT NULL DEFAULT 1 AFTER requires_approval,
    ADD COLUMN allows_download TINYINT(1) NOT NULL DEFAULT 1 AFTER requires_signature,
    ADD COLUMN allows_sms TINYINT(1) NOT NULL DEFAULT 1 AFTER allows_download,
    ADD COLUMN sla_hours INT NOT NULL DEFAULT 48 AFTER allows_sms,
    ADD COLUMN fields_json JSON NULL AFTER eligibility;

-- ---------------------------------------------------------------------------
-- 2. Application lifecycle (spec §5, §17, §40)
-- ---------------------------------------------------------------------------
ALTER TABLE application
    MODIFY status ENUM('draft','submitted','received','document_check','verification',
        'officer_review','approved','rejected','needs_correction','cancelled','on_hold',
        'payment_required','payment_verified','review_required','document_generation',
        'printing','ready_for_collection','digitally_delivered','completed','returned')
        NOT NULL DEFAULT 'draft',
    ADD COLUMN status_notes VARCHAR(512) NULL AFTER status,
    ADD COLUMN correction_deadline DATETIME NULL AFTER status_notes,
    ADD COLUMN correction_submitted_at DATETIME NULL AFTER correction_deadline,
    ADD COLUMN due_at DATETIME NULL AFTER correction_submitted_at,
    ADD COLUMN priority ENUM('low','normal','high','critical') NOT NULL DEFAULT 'normal'
        AFTER due_at,
    ADD COLUMN office_id CHAR(36) NULL AFTER assigned_officer_id,
    ADD COLUMN requested_document_id CHAR(36) NULL AFTER office_id,
    ADD KEY idx_app_dup (citizen_id, service_catalog_id, status),
    ADD KEY idx_app_due (due_at, status);

-- ---------------------------------------------------------------------------
-- 3. Append-only application status history (spec §31, §32)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS application_status_history (
    id               CHAR(36) PRIMARY KEY,
    application_id   CHAR(36) NOT NULL,
    status           VARCHAR(40) NOT NULL,
    previous_status  VARCHAR(40) NULL,
    actor_user_id    CHAR(36) NULL,
    notes            VARCHAR(512) NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ash_app (application_id, created_at),
    CONSTRAINT fk_ash_application FOREIGN KEY (application_id) REFERENCES application(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. Supporting documents (spec §34, §39, §55)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS application_documents (
    id                    CHAR(36) PRIMARY KEY,
    application_id        CHAR(36) NOT NULL,
    document_type         VARCHAR(64) NOT NULL,
    file_reference        VARCHAR(255) NOT NULL,
    original_filename_enc VARBINARY(1024) NULL,
    mime_type             VARCHAR(128) NOT NULL,
    size_bytes            INT UNSIGNED NOT NULL,
    uploaded_by           CHAR(36) NULL,
    uploaded_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    verification_status   ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    verified_by           CHAR(36) NULL,
    verified_at           DATETIME NULL,
    KEY idx_ad_application (application_id),
    CONSTRAINT fk_ad_application FOREIGN KEY (application_id) REFERENCES application(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. Document record: printing, collection, reissue chain (spec §25, §35, §46)
-- ---------------------------------------------------------------------------
ALTER TABLE document
    MODIFY status ENUM('draft','submitted','reviewed','approved','signed','printed',
        'issued','verified','revoked','expired') NOT NULL DEFAULT 'draft',
    ADD COLUMN print_required TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN printed_at DATETIME NULL AFTER print_required,
    ADD COLUMN printed_by CHAR(36) NULL AFTER printed_at,
    ADD COLUMN collected_at DATETIME NULL AFTER printed_by,
    ADD COLUMN original_document_id CHAR(36) NULL AFTER application_id,
    ADD KEY idx_doc_original (original_document_id);

-- ---------------------------------------------------------------------------
-- 6. Print queue (spec §25, §26)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS print_jobs (
    id               CHAR(36) PRIMARY KEY,
    document_id      CHAR(36) NOT NULL,
    job_number       VARCHAR(32) NOT NULL UNIQUE,
    reason           ENUM('original','copy','reissue','duplicate') NOT NULL DEFAULT 'original',
    reprint_reason   VARCHAR(255) NULL,
    operator_user_id CHAR(36) NULL,
    status           ENUM('queued','printing','printed','quality_failed','cancelled')
                     NOT NULL DEFAULT 'queued',
    attempts         INT NOT NULL DEFAULT 0,
    print_started_at DATETIME NULL,
    printed_at       DATETIME NULL,
    printed_by       CHAR(36) NULL,
    created_by       CHAR(36) NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pj_document (document_id),
    CONSTRAINT fk_pj_document FOREIGN KEY (document_id) REFERENCES document(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 7. Appointments: number + capacity + check-in (spec §18-§20)
-- ---------------------------------------------------------------------------
ALTER TABLE appointment
    ADD COLUMN appointment_number VARCHAR(32) NULL AFTER id,
    MODIFY status ENUM('booked','confirmed','checked_in','in_service','completed',
        'missed','cancelled','rescheduled') NOT NULL DEFAULT 'booked',
    ADD COLUMN checked_in_at DATETIME NULL AFTER status,
    ADD COLUMN completed_at DATETIME NULL AFTER checked_in_at;

ALTER TABLE office
    ADD COLUMN working_days VARCHAR(64) NULL AFTER working_hours,   -- e.g. "1,2,3,4,5"
    ADD COLUMN holidays_json JSON NULL AFTER working_days,
    ADD COLUMN max_daily_appointments INT UNSIGNED NULL AFTER capacity;

-- ---------------------------------------------------------------------------
-- 8. Notification templates for lifecycle events (spec §21)
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO notification_template (id, channel, locale, subject, body)
SELECT 'app_correction', 'sms', 'en',
       'Action required: your application needs correction',
       'Your application {service_id} needs correction. Reason: {reason} Deadline: {deadline}'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notification_template WHERE id = 'app_correction' AND locale = 'en');

INSERT IGNORE INTO notification_template (id, channel, locale, subject, body)
SELECT 'app_approved', 'sms', 'en',
       'Your application has been approved',
       'Your application {service_id} for {service_name} has been approved. Your document is being prepared.'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notification_template WHERE id = 'app_approved' AND locale = 'en');

INSERT IGNORE INTO notification_template (id, channel, locale, subject, body)
SELECT 'app_ready', 'sms', 'en',
       'Your document is ready',
       'Your document for application {service_id} is ready. Delivery: Kebele office collection.'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notification_template WHERE id = 'app_ready' AND locale = 'en');

INSERT IGNORE INTO notification_template (id, channel, locale, subject, body)
SELECT 'app_completed', 'sms', 'en',
       'Your application has been completed',
       'Your application {service_id} is completed. Thank you.'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notification_template WHERE id = 'app_completed' AND locale = 'en');

INSERT IGNORE INTO notification_template (id, channel, locale, subject, body)
SELECT 'app_rejected', 'sms', 'en',
       'Your application was not approved',
       'Your application {service_id} was rejected. Reason: {reason}'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notification_template WHERE id = 'app_rejected' AND locale = 'en');

-- ---------------------------------------------------------------------------
-- 9. Seed configuration for existing flagship services (spec §36)
-- ---------------------------------------------------------------------------
UPDATE service_catalog SET service_code = 'RES',
    issuance_mode = 'DIGITAL_AND_PRINT', requires_appointment = 'OPTIONAL',
    requires_approval = 1, requires_signature = 1, allows_download = 1, allows_sms = 1,
    sla_hours = 48,
    fields_json = JSON_ARRAY(
        JSON_OBJECT('name','previous_name','label','Previous name (if applicable)','type','text','required',FALSE),
        JSON_OBJECT('name','occupation','label','Occupation','type','text','required',FALSE),
        JSON_OBJECT('name','region','label','Region','type','text','required',TRUE),
        JSON_OBJECT('name','zone','label','Zone','type','text','required',FALSE),
        JSON_OBJECT('name','city','label','City','type','text','required',FALSE),
        JSON_OBJECT('name','sub_city','label','Sub-city','type','text','required',FALSE),
        JSON_OBJECT('name','woreda','label','Woreda','type','text','required',TRUE),
        JSON_OBJECT('name','kebele','label','Kebele','type','text','required',TRUE),
        JSON_OBJECT('name','house_number','label','House number','type','text','required',TRUE),
        JSON_OBJECT('name','specific_location','label','Specific location','type','text','required',FALSE),
        JSON_OBJECT('name','length_of_residence','label','Length of residence','type','text','required',FALSE),
        JSON_OBJECT('name','certificate_purpose','label','Certificate purpose','type','select','required',TRUE,
            'options',JSON_ARRAY('Employment','Education','Banking','Government service','Legal requirement','Housing','Other'))
    )
WHERE name = 'Residence Certificate' AND service_code IS NULL;

UPDATE service_catalog SET service_code = 'LET',
    issuance_mode = 'DIGITAL_ONLY', requires_appointment = 'NONE',
    requires_approval = 1, requires_signature = 1, allows_download = 1, allows_sms = 1,
    sla_hours = 24,
    fields_json = JSON_ARRAY(
        JSON_OBJECT('name','letter_type','label','Letter type','type','select','required',TRUE,
            'options',JSON_ARRAY('Confirmation letter','Introduction letter','Residence-related letter','Local administrative confirmation','Family-related administrative document','Other')),
        JSON_OBJECT('name','purpose','label','Purpose','type','text','required',TRUE),
        JSON_OBJECT('name','recipient','label','Recipient organization','type','text','required',FALSE),
        JSON_OBJECT('name','description','label','Description','type','textarea','required',FALSE),
        JSON_OBJECT('name','delivery_method','label','Preferred delivery','type','select','required',FALSE,
            'options',JSON_ARRAY('Digital download','Kebele office collection','Both'))
    )
WHERE name = 'Local Letter' AND service_code IS NULL;

UPDATE service_catalog SET service_code = 'RES', issuance_mode = 'DIGITAL_AND_PRINT', sla_hours = 48
WHERE name = 'Proof of Residence' AND service_code IS NULL;
UPDATE service_catalog SET service_code = 'BIR', issuance_mode = 'DIGITAL_AND_PRINT', sla_hours = 48
WHERE name = 'Birth Certificate Issuance' AND service_code IS NULL;
UPDATE service_catalog SET service_code = 'MAR', issuance_mode = 'DIGITAL_AND_PRINT', sla_hours = 72
WHERE name = 'Marriage Certificate' AND service_code IS NULL;

-- 10. Document Copy / Reissue service (spec §13-§14)
SET @woreda = '4ca5f0bb-9901-11f1-b7a6-98af65be3926';
SET @wf_res = '1040ea6d-9908-11f1-b7a6-98af65be3926';
INSERT IGNORE INTO service_catalog
    (id, name, local_name, service_code, description, eligibility, required_docs,
     issuance_mode, requires_appointment, requires_approval, requires_signature,
     allows_download, allows_sms, sla_hours, workflow_id, admin_unit_id, fee_amount, currency, is_active)
VALUES
    ('3f8c01d0-0008-11f1-b7a6-98af65be3926', 'Document Copy / Reissue', 'የሰነድ ቅጂ',
     'DOC', 'Request a copy or reissue of an already issued document.',
     '["has_previous_document"]', '["identity_document"]',
     'DIGITAL_ONLY', 'OPTIONAL', 1, 1, 1, 1, 24,
     @wf_res, @woreda, 10.00, 'ETB', 1);