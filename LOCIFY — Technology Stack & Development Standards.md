# LOCIFY — Technology Stack & Development Standards

## 1. Overview

**LOCIFY** is built using a focused, lightweight technology stack:

- **C**
- **PHP**
- **HTML**
- **CSS**
- **Pure JavaScript**

The project intentionally avoids large frontend and backend frameworks.

LOCIFY should be developed using these technologies as the core and exclusive application stack.

The architecture is designed to remain:

- Lightweight
- Secure
- Maintainable
- Fast
- Transparent
- Easy to deploy
- Suitable for Ethiopian government environments
- Suitable for low-resource infrastructure
- Suitable for offline and low-connectivity environments

---

# 2. Official LOCIFY Stack

```text
LOCIFY
│
├── Frontend
│   ├── HTML
│   ├── CSS
│   └── Pure JavaScript
│
├── Backend
│   └── PHP
│
├── Systems / Core Services
│   └── C
│
└── Database
    └── MySQL / MariaDB
```

The application code must remain within:

```text
.C
.php
.html
.css
.js
```

No alternative programming language should be introduced into the core LOCIFY implementation without explicit architectural approval.

---

# 3. C

## Purpose

C is the **systems-level programming language** of LOCIFY.

C should be used where direct control over the operating system, hardware, networking, performance, local services, or security infrastructure is required.

## C Responsibilities

C may be used for:

- Local Kebele services
- Offline synchronization
- Device communication
- Hardware integration
- Scanner integration
- Smart-card integration
- Local authentication devices
- Background services
- System monitoring
- Security agents
- Local data processing
- High-performance processing
- File integrity verification
- Secure local utilities
- Network utilities
- Government-office infrastructure services

---

# 4. C Architecture

C components should operate independently from the main PHP application when appropriate.

Example:

```text
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

C should not unnecessarily duplicate PHP business logic.

Instead, C should provide specialized system-level capabilities.

---

# 5. PHP

## Purpose

PHP is the **primary backend application language** of LOCIFY.

PHP handles the core government-service logic.

## PHP Responsibilities

PHP should implement:

- Authentication
- Authorization
- RBAC
- Citizen management
- Kebele management
- Administrative hierarchy
- User management
- Service management
- Applications
- Workflows
- Document management
- Digital document verification
- Notifications
- Appointments
- Queue management
- Complaints
- Payments
- Reports
- Audit logging
- API endpoints
- Database communication
- Business rules
- Session management
- Security controls

---

# 6. PHP API

LOCIFY should expose backend functionality through secure PHP APIs.

Example:

```text
/api/
├── auth/
├── citizens/
├── users/
├── roles/
├── kebeles/
├── services/
├── applications/
├── workflows/
├── documents/
├── verification/
├── payments/
├── notifications/
├── appointments/
├── complaints/
├── reports/
└── audit/
```

The API must enforce authentication and authorization for every protected operation.

---

# 7. HTML

## Purpose

HTML is the structural layer of the LOCIFY interface.

HTML should define:

- Pages
- Forms
- Tables
- Navigation
- Dashboards
- Cards
- Dialogs
- Modals
- Document views
- Citizen service forms
- Officer interfaces
- Administrative interfaces

Example:

```text
HTML
 │
 ├── Citizen Portal
 ├── Officer Portal
 ├── Kebele Dashboard
 ├── Administrator Dashboard
 ├── Document Verification
 └── Public Services
```

HTML should use semantic and accessible structures.

---

# 8. CSS

## Purpose

CSS is responsible for the complete visual design of LOCIFY.

CSS should control:

- Layout
- Typography
- Colors
- Spacing
- Responsive design
- Navigation
- Forms
- Tables
- Dashboards
- Cards
- Buttons
- Modals
- Notifications
- Accessibility states
- Mobile layouts
- Print layouts

LOCIFY should not depend on a frontend CSS framework.

The visual system should be implemented using native CSS.

---

# 9. Pure JavaScript

## Purpose

Pure JavaScript is the **client-side application logic**.

No frontend framework is required.

The system should not depend on:

- React
- Vue
- Angular
- Svelte
- Next.js
- Nuxt
- jQuery

unless explicitly approved as an exception.

## JavaScript Responsibilities

Pure JavaScript handles:

- Dynamic interfaces
- API communication
- Form validation
- Search
- Filtering
- Sorting
- Pagination
- Modal windows
- Notifications
- Dashboard updates
- AJAX/fetch requests
- File uploads
- Client-side state
- Interactive tables
- Camera access
- QR functionality
- Offline functionality
- Service Workers
- IndexedDB
- Browser storage

---

# 10. Frontend Architecture

The frontend should follow:

```text
HTML
  ↓
CSS
  ↓
Pure JavaScript
  ↓
PHP API
```

Example:

```text
Citizen
   ↓
HTML Interface
   ↓
CSS Presentation
   ↓
JavaScript Interaction
   ↓
PHP API
   ↓
Business Logic
   ↓
MySQL/MariaDB
```

---

# 11. Backend Architecture

PHP should use a modular structure.

Recommended:

```text
locify/
│
├── public/
│   ├── index.php
│   ├── assets/
│   │   ├── css/
│   │   └── js/
│
├── app/
│   ├── controllers/
│   ├── services/
│   ├── models/
│   ├── repositories/
│   ├── middleware/
│   ├── validators/
│   ├── security/
│   └── helpers/
│
├── api/
│   ├── auth/
│   ├── citizens/
│   ├── services/
│   ├── documents/
│   └── reports/
│
├── config/
│
├── storage/
│
├── database/
│
└── c/
    ├── services/
    ├── sync/
    ├── security/
    └── devices/
```

The exact directory structure may evolve, but separation of responsibilities must remain.

---

# 12. Database

The application may use:

**MySQL or MariaDB**

The database stores structured LOCIFY information such as:

- Citizens
- Users
- Roles
- Permissions
- Administrative units
- Kebeles
- Services
- Applications
- Workflows
- Documents
- Document versions
- Verification records
- Payments
- Appointments
- Notifications
- Complaints
- Audit records

PHP communicates with the database.

C may interact with local databases where required for offline infrastructure.

---

# 13. Communication Between Technologies

## Browser → PHP

```text
HTML
CSS
JavaScript
   ↓
HTTP/HTTPS
   ↓
PHP API
```

## PHP → Database

```text
PHP
 ↓
Database Driver
 ↓
MySQL/MariaDB
```

## PHP → C

Where required:

```text
PHP
 ↓
Secure IPC / CLI / Local API
 ↓
C Service
```

The communication mechanism must be carefully secured.

Never allow arbitrary user input to execute C programs or operating-system commands.

---

# 14. Offline Kebele Architecture

LOCIFY should support offices with unreliable connectivity.

Possible architecture:

```text
                 INTERNET
                    │
                    ▼
             CENTRAL LOCIFY
                    │
             HTTPS / Secure API
                    │
                    ▼
            KEBELE LOCAL SERVER
                    │
             ┌──────┴──────┐
             │             │
          PHP/WEB          C
             │             │
             └──────┬──────┘
                    │
              Local Database
```

The C component may manage:

- Synchronization
- Local services
- Device communication
- Connection monitoring
- Secure background operations

The PHP application remains responsible for normal government application logic.

---

# 15. Security Philosophy

The LOCIFY stack must follow:

## Least Privilege

Every user and service receives only the permissions required.

## Defense in Depth

Security must exist at multiple layers.

```text
Browser Security
       ↓
PHP Authentication
       ↓
Authorization
       ↓
Input Validation
       ↓
Database Security
       ↓
Operating System Security
       ↓
Network Security
```

## Secure by Default

Unsafe functionality should never be enabled by default.

---

# 16. PHP Security Requirements

PHP code must use:

- Prepared SQL statements
- Strong password hashing
- Secure sessions
- CSRF protection
- Input validation
- Output encoding
- Authorization checks
- Rate limiting
- Secure file uploads
- Secure error handling
- Security logging

Never:

```php
$sql = "SELECT * FROM users WHERE id = " . $_GET['id'];
```

Prefer parameterized queries.

---

# 17. JavaScript Security

JavaScript must never be trusted as the final security layer.

Client-side validation improves usability, but PHP must validate everything again.

Example:

```text
JavaScript validation
        ↓
PHP validation
        ↓
Authorization
        ↓
Database operation
```

Never trust:

- Hidden fields
- JavaScript variables
- Browser storage
- Client-side roles
- Client-side permissions
- Client-submitted prices
- Client-submitted user IDs

---

# 18. C Security

C code must be written defensively.

Pay special attention to:

- Buffer overflows
- Memory corruption
- Integer overflow
- Use-after-free
- Null pointers
- Race conditions
- File permissions
- Command injection
- Unsafe parsing
- Privilege escalation

Avoid unsafe functions where safer alternatives exist.

C services should run with the minimum operating-system privileges required.

---

# 19. File Upload Security

Uploaded government documents must be treated as untrusted input.

PHP should validate:

- File size
- File type
- File extension
- MIME type
- File content
- Filename
- Storage location

Uploaded files should not automatically become executable.

---

# 20. API Security

Every protected API must verify:

```text
Authentication
      ↓
Session/Token validity
      ↓
User status
      ↓
Role
      ↓
Permission
      ↓
Administrative scope
      ↓
Requested resource
```

An administrator from one Kebele must not automatically access another Kebele's data.

---

# 21. Coding Principle

LOCIFY should prioritize:

**Simple code over unnecessary complexity.**

Avoid adding a framework simply because it is popular.

Use the native capabilities of:

- C
- PHP
- HTML
- CSS
- JavaScript

where practical.

---

# 22. No Framework Dependency

The core LOCIFY system should remain independent of major application frameworks.

This provides:

- Lower infrastructure requirements
- Easier deployment
- Greater source-code control
- Easier local maintenance
- Less dependency overhead
- Better understanding of the underlying system
- Greater portability

External libraries may only be introduced when they solve a genuine technical or security requirement and are compatible with the project's architecture.

---

# 23. Development Environment

A development environment may contain:

```text
Linux
Apache
PHP
MySQL/MariaDB
GCC
HTML
CSS
JavaScript
```

The exact operating system may vary between development and production environments.

---

# 24. Deployment Model

A standard LOCIFY deployment may look like:

```text
Linux Server
│
├── Apache
│
├── PHP
│
├── MySQL/MariaDB
│
├── LOCIFY
│   ├── HTML
│   ├── CSS
│   ├── JavaScript
│   └── PHP
│
└── C Services
    ├── Synchronization
    ├── Security
    └── Hardware Integration
```

---

# 25. Development Rule

All developers working on LOCIFY must understand the responsibility of each technology.

### C

**System-level and hardware-level capabilities**

### PHP

**Backend and government business logic**

### HTML

**Interface structure**

### CSS

**Interface design**

### JavaScript

**Interface behavior and client-side interaction**

### MySQL/MariaDB

**Persistent structured data**

---

# 26. Final Technology Principle

LOCIFY is intentionally built on a small and controlled technology foundation:

```text
                LOCIFY
                   │
       ┌───────────┼───────────┐
       │           │           │
       ▼           ▼           ▼
      C          PHP       HTML/CSS/JS
       │           │           │
       │           ▼           ▼
       │        Database     Browser
       │
       ▼
System / Hardware /
Offline / Security
```

The goal is not to use the maximum number of technologies.

The goal is to build a **secure, reliable, maintainable and nationally scalable Digital Kebele platform using a technology stack that the LOCIFY development team fully understands and controls**.

## Official LOCIFY Stack

> **C + PHP + HTML + CSS + Pure JavaScript + MySQL/MariaDB**

No unnecessary framework should be introduced.

LOCIFY should remain lightweight, transparent, secure, and practical for **Digital Ethiopia**.