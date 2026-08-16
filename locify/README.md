# LOCIFY — Digital Kebele Government Services

LOCIFY is a modular monolith for Project ARWE: digital government services at the
kebele (ward) level of Ethiopia. It is built strictly on the mandated stack —
**C, PHP, HTML, CSS, Pure JavaScript, MySQL/MariaDB** — with no frameworks.

```
public/        Web root (front controller, assets, views)
app/           PHP application (core, security, middleware, services, helpers)
api/routes.php API v1 route table
database/      Schema + seed data
c/             C services (offline sync agent, security integrity monitor)
storage/       Local data (encrypted artifacts, SQLite sync queue)
bin/           CLI administration tool
tests/         Calendar test suite
```

## Requirements

- PHP 8.2+ with `pdo_mysql`, `openssl`, `mbstring`
- MySQL 8.x or MariaDB 10.6+ (with triggers enabled)
- `gcc`, `libcurl`, `libssl-dev` for the C services (optional)
- Apache or Nginx for production; `php -S` for development

## Quick start

```bash
cp .env.example .env        # then set APP_KEY, JWT_SECRET, DB_* credentials
php bin/locify db:setup     # idempotent: loads schema + seed data
php bin/locify admin:create # create the system administrator (12+ char password)

# development server (all requests go through public/index.php)
php -S 127.0.0.1:8080 -t public public/index.php
```

Login at `http://127.0.0.1:8080/login` with the administrator created above.

## Configuration (`.env`)

| Key | Purpose |
| --- | --- |
| `DB_*` | MySQL/MariaDB connection |
| `APP_KEY` | AES-256-GCM envelope encryption key (base64, 32+ bytes) |
| `JWT_SECRET` | HMAC-SHA256 signing secret (base64, 32+ bytes) |
| `PAYMENT_MODE` | `mock` until an official integration is approved |
| `APP_DEBUG` | `true` in development only |

Keys must be rotated without re-encrypting stored data: sensitive citizen fields
are stored as `*_enc` VARBINARY blobs, and app-level encryption uses per-record
random nonces, so only new writes use a new key.

## Architecture notes

- **RBAC**: every protected route requires a permission; all actions are also
  checked against the actor's administrative scope (admin unit subtree), so a
  kebele admin cannot touch another kebele's records.
- **Audit immutability**: `audit_log` and `security_event` are append-only,
  enforced by database triggers; `AuditService` writes masked/encrypted values.
- **Identity protection**: national ID stored as SHA-256 hash + masked value;
  names, phones, addresses encrypted app-level; verification endpoints never
  return personal data.
- **Ethiopian calendar**: dual dating via `app/helpers/Calendar.php`
  (Beyene–Kudlek algorithm), verified against known anchors in
  `tests/test_calendar.php`.
- **Offline sync**: the C sync agent (`c/sync/sync_agent.c`) keeps local
  operations available and reconciles queued mutations with the central API
  when connectivity returns. Build with `make -C c`.
- **Integrity monitoring**: `c/security/security_monitor.c` maintains a SHA-256
  manifest of application files and alerts on tampering. Build with
  `make -C c`.
- **Payments / e-signature / national ID**: mocked behind adapters until
  official integrations are approved (`REQUIRES OFFICIAL INTEGRATION`).

## CLI

```
php bin/locify db:setup            # load schema + seed (idempotent)
php bin/locify admin:create        # create/reset system administrator
php bin/locify payments:reconcile  # reconcile mock payments
php bin/locify test:calendar       # verify Ethiopian calendar conversions
```

## API

Full route table in `api/routes.php`. Highlights:

| Method | Path | Access |
| --- | --- | --- |
| POST | `/api/v1/auth/login` | public (rate-limited 10/min) |
| POST | `/api/v1/auth/refresh` | refresh token |
| GET | `/api/v1/auth/me` | authenticated |
| POST | `/api/v1/citizens` | `CITIZEN:CREATE` |
| POST | `/api/v1/citizens/{uuid}/verify` | `CITIZEN:VERIFY_INITIATE` |
| GET | `/api/v1/services` | authenticated |
| POST | `/api/v1/services/applications` | `APPLICATION:CREATE` |
| PUT | `/api/v1/services/applications/{uuid}/step` | `APPLICATION:PROCESS` |
| POST | `/api/v1/documents` | `DOCUMENT:CREATE` |
| POST | `/api/v1/documents/{uuid}/sign` | `DOCUMENT:SIGN` |
| GET | `/api/v1/documents/verify?code=` | **public**, no PII |
| GET | `/api/v1/audit-logs` | `AUDIT:VIEW` |

Access tokens live 15 minutes; refresh tokens 30 days. Tokens are held in
memory in the browser (no localStorage/cookies).

## Deployment

- Point the web root at `public/`; the Apache config sample in
  `config/apache.conf.example` blocks direct access to `app/`, `api/`,
  `database/`, `storage/`, `views/`, `bin/`.
- Serve over TLS in production; terminate at Apache/Nginx.
- Run `c/security/security_monitor` under a supervisor for file integrity
  checks, and `c/sync/sync_agent` on remote offices for offline operation.
- `APP_DEBUG` must be `false` in production.

## Testing

```bash
php tests/test_calendar.php        # calendar conversions (11 anchors + round trips)
# E2E smoke test against a running server:
#   - reset DB to seed state (php bin/locify db:setup)
#   - start dev server
#   - run the flow: login → citizen → verify → application → workflow → document → sign → public verify
```

## Security

- Passwords: Argon2id, minimum 12 chars with mixed classes; lockout after 5
  failed attempts (15 minutes).
- JWT: HMAC-SHA256, `jti` per token, no secrets in the client.
- Rate limits: login 10/min, officers 300/min, public endpoints 30/min.
- All audit writes append-only; security events (failed logins, permission
  denials, tampering) are logged continuously.

## Status

Implemented: auth/RBAC with scope enforcement, citizen registry with identity
protection, JSON-driven workflows, document lifecycle (create/sign/issue/verify),
QR-verification codes, appointments & queue, complaints, mock payments with
HMAC webhooks, notifications, audit, reports, admin tools, offline sync and
integrity-monitoring C services. Seed data includes the full federal→kebele
hierarchy, 10 roles, 43 permissions, 2 workflows and 2 services.

Pending official integration: national ID lookup, e-signature, payment gateway.
