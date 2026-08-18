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

- Citizen management
- Kebele management and administrative hierarchy
- User management, authentication, authorization, RBAC
- Service management and applications with workflows
- Document management and digital document verification
- Appointments and queue management
- Complaints
- Payments
- Reports and audit logging
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
├── app/        PHP application
├── bin/        C binaries (locify)
├── c/          C components (sync, devices, security, services)
├── config/     Configuration
├── database/   Schema (schema.sql), migrations, seeds
├── public/     Web root
├── storage/    Storage
├── tests/      Test suites
└── views/      Views
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

Requirements: PHP, a C compiler (`make`, `gcc`) and MySQL/MariaDB.

```bash
# 1. Create the database
mysql -u root -p -e "CREATE DATABASE locify CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p locify < database/schema.sql
# apply database/migrations/* in order, then load database/seeds/*

# 2. Build the C layer (sync, devices, security)
make -C c

# 3. Serve the web app
php -S 127.0.0.1:8081 -t public public/index.php
```

Adjust DB credentials in `config/config.php` to match your server.

## Usage

Open the served URL in a browser and sign in with a seeded Kebele
administrator (see `database/seeds/` for accounts and passwords). From the
dashboard:

- Manage **citizens** and the **Kebele hierarchy** (zone → woreda → kebele)
- Run **services and applications** through their workflows
- Handle **documents** and verify digitally issued documents
- Run **appointments/queue**, **complaints** and **payments**
- Use the **offline LAN mode** on low-connectivity sites — C utilities keep
  local servers consistent and sync when connectivity returns

Run the suites under `tests/` to verify the deployment.

## Security

- **Strict data isolation** — each Kebele's data is a separate boundary; cross-
  Kebele access is denied by construction.
- **RBAC** — role-based control over citizen, service, payment and
  administrative actions.
- **C-based security utilities** — cryptographic and integrity operations
  execute in the system layer.
- **Audit logging** — administrative and service actions are recorded for
  accountability.
- **Offline-first** — no dependency on external cloud services or third-party
  runtimes.

## Screenshots

Screenshots will be added here as the interface is finalized.

## Roadmap

- Field deployment kit: assisted LAN setup and offline sync bootstrapping
- Digital signature flows for Kebele-issued certificates
- Arabic/Amharic localization pass
- Inter-Kebele electronic services and verification
- Integration with sibling ARWE platforms (TerraChain, Govyx, Edunex)

## License

Apache License, Version 2.0 — see [LICENSE](LICENSE) and [NOTICE](NOTICE).

Copyright 2026 henokakriso. "Locify" and "ARWE" are trademarks of the ARWE project; trademark use is governed by Section 6 of the Apache License, Version 2.0.