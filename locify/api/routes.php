<?php

declare(strict_types=1);

/**
 * LOCIFY API routes v1.
 * Modules mirror the api/ directory layout from the technology stack document.
 * permission: null → authenticated only; a string → RBAC permission required.
 */

function locifyRoutes(): array
{
    $auth = function (string $handler, string $method, ?string $permission = null): array {
        return [
            'handler' => $handler,
            'method' => $method,
            'permission' => $permission,
            'auth' => true,
        ];
    };

    return [
        // ============ AUTH ============
        ['route' => '/api/v1/auth/login', 'http' => 'POST', 'action' => 'login', 'auth' => false, 'handler' => ['AuthController', 'login']],
        ['route' => '/api/v1/auth/refresh', 'http' => 'POST', 'action' => 'refresh', 'auth' => false, 'handler' => ['AuthController', 'refresh']],
        ['route' => '/api/v1/auth/logout', 'http' => 'POST', 'action' => 'logout', 'auth' => true, 'handler' => ['AuthController', 'logout']],
        ['route' => '/api/v1/auth/me', 'http' => 'GET', 'action' => 'me', 'auth' => true, 'handler' => ['AuthController', 'me']],
        ['route' => '/api/v1/auth/change-password', 'http' => 'POST', 'action' => 'change-password', 'auth' => true, 'handler' => ['AuthController', 'changePassword']],
        ['route' => '/api/v1/auth/register-user', 'http' => 'POST', 'action' => 'register-user', 'auth' => true, 'handler' => ['AuthController', 'registerUser'], 'permission' => 'USER:MANAGE'],

        // ============ CITIZENS ============
        ['route' => '/api/v1/citizens', 'http' => 'POST', 'action' => 'create', 'auth' => true, 'handler' => ['CitizenController', 'create'], 'permission' => 'CITIZEN:CREATE'],
        ['route' => '/api/v1/citizens/search', 'http' => 'GET', 'action' => 'search', 'auth' => true, 'handler' => ['CitizenController', 'search'], 'permission' => 'CITIZEN:SEARCH'],
        ['route' => '/api/v1/citizens/{uuid}', 'http' => 'GET', 'action' => 'show', 'auth' => true, 'handler' => ['CitizenController', 'show'], 'permission' => 'CITIZEN:VIEW'],
        ['route' => '/api/v1/citizens/{uuid}', 'http' => 'PUT', 'action' => 'update', 'auth' => true, 'handler' => ['CitizenController', 'update'], 'permission' => 'CITIZEN:EDIT'],
        ['route' => '/api/v1/citizens/{uuid}/verify', 'http' => 'POST', 'action' => 'verify', 'auth' => true, 'handler' => ['CitizenController', 'verify'], 'permission' => 'CITIZEN:VERIFY_INITIATE'],
        ['route' => '/api/v1/citizens/{uuid}/verify-approve', 'http' => 'POST', 'action' => 'verify-approve', 'auth' => true, 'handler' => ['CitizenController', 'approveVerification'], 'permission' => 'CITIZEN:VERIFY_APPROVE'],
        ['route' => '/api/v1/citizens/{uuid}/relationships', 'http' => 'GET', 'action' => 'relationships', 'auth' => true, 'handler' => ['CitizenController', 'relationships'], 'permission' => 'CITIZEN:VIEW_FAMILY'],
        ['route' => '/api/v1/citizens/{uuid}/relationships', 'http' => 'POST', 'action' => 'relationship-create', 'auth' => true, 'handler' => ['CitizenController', 'linkRelationship'], 'permission' => 'CITIZEN:EDIT'],
        ['route' => '/api/v1/citizens/{uuid}/relationships/{relUuid}', 'http' => 'DELETE', 'action' => 'relationship-delete', 'auth' => true, 'handler' => ['CitizenController', 'deleteRelationship'], 'permission' => 'CITIZEN:EDIT'],

        // ============ SERVICES & APPLICATIONS ============
        ['route' => '/api/v1/services', 'http' => 'GET', 'action' => 'catalog', 'auth' => true, 'handler' => ['ServiceController', 'catalog'], 'permission' => 'APPLICATION:CREATE'],
        ['route' => '/api/v1/services/applications', 'http' => 'POST', 'action' => 'create', 'auth' => true, 'handler' => ['ApplicationController', 'create'], 'permission' => 'APPLICATION:CREATE'],
        ['route' => '/api/v1/services/applications', 'http' => 'GET', 'action' => 'index', 'auth' => true, 'handler' => ['ApplicationController', 'index'], 'permission' => 'APPLICATION:VIEW'],
        ['route' => '/api/v1/services/applications/{uuid}', 'http' => 'GET', 'action' => 'show', 'auth' => true, 'handler' => ['ApplicationController', 'show'], 'permission' => 'APPLICATION:VIEW'],
        ['route' => '/api/v1/services/applications/{uuid}/step', 'http' => 'PUT', 'action' => 'advance', 'auth' => true, 'handler' => ['ApplicationController', 'advance'], 'permission' => 'APPLICATION:PROCESS'],
        ['route' => '/api/v1/workflows', 'http' => 'GET', 'action' => 'workflows', 'auth' => true, 'handler' => ['ServiceController', 'workflows'], 'permission' => 'APPLICATION:VIEW'],

        // ============ DOCUMENTS ============
        ['route' => '/api/v1/documents', 'http' => 'POST', 'action' => 'create', 'auth' => true, 'handler' => ['DocumentController', 'create'], 'permission' => 'DOCUMENT:CREATE'],
        ['route' => '/api/v1/documents/my', 'http' => 'GET', 'action' => 'my', 'auth' => true, 'handler' => ['DocumentController', 'myDocuments'], 'permission' => 'DOCUMENT:VIEW_OWN'],
        ['route' => '/api/v1/documents/office', 'http' => 'GET', 'action' => 'office', 'auth' => true, 'handler' => ['DocumentController', 'officeDocuments'], 'permission' => 'DOCUMENT:VIEW'],

        // ============ PUBLIC VERIFICATION (must precede {uuid} routes) ============
        ['route' => '/api/v1/documents/verify', 'http' => 'GET', 'action' => 'verify', 'auth' => false, 'handler' => ['VerificationController', 'verify']],

        ['route' => '/api/v1/documents/{uuid}', 'http' => 'GET', 'action' => 'show', 'auth' => true, 'handler' => ['DocumentController', 'show'], 'permission' => 'DOCUMENT:VIEW'],
        ['route' => '/api/v1/documents/{uuid}/sign', 'http' => 'POST', 'action' => 'sign', 'auth' => true, 'handler' => ['DocumentController', 'sign'], 'permission' => 'DOCUMENT:SIGN'],
        ['route' => '/api/v1/documents/{uuid}/issue', 'http' => 'POST', 'action' => 'issue', 'auth' => true, 'handler' => ['DocumentController', 'issue'], 'permission' => 'DOCUMENT:VIEW'],
        ['route' => '/api/v1/documents/{uuid}/revoke', 'http' => 'POST', 'action' => 'revoke', 'auth' => true, 'handler' => ['DocumentController', 'revoke'], 'permission' => 'DOCUMENT:REVOKE'],
        ['route' => '/api/v1/documents/{uuid}/links', 'http' => 'POST', 'action' => 'link-doc', 'auth' => true, 'handler' => ['DocumentLinkController', 'link'], 'permission' => 'DOCUMENT:LINK'],
        ['route' => '/api/v1/documents/{uuid}/links', 'http' => 'GET', 'action' => 'doc-links', 'auth' => true, 'handler' => ['DocumentLinkController', 'links'], 'permission' => 'DOCUMENT:VIEW'],

        // ============ APPOINTMENTS & QUEUE ============
        ['route' => '/api/v1/appointments/slots', 'http' => 'GET', 'action' => 'slots', 'auth' => true, 'handler' => ['AppointmentController', 'slots'], 'permission' => 'APPOINTMENT:CREATE'],
        ['route' => '/api/v1/appointments', 'http' => 'POST', 'action' => 'book', 'auth' => true, 'handler' => ['AppointmentController', 'book'], 'permission' => 'APPOINTMENT:CREATE'],
        ['route' => '/api/v1/appointments', 'http' => 'GET', 'action' => 'index', 'auth' => true, 'handler' => ['AppointmentController', 'index'], 'permission' => 'APPOINTMENT:MANAGE'],
        ['route' => '/api/v1/appointments/{uuid}', 'http' => 'DELETE', 'action' => 'cancel', 'auth' => true, 'handler' => ['AppointmentController', 'cancel'], 'permission' => 'APPOINTMENT:CREATE'],
        ['route' => '/api/v1/appointments/{uuid}/confirm', 'http' => 'POST', 'action' => 'confirm', 'auth' => true, 'handler' => ['AppointmentController', 'confirm'], 'permission' => 'APPOINTMENT:MANAGE'],
        ['route' => '/api/v1/queue/tickets', 'http' => 'POST', 'action' => 'issue', 'auth' => true, 'handler' => ['QueueController', 'issue'], 'permission' => 'QUEUE:ISSUE'],
        ['route' => '/api/v1/queue/next', 'http' => 'POST', 'action' => 'call-next', 'auth' => true, 'handler' => ['QueueController', 'callNext'], 'permission' => 'QUEUE:CALL'],
        ['route' => '/api/v1/queue/status', 'http' => 'GET', 'action' => 'status', 'auth' => true, 'handler' => ['QueueController', 'status'], 'permission' => 'QUEUE:ISSUE'],
        ['route' => '/api/v1/queue/tickets/{uuid}', 'http' => 'POST', 'action' => 'resolve-ticket', 'auth' => true, 'handler' => ['QueueController', 'resolve'], 'permission' => 'QUEUE:CALL'],
        ['route' => '/api/v1/queue/board', 'http' => 'GET', 'action' => 'waiting-list', 'auth' => true, 'handler' => ['QueueController', 'waiting'], 'permission' => 'QUEUE:ISSUE'],

        // ============ COMPLAINTS ============
        ['route' => '/api/v1/complaints', 'http' => 'POST', 'action' => 'create', 'auth' => true, 'handler' => ['ComplaintController', 'create'], 'permission' => 'COMPLAINT:CREATE'],
        ['route' => '/api/v1/complaints', 'http' => 'GET', 'action' => 'index', 'auth' => true, 'handler' => ['ComplaintController', 'index'], 'permission' => 'COMPLAINT:VIEW'],
        ['route' => '/api/v1/complaints/{uuid}', 'http' => 'PUT', 'action' => 'process', 'auth' => true, 'handler' => ['ComplaintController', 'process'], 'permission' => 'COMPLAINT:PROCESS'],

        // ============ PAYMENTS ============
        ['route' => '/api/v1/payments/initiate', 'http' => 'POST', 'action' => 'initiate', 'auth' => true, 'handler' => ['PaymentController', 'initiate'], 'permission' => 'PAYMENT:INITIATE'],
        ['route' => '/api/v1/payments/confirm', 'http' => 'POST', 'action' => 'confirm', 'auth' => false, 'handler' => ['PaymentController', 'confirm']],
        ['route' => '/api/v1/payments', 'http' => 'GET', 'action' => 'index', 'auth' => true, 'handler' => ['PaymentController', 'index'], 'permission' => 'PAYMENT:VIEW'],

        // ============ NOTIFICATIONS ============
        ['route' => '/api/v1/notifications', 'http' => 'GET', 'action' => 'index', 'auth' => true, 'handler' => ['NotificationController', 'index'], 'permission' => 'NOTIFICATION:VIEW'],
        ['route' => '/api/v1/notifications', 'http' => 'POST', 'action' => 'send', 'auth' => true, 'handler' => ['NotificationController', 'send'], 'permission' => 'NOTIFICATION:SEND'],
        ['route' => '/api/v1/notifications/read-all', 'http' => 'POST', 'action' => 'read-all', 'auth' => true, 'handler' => ['NotificationController', 'markAllRead'], 'permission' => 'NOTIFICATION:VIEW'],
        ['route' => '/api/v1/chat/units', 'http' => 'GET', 'action' => 'chat-units', 'auth' => true, 'handler' => ['ChatController', 'units']],
        ['route' => '/api/v1/chat/conversations', 'http' => 'GET', 'action' => 'conversations', 'auth' => true, 'handler' => ['ChatController', 'index'], 'permission' => 'CHAT:VIEW'],
        ['route' => '/api/v1/chat/conversations', 'http' => 'POST', 'action' => 'conversation-create', 'auth' => true, 'handler' => ['ChatController', 'create'], 'permission' => 'CHAT:SEND'],
        ['route' => '/api/v1/chat/conversations/{uuid}/messages', 'http' => 'GET', 'action' => 'conversation-messages', 'auth' => true, 'handler' => ['ChatController', 'messages'], 'permission' => 'CHAT:VIEW'],
        ['route' => '/api/v1/chat/conversations/{uuid}/messages', 'http' => 'POST', 'action' => 'conversation-send', 'auth' => true, 'handler' => ['ChatController', 'send'], 'permission' => 'CHAT:SEND'],
        ['route' => '/api/v1/chat/conversations/{uuid}/read', 'http' => 'POST', 'action' => 'conversation-read', 'auth' => true, 'handler' => ['ChatController', 'markRead'], 'permission' => 'CHAT:VIEW'],
        ['route' => '/api/v1/chat/conversations/{uuid}', 'http' => 'PUT', 'action' => 'conversation-close', 'auth' => true, 'handler' => ['ChatController', 'close'], 'permission' => 'CHAT:MANAGE'],
        ['route' => '/api/v1/institutions', 'http' => 'GET', 'action' => 'institutions', 'auth' => true, 'handler' => ['InstitutionController', 'index'], 'permission' => 'INSTITUTION:VIEW'],
        ['route' => '/api/v1/admin/institutions', 'http' => 'POST', 'action' => 'institution-create', 'auth' => true, 'handler' => ['InstitutionController', 'create'], 'permission' => 'INSTITUTION:MANAGE'],
        ['route' => '/api/v1/admin/institutions/{uuid}', 'http' => 'PUT', 'action' => 'institution-status', 'auth' => true, 'handler' => ['InstitutionController', 'updateStatus'], 'permission' => 'INSTITUTION:MANAGE'],
        ['route' => '/api/v1/admin/institutions/{uuid}/token', 'http' => 'POST', 'action' => 'institution-token', 'auth' => true, 'handler' => ['InstitutionController', 'issueToken'], 'permission' => 'INSTITUTION:MANAGE'],
        ['route' => '/api/v1/institutions/documents/inspect', 'http' => 'POST', 'action' => 'institution-inspect', 'auth' => false, 'handler' => ['InstitutionController', 'inspect']],
        ['route' => '/api/v1/institutions/requests', 'http' => 'GET', 'action' => 'institution-requests', 'auth' => true, 'handler' => ['InstitutionController', 'requests'], 'permission' => 'INSTITUTION:VIEW'],
        ['route' => '/api/v1/institutions/requests/{uuid}', 'http' => 'PUT', 'action' => 'institution-decide', 'auth' => true, 'handler' => ['InstitutionController', 'decide'], 'permission' => 'DOCUMENT:PULL'],
        ['route' => '/api/v1/institutions/documents/{uuid}/pull', 'http' => 'GET', 'action' => 'institution-pull', 'auth' => false, 'handler' => ['InstitutionController', 'pull']],
        ['route' => '/api/v1/paper', 'http' => 'GET', 'action' => 'paper-lookup', 'auth' => true, 'handler' => ['PaperController', 'lookup'], 'permission' => 'DOCUMENT:VIEW'],
        ['route' => '/api/v1/notifications/{uuid}', 'http' => 'POST', 'action' => 'mark-read', 'auth' => true, 'handler' => ['NotificationController', 'markRead'], 'permission' => 'NOTIFICATION:VIEW'],

        // ============ REPORTS & AUDIT ============
        ['route' => '/api/v1/reports/service-summary', 'http' => 'GET', 'action' => 'service-summary', 'auth' => true, 'handler' => ['ReportController', 'serviceSummary'], 'permission' => 'REPORT:VIEW'],
        ['route' => '/api/v1/reports/dashboard', 'http' => 'GET', 'action' => 'dashboard', 'auth' => true, 'handler' => ['ReportController', 'dashboard'], 'permission' => 'REPORT:VIEW'],
        ['route' => '/api/v1/audit-logs', 'http' => 'GET', 'action' => 'index', 'auth' => true, 'handler' => ['AuditController', 'index'], 'permission' => 'AUDIT:VIEW'],

        // ============ ADMINISTRATION ============
        ['route' => '/api/v1/admin/units', 'http' => 'GET', 'action' => 'units', 'auth' => true, 'handler' => ['AdminController', 'adminUnits'], 'permission' => 'REPORT:VIEW'],
        ['route' => '/api/v1/admin/units', 'http' => 'POST', 'action' => 'create-unit', 'auth' => true, 'handler' => ['AdminController', 'createAdminUnit'], 'permission' => 'SYSTEM:MANAGE'],
        // Public active office catalog (authenticated users only; no permission — used by citizens to book)
        ['route' => '/api/v1/offices', 'http' => 'GET', 'action' => 'offices', 'auth' => true, 'handler' => ['AdminController', 'publicOffices']],
        ['route' => '/api/v1/admin/units/{uuid}', 'http' => 'PUT', 'action' => 'update-unit', 'auth' => true, 'handler' => ['AdminController', 'updateAdminUnit'], 'permission' => 'SYSTEM:MANAGE'],
        ['route' => '/api/v1/admin/offices', 'http' => 'GET', 'action' => 'offices', 'auth' => true, 'handler' => ['AdminController', 'offices'], 'permission' => 'OFFICE:MANAGE'],
        ['route' => '/api/v1/admin/offices', 'http' => 'POST', 'action' => 'create-office', 'auth' => true, 'handler' => ['AdminController', 'createOffice'], 'permission' => 'OFFICE:MANAGE'],
        ['route' => '/api/v1/admin/offices/{uuid}', 'http' => 'PUT', 'action' => 'update-office', 'auth' => true, 'handler' => ['AdminController', 'updateOffice'], 'permission' => 'OFFICE:MANAGE'],
        ['route' => '/api/v1/admin/users', 'http' => 'GET', 'action' => 'users', 'auth' => true, 'handler' => ['AdminController', 'listUsers'], 'permission' => 'USER:MANAGE'],
        ['route' => '/api/v1/admin/users/roles', 'http' => 'POST', 'action' => 'assign-role', 'auth' => true, 'handler' => ['AdminController', 'assignRole'], 'permission' => 'ROLE:ASSIGN'],
        ['route' => '/api/v1/admin/users/roles', 'http' => 'DELETE', 'action' => 'revoke-role', 'auth' => true, 'handler' => ['AdminController', 'revokeRole'], 'permission' => 'ROLE:ASSIGN'],
        ['route' => '/api/v1/admin/users/{uuid}/status', 'http' => 'PUT', 'action' => 'set-status', 'auth' => true, 'handler' => ['AdminController', 'setUserStatus'], 'permission' => 'USER:MANAGE'],
        ['route' => '/api/v1/admin/services', 'http' => 'POST', 'action' => 'configure-service', 'auth' => true, 'handler' => ['AdminController', 'configureService'], 'permission' => 'SERVICE:CONFIGURE'],
        ['route' => '/api/v1/admin/services/{uuid}', 'http' => 'PUT', 'action' => 'update-service', 'auth' => true, 'handler' => ['AdminController', 'updateService'], 'permission' => 'SERVICE:CONFIGURE'],

        // ============ OFFLINE SYNC (C sync agent) ============
        ['route' => '/api/v1/sync/push', 'http' => 'POST', 'action' => 'push', 'auth' => true, 'handler' => ['SyncController', 'push']],
        ['route' => '/api/v1/sync/ack', 'http' => 'POST', 'action' => 'ack', 'auth' => true, 'handler' => ['SyncController', 'ack']],
    ];
}
