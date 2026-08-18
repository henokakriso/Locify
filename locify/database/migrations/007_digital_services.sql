-- 007_digital_services.sql
-- Digital Kebele services: seed the flagship digital services
-- ("Residence Certificate", "Local Letter") on the Addis Ketema woreda unit,
-- bound to the active "Proof of Residence" workflow. Tables were created in
-- the base schema (seed.sql); this adds the catalog entries only.
-- Idempotent: INSERT IGNORE on fixed UUIDs.

SET @woreda     = '4ca5f0bb-9901-11f1-b7a6-98af65be3926';
SET @wf_res     = '1040ea6d-9908-11f1-b7a6-98af65be3926';  -- Proof of Residence (active, v1)

INSERT IGNORE INTO service_catalog
    (id, name, local_name, description, eligibility, required_docs, workflow_id,
     admin_unit_id, fee_amount, currency, slot_duration_min, is_active)
VALUES
    ('3f8a9c01-0007-11f1-b7a6-98af65be3926', 'Residence Certificate', 'የመኖሪያ ማስረጃ',
     'Digital residence certificate confirming a citizen''s place of residence in the kebele.',
     '["resident_of_record"]',
     '["identity_document", "proof_of_residence"]',
     @wf_res, @woreda, 25.00, 'ETB', 20, 1),
    ('3f8ab1c2-0007-11f1-b7a6-98af65be3926', 'Local Letter', 'የአካባቢ ደብዳቤ',
     'Official local supporting letter / certificate of the kebele administration.',
     '[]',
     '["identity_document", "purpose_document"]',
     @wf_res, @woreda, 10.00, 'ETB', 15, 1);