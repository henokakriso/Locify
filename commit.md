# LOCIFY — Commit Log (2026-08-18)

## Digital Kebele Services (DKS) — full lifecycle

### Backend — new endpoints & engine
- **Migrations applied**: `008_digital_kebele_services.sql` (service_catalog issuance modes/SLA/notification templates, application_status_history, application_documents, print_jobs), `009_dks_permissions.sql` (PRINT:OPERATE for kebele_admin/system_admin).
- **WorkflowService rewritten**: step-driven transitions, actions `next|approve|reject|cancel|hold|resume|mark-ready|complete|request-correction|return|submit-correction`; last-active-status resume; single write point `applyStatus` (status + append-only history + audit + Amharic SMS notification); `start()` records the real first-step status.
- **Number helpers** (spec §4): `nextServiceNumber`, `nextDocumentNumber(?serviceCode)`, `nextAppointmentNumber`, `nextPrintJobNumber` → LOC-{y}-{unit}-{svc}-{seq} formats.
- **ServiceController**: dynamic catalog (fields/mode/SLA, citizen ancestor scope), application create with duplicate guard, citizen verification guard, SLA `due_at`, DOC reissue via `requested_document_id`; `show()` by uuid or Service ID (tracking, spec §15) with history/attachments/overdue/correction payload; `advance()` incl. citizen `submit-correction`.
- **DKSControllers**: PortalController (kebele list, rate-limited self-registration, profile), PrintController (queue, create with reason/reprint, update start/complete/fail/cancel, 3-attempt cap, JOB_EXISTS guard), UploadController (finfo-validated PDF/JPG/PNG ≤8 MB, encrypted at rest, verify/reject review).
- **Auth**: `unitAncestorIds` + `assertResourceScope` — citizens may act on their own kebele's ancestor chain.
- **DocumentService**: service-aware document numbers, print_required, issuing office from citizen address, original_document_id.
- **Completion**: document → `issued` with E.C./G.C. issue dates + `collected_at`; digital delivery stamped on complete.
- **Appointments**: `appointment_number`, office daily capacity (`CAPACITY_REACHED`), book-on-behalf, check-in → finish (complete/missed).

### Frontend — ports
- **Public registration** `/register` + login link; E.C. DOB, kebele picker, password policy, rate limiter.
- **Citizen portal**: Track by Service ID, My Profile (masked NID/phone), dynamic forms from `fields_json`, details with timeline + overdue/correction deadline + attachment upload (FormData) + verify links, Fix & resubmit with prefilled editable form, notifications badge.
- **Admin digital.js**: Print queue tab, doc-row Print, app detail history timeline + attachment verify/reject, Hold/Resume/Request correction (deadline)/Mark ready/Complete, appointments check-in/finish, reissue UUID field.

### Form definitions
- Seeded `fields_json` for **every** catalog service: BIR (8), MAR (6), RES Proof of Residence (4) + Residence Certificate (12), DOC copy/reissue (4), Test service (3).
- New `document` field type: refund selects the citizen's own issued/printed docs → `requested_document_id`.

### Fixes this session
- Correction resubmission persists edited `form_data` (not just comments).
- `.alert-gold` + status `.timeline` styles added.
- Print-job update verb corrected (POST), JS history keys vs API (`previous_status`), async form builder ordering, `requested_document_id` in `show()` payload.

### Verified
- Full path: registration → verify → apply (RES/LET/DOC/BIR) → Service IDs → duplicate guard → correction cycle → mark-ready → document sign/issue (GNKS code verify) → print cycle → complete → appointments with capacity guard → check-in/finish → Amharic/SMS notifications.
- Upload security: evil.exe rejected, >8 MB rejected, PDF verified by officer.
- All PHP/JS lint clean; `db:status` ok.