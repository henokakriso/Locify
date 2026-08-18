# LOCIFY — Digital Kebele Platform

## Overview

LOCIFY is a lightweight, secure and offline-capable **Digital Kebele platform**
built for Ethiopian government environments — including low-resource and
low-connectivity infrastructure. It digitizes the full range of Kebele
administration work on a self-contained stack of PHP, C and MySQL with strict
data isolation between Kebeles.

## Problem

Kebele administrations run on paper and disconnected tools: citizens queue
for certificates and documents, services and complaints are hard to track,
appointments are managed manually, and remote Kebeles have unreliable
connectivity. Centralized cloud platforms are out of reach for low-bandwidth
areas, and typical frameworks over-consume scarce hardware.

## Solution

LOCIFY digitizes Kebele operations with a web interface plus an **offline
local-server architecture**: each Kebele runs its own instance, and C-based
synchronization keeps copies consistent when connectivity returns.

- **One Kebele, one isolated dataset** — an administrator from one Kebele can
  never access another Kebele's data.
- **Full service lifecycle** — citizens, services, applications and workflows,
  document management with digital verification, appointments and queue
  management, complaints, payments.
- **C services for synchronization, device handling and security**, keeping
  low-connectivity operations working locally and syncing later.

## Features

- **Digital Kebele Services (DKS) full lifecycle** — dynamic service catalog
  (per-service fields, issuance modes, SLA timers, notification templates),
  applications with duplicate + citizen-verification guards, Service IDs for
  public tracking, and a step-driven workflow engine
    (`next | approve | reject | cancel | hold | resume | mark-ready | complete
    | request-correction | return | submit-correction`)
- **Citizen self-service** — rate-limited public registration (E.C. DOB,
  kebele picker, password policy) and a citizen portal: track application by
  Service ID, profile with masked NID/phone, dynamic forms, Fix & resubmit,
  notification badge
- **Document issuing & reissue** — service-aware numbers `LOC-{y}-{unit}-{svc}
  -{seq}`, print-required documents, issue with E.C./G.C. dates, digital
  delivery, `requested_document_id` reissue flow from the citizen's own printed
  documents
- **Print jobs** — queued with reason/reprint, 3-attempt cap, start/complete/
  fail/cancel lifecycle and JOB_EXISTS guard
- **Attachment uploads** — finfo-validated PDF/JPG/PNG (≤ 8 MB), encrypted at
  rest, officer verify/reject review
- **Appointments & queue** — office daily capacity (`CAPACITY_REACHED`), book
  on behalf, check-in → finish (complete/missed)
- **Resident management** — registration, verification and resident records
- **Amharic/SMS notifications** — status changes push through
  NotificationService (audit + per-event templates)
- **TOTP two-factor authentication** — RFC 6238 with QR setup, hashed
  one-time recovery codes, optional `MFA_ENFORCED`
- Citizen management and **Kebele administrative hierarchy** (zone → woreda →
  kebele) with RBAC and admin-unit scoping
- Payments (mock mode until an official integration is approved), reports and
  append-only audit logging
- Offline Kebele local servers with C-based synchronization

## Architecture

```
                  LOCIFY
                     │
        ┌────────────┴────────────┐
        │                         │
     PHP API                  C SERVICES
        │                         │
        │                ┌────────┼────────┐
        │                │        │        │
     Database          Sync    Devices  Security
        │
        │
   MySQL/MariaDB
```

```
locify/
├── api/        REST API (auth, citizens, kebeles, services, documents, …)
├── app/        PHP application (core, security, middleware, services, controllers, helpers)
├── bin/        CLI administration tool (db:setup/backup/status, admin:create, tests)
├── c/          C services (offline sync agent, security integrity monitor)
├── config/     Configuration (.env)
├── database/   Schema (schema.sql), migrations (005–009), seeds
├── public/     Web root (front controller, assets, views)
├── storage/    Local data (encrypted artifacts, backups, SQLite sync queue)
├── tests/      Test suites
└── views/      Views (public, citizen, admin)
```

## Technology

```
LOCIFY
│
├── Frontend:  HTML, CSS, Pure JavaScript
├── Backend:   PHP
├── Systems:   C
└── Database:  MySQL / MariaDB
```

The core application stays within `.c .php .html .css .js` — no major
frameworks.

## Installation

Requirements: PHP 8.2+ (`pdo_mysql`, `openssl`, `mbstring`), MySQL 8.x /
MariaDB 10.6+, and optionally `gcc`/`libcurl`/`libssl-dev` for the C services.

```bash
# 1. Configure
cp .env.example .env        # set APP_KEY, JWT_SECRET, DB_* credentials

# 2. Create the database (schema + seed, idempotent)
php bin/locify db:setup

# 3. Create the system administrator
php bin/locify admin:create   # 12+ character password

# 4. Serve the web app (all requests through public/index.php)
php -S 127.0.0.1:8080 -t public public/index.php
```

Available CLI commands: `db:setup`, `db:backup` (gzip mysqldump into
`storage/backups`), `db:status` (health + row counts), `admin:create`,
`payments:reconcile`, `test:calendar`, `test:totp`.

## Usage

Open the served URL in a browser and sign in with the administrator created
above (or a seeded Kebele administrator — see `database/seeds/`). From the
dashboard:

- **Registration → verification** — citizens self-register at `/register`;
  officers verify them, then they can apply for services at their kebele
- **DKS lifecycle** — apply (dynamic catalog forms) → track by Service ID →
  corrections (fix & resubmit) → hold/resume → mark-ready → document sign/
  issue with GNKS code verification → print cycle → complete → appointment
  check-in/finish, with Amharic/SMS notifications at every step
- Manage **citizens/residents** and the **Kebele hierarchy**
- Handle **documents, print jobs and attachment reviews** (verify/reject)
- Run **appointments/queue**, **complaints** and **payments**
- Use the **offline LAN mode** on low-connectivity sites — C utilities keep
  local servers consistent and sync when connectivity returns

Run the suites under `tests/` (calendar, TOTP) to verify the deployment.

## Security

- **Strict data isolation** — each Kebele's data is a separate boundary; cross-
  Kebele access is denied by construction (admin-unit subtree scope).
- **Two-factor authentication** — per-account TOTP (RFC 6238, 30 s, 6 digits)
  with QR setup, ten one-time recovery codes (stored hashed), and a 3-minute
  login challenge; `MFA_ENFORCED=true` blocks login until setup completes.
- **RBAC** — every protected route requires a permission, checked against the
  actor's administrative scope.
- **Encryption at rest** — citizen fields are AES-256-GCM encrypted with
  per-record random nonces (`APP_KEY`); uploads are encrypted on disk.
- **CSRF defense-in-depth** — state-changing browser requests are rejected
  unless the `Origin` matches the configured application URL.
- **Rate limiting** — login 10/min, MFA 5/min, import 5/min, officers
  300/min, public 30/min; client IPs honored only behind `TRUST_PROXY`.
- **CSV safety** — import validates every row (duplicates reported, not
  aborted); export neutralizes spreadsheet formula injection.
- **Audit immutability** — `audit_log` and `security_event` are append-only,
  enforced by database triggers (failed logins, permission denials, tampering,
  cross-origin rejections are logged continuously).
- **Offline-first** — no dependency on external cloud services or third-party
  runtimes.

## Screenshots

Screenshots will be added here as the interface is finalized.

## Roadmap

- Field deployment kit: assisted LAN setup and offline sync bootstrapping
- Expand seeded service catalog (additional certificate types and fees)
- Arabic/Amharic localization pass
- Inter-Kebele electronic services and verification
- Integration with sibling ARWE platforms (TerraChain, Govyx, Edunex)

## License

ARWE Public Source License (ARWE-PSL) v1.0 — see [LICENSE](LICENSE) and [NOTICE](NOTICE).

Copyright © 2026 Henok Akriso. All rights reserved. Developer / Project Alias: Sergio — Founder of Halziz. "Locify" and "ARWE" are trademarks of the ARWE project; see the license for trademark terms.