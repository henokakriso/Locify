# LOCIFY — Digital Kebele Platform

LOCIFY is a lightweight, secure and offline-capable **Digital Kebele platform**
built for Ethiopian government environments, including low-resource and
low-connectivity infrastructure.

## Purpose

LOCIFY digitizes the work of Kebele administrations:

- Citizen management
- Kebele management and administrative hierarchy
- User management, authentication, authorization, RBAC
- Service management and applications
- Workflows
- Document management and digital document verification
- Appointments and queue management
- Complaints
- Payments
- Reports and audit logging
- Offline Kebele local servers with C-based synchronization

One Kebele's data is isolated — an administrator from one Kebele cannot access
another Kebele's data.

## Technology Stack

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

## Repository Layout

```
locify/
├── api/        REST API (auth, citizens, kebeles, services, documents, …)
├── app/        PHP application
├── bin/        C binaries (locify)
├── c/          C components (sync, devices, security, services)
├── config/     Configuration
├── database/   Schema and migrations
├── public/     Web root
├── storage/    Storage
├── tests/      Test suites
└── views/      Views
```

## License

MIT — see [LICENSE](LICENSE).