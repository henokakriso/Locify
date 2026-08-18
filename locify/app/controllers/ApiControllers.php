<?php

declare(strict_types=1);

/** Document, verification, appointment, queue, complaint endpoints. */

final class DocumentLinkController extends Controller
{
    /** Link two documents (both directions become visible through canonical ordering). */
    public function link(Request $request): void
    {
        $this->requirePermission($request, 'DOCUMENT:LINK');
        Validator::requireFields($request, ['target_uuid']);
        $source = Database::fetchOne(
            'SELECT d.id, d.uuid, a.admin_unit_id FROM document d
             JOIN application a ON a.id = d.application_id WHERE d.uuid = ?',
            [$request->routeParams['uuid']]
        );
        if ($source === null) {
            Response::notFound('Document not found');
        }
        Auth::assertInScope($request, $source['admin_unit_id']);
        $target = Database::fetchOne(
            'SELECT d.id, d.uuid, a.admin_unit_id FROM document d
             JOIN application a ON a.id = d.application_id WHERE d.uuid = ?',
            [$request->input('target_uuid')]
        );
        if ($target === null) {
            Response::validationError(['target_uuid' => 'Unknown target document']);
        }
        if ($target['id'] === $source['id']) {
            Response::validationError(['target_uuid' => 'Cannot link a document to itself']);
        }
        $relation = (string)($request->input('relation') ?? 'related');
        if (strlen($relation) > 64) {
            Response::validationError(['relation' => 'Too long']);
        }
        $a = $source['id'] < $target['id'] ? $source : $target;
        $b = $source['id'] < $target['id'] ? $target : $source;
        Database::run(
            'INSERT INTO document_link (id, source_document_id, target_document_id, relation, created_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE relation = VALUES(relation)',
            [uuid4(), $a['id'], $b['id'], $relation, Auth::$context['user_id'] ?? null]
        );
        Audit::log($request, 'LINK_DOCUMENT', 'document', $source['id'], null, ['target' => $target['id'], 'relation' => $relation]);
        Response::success(['status' => 'linked', 'relation' => $relation]);
    }

    /** List linked documents (from either side). */
    public function links(Request $request): void
    {
        $this->requirePermission($request, 'DOCUMENT:VIEW');
        $doc = Database::fetchOne(
            'SELECT d.id, d.uuid, a.admin_unit_id FROM document d
             JOIN application a ON a.id = d.application_id WHERE d.uuid = ?',
            [$request->routeParams['uuid']]
        );
        if ($doc === null) {
            Response::notFound('Document not found');
        }
        Auth::assertInScope($request, $doc['admin_unit_id']);
        $rows = Database::fetchAll(
            'SELECT d.document_number, d.document_type, d.title, d.status, dl.relation, dl.created_at
             FROM document_link dl
             JOIN document d ON d.id = IF(dl.source_document_id = ?, dl.target_document_id, dl.source_document_id)
             WHERE dl.source_document_id = ? OR dl.target_document_id = ?
             ORDER BY dl.created_at DESC',
            [$doc['id'], $doc['id'], $doc['id']]
        );
        Audit::log($request, 'VIEW_DOCUMENT_LINKS', 'document', $doc['id']);
        Response::success(['links' => $rows]);
    }
}

final class DocumentController extends Controller
{
    public function create(Request $request): void
    {
        $this->requirePermission($request, 'DOCUMENT:CREATE');
        Validator::requireFields($request, ['application_uuid']);
        Response::json(DocumentService::create($request, (string)$request->input('application_uuid'), $request->body), 201);
    }

    public function sign(Request $request): void
    {
        $this->requirePermission($request, 'DOCUMENT:SIGN');
        Response::json(DocumentService::sign($request, $request->routeParams['uuid']));
    }

    public function issue(Request $request): void
    {
        $this->requirePermission($request, 'DOCUMENT:VIEW');
        Response::json(DocumentService::issue($request, $request->routeParams['uuid']));
    }

    public function revoke(Request $request): void
    {
        $this->requirePermission($request, 'DOCUMENT:REVOKE');
        Response::json(DocumentService::revoke($request, $request->routeParams['uuid'], $request->input('reason')));
    }

    public function show(Request $request): void
    {
        $this->requirePermission($request, 'DOCUMENT:VIEW');
        $doc = Database::fetchOne(
            'SELECT d.uuid, d.document_number, d.document_type, d.title, d.status, d.version,
                    d.verification_code, d.issued_at_eth, d.issued_at_greg, d.created_at, c.uuid AS citizen_uuid
             FROM document d JOIN citizen c ON c.id = d.citizen_id WHERE d.uuid = ?',
            [$request->routeParams['uuid']]
        );
        if ($doc === null) {
            Response::notFound('Document not found');
        }
        $citizenUnit = Database::fetchOne(
            'SELECT a.admin_unit_id FROM citizen_address a WHERE a.citizen_id = (SELECT id FROM citizen WHERE uuid = ?) LIMIT 1',
            [$doc['citizen_uuid']]
        );
        if (Auth::isCitizen($request) && $doc['citizen_uuid'] !== (Auth::$context['citizen_id'] ?? '')) {
            Response::forbidden('Not your document');
        }
        if (!Auth::isCitizen($request) && $citizenUnit) {
            Auth::assertInScope($request, $citizenUnit['admin_unit_id']);
        }
        $signature = Database::fetchOne(
            'SELECT signer_name_enc, timestamp, hash_algorithm, document_hash FROM digital_signature WHERE document_id = (SELECT id FROM document WHERE uuid = ?)',
            [$request->routeParams['uuid']]
        );
        Audit::log($request, 'VIEW_DOCUMENT', 'document', null);
        Response::success([
            ...$doc,
            'signature' => $signature !== null ? [
                'signer' => Crypto::decrypt($signature['signer_name_enc']),
                'signed_at' => $signature['timestamp'],
                'hash_algorithm' => $signature['hash_algorithm'],
                'document_hash' => $signature['document_hash'],
            ] : null,
        ]);
    }

    public function myDocuments(Request $request): void
    {
        $this->requirePermission($request, 'DOCUMENT:VIEW_OWN');
        if (!Auth::isCitizen($request)) {
            Response::forbidden('Citizen role required');
        }
        $rows = Database::fetchAll(
            'SELECT uuid, document_number, document_type, title, status, created_at
             FROM document WHERE citizen_id = ? ORDER BY created_at DESC LIMIT 100',
            [Auth::$context['citizen_id']]
        );
        Response::success(['documents' => $rows]);
    }

    /** Office-scoped document list for officers. */
    public function officeDocuments(Request $request): void
    {
        $this->requirePermission($request, 'DOCUMENT:VIEW');
        $scope = Auth::$context['scope_subtree'];
        $rows = Database::fetchAll(
            'SELECT d.uuid, d.document_number, d.document_type, d.title, d.status, d.verification_code,
                    d.created_at, d.citizen_id, a.application_number
             FROM document d
             JOIN application a ON a.id = d.application_id
             WHERE a.admin_unit_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')
             ORDER BY d.created_at DESC LIMIT 200',
            $scope
        );
        foreach ($rows as &$row) {
            $row['citizen_name'] = citizenFullName(Database::pdo(), (string)$row['citizen_id']);
        }
        unset($row);
        Response::success(['documents' => $rows]);
    }
}

final class VerificationController extends Controller
{
    /** Public verification endpoint — rate limited, no personal data. */
    public function verify(Request $request): void
    {
        $limiter = new RateLimiter(Database::pdo());
        if (!$limiter->allow('verify:' . $request->ip, (int)Config::get('rate_limit.public', 30), $request->ip)) {
            Response::error('RATE_LIMITED', 'Too many verification attempts', 429);
        }
        $code = $request->query['code'] ?? '';
        if ($code === '') {
            Response::validationError(['code' => 'Missing verification code']);
        }
        Response::json(DocumentService::verify($request, (string)$code));
    }
}

final class AppointmentController extends Controller
{
    public function slots(Request $request): void
    {
        $this->requirePermission($request, 'APPOINTMENT:CREATE');
        $officeId = $request->query['office_id'] ?? null;
        $date = $request->query['date'] ?? date('Y-m-d');
        if ($officeId === null || !isValidUuid((string)$officeId)) {
            Response::validationError(['office_id' => 'Invalid office']);
        }
        $office = Database::fetchOne('SELECT id, capacity, working_hours FROM office WHERE id = ? AND is_active = 1', [$officeId]);
        if ($office === null) {
            Response::notFound('Office not found');
        }
        $hours = json_decode((string)$office['working_hours'], true) ?? ['start' => '08:30', 'end' => '17:30'];
        $booked = Database::fetchAll(
            'SELECT slot_start, slot_end FROM appointment WHERE office_id = ? AND slot_start >= ? AND slot_start < ? AND status IN (?, ?)',
            [$officeId, $date . ' 00:00:00', date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00', 'booked', 'confirmed']
        );
        $slots = [];
        $start = strtotime($hours['start']);
        $end = strtotime($hours['end']);
        for ($t = $start; $t < $end; $t += 3600) {
            $slotStart = $date . ' ' . date('H:i:s', $t);
            $taken = false;
            foreach ($booked as $b) {
                if (substr($b['slot_start'], 0, 16) === substr($slotStart, 0, 16)) {
                    $taken = true;
                    break;
                }
            }
            if (!$taken) {
                $slots[] = ['start' => $slotStart, 'end' => $date . ' ' . date('H:i:s', $t + 3600)];
            }
        }
        Response::success(['office_id' => $officeId, 'date' => $date, 'slots' => $slots]);
    }

    public function book(Request $request): void
    {
        $this->requirePermission($request, 'APPOINTMENT:CREATE');
        Validator::requireFields($request, ['office_id', 'service_id', 'slot_start', 'slot_end']);
        $citizenId = Auth::$context['citizen_id'];
        if ($citizenId === null) {
            // Officer-assisted booking: the officer books on behalf of a citizen.
            $citizenUuid = (string)$request->input('citizen_uuid');
            if (!isValidUuid($citizenUuid)) {
                Response::validationError(['citizen_uuid' => 'Required when booking on behalf of a citizen']);
            }
            $citizen = Database::fetchOne(
                'SELECT id, uuid, status FROM citizen WHERE uuid = ?',
                [$citizenUuid]
            );
            if ($citizen === null || $citizen['status'] !== 'active') {
                Response::validationError(['citizen_uuid' => 'Unknown or unverified citizen']);
            }
            $unit = Database::fetchOne(
                'SELECT admin_unit_id FROM citizen_address WHERE citizen_id = ? AND is_primary = 1',
                [$citizen['id']]
            );
            Auth::assertInScope($request, $unit['admin_unit_id'] ?? Auth::$context['scope_unit']);
            $citizenId = $citizen['id'];
        }
        $conflict = Database::fetchOne(
            'SELECT id FROM appointment WHERE office_id = ? AND slot_start = ? AND status IN (?, ?)',
            [$request->input('office_id'), $request->input('slot_start'), 'booked', 'confirmed']
        );
        if ($conflict !== null) {
            Response::error('SLOT_TAKEN', 'Slot is no longer available', 409);
        }
        // Capacity (spec §19-§20): office.max_daily_appointments limits bookings per day.
        $office = Database::fetchOne('SELECT * FROM office WHERE id = ? AND is_active = 1', [$request->input('office_id')]);
        if ($office === null) {
            Response::notFound('Office not found');
        }
        $maxDaily = $office['max_daily_appointments'] !== null ? (int)$office['max_daily_appointments'] : null;
        if ($maxDaily !== null && $maxDaily > 0) {
            $day = substr((string)$request->input('slot_start'), 0, 10);
            $counted = (int)(Database::fetchOne(
                'SELECT COUNT(*) AS n FROM appointment
                 WHERE office_id = ? AND slot_start >= ? AND slot_start < ?
                   AND status IN (?, ?, ?, ?)',
                [$office['id'], $day . ' 00:00:00',
                 date('Y-m-d', strtotime($day . ' +1 day')) . ' 00:00:00',
                 'booked', 'confirmed', 'checked_in', 'in_service']
            )['n'] ?? 0);
            if ($counted >= $maxDaily) {
                Response::error('CAPACITY_REACHED',
                    'This office has reached its daily appointment limit. Choose another day.', 409);
            }
        }
        $id = uuid4();
        $number = nextAppointmentNumber(Database::pdo());
        Database::run(
            'INSERT INTO appointment (id, appointment_number, citizen_id, office_id, service_catalog_id, slot_start, slot_end, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $number, $citizenId, $request->input('office_id'), $request->input('service_id'),
             $request->input('slot_start'), $request->input('slot_end'), 'booked']
        );
        Audit::log($request, 'BOOK_APPOINTMENT', 'appointment', $id);
        Response::success(['id' => $id, 'appointment_number' => $number, 'status' => 'booked'], 201);
    }

    public function confirm(Request $request): void
    {
        $this->requirePermission($request, 'APPOINTMENT:MANAGE');
        $uuid = $request->routeParams['uuid'];
        $appt = Database::fetchOne('SELECT id, status FROM appointment WHERE id = ?', [$uuid]);
        if ($appt === null) {
            Response::notFound('Appointment not found');
        }
        if ($appt['status'] !== 'booked') {
            Response::error('INVALID_STATE', 'Only booked appointments can be confirmed', 409);
        }
        Database::run("UPDATE appointment SET status = 'confirmed' WHERE id = ?", [$uuid]);
        Audit::log($request, 'CONFIRM_APPOINTMENT', 'appointment', $uuid);
        Response::success(['id' => $uuid, 'status' => 'confirmed']);
    }

    public function index(Request $request): void
    {
        $this->requirePermission($request, 'APPOINTMENT:MANAGE');
if (Auth::isCitizen($request)) {
            $rows = Database::fetchAll(
                'SELECT id, appointment_number, slot_start, slot_end, status, created_at
                 FROM appointment WHERE citizen_id = ? ORDER BY slot_start DESC LIMIT 50',
                [Auth::$context['citizen_id']]
            );
        } else {
            $rows = Database::fetchAll(
                'SELECT a.id, a.appointment_number, a.slot_start, a.slot_end, a.status, a.created_at,
                        a.citizen_id, o.name AS office_name
                 FROM appointment a JOIN office o ON o.id = a.office_id
                 WHERE o.admin_unit_id IN (' . implode(',', array_fill(0, count(Auth::$context['scope_subtree']), '?')) . ')
                 ORDER BY a.slot_start DESC LIMIT 200',
                Auth::$context['scope_subtree']
            );
            foreach ($rows as &$row) {
                $row['citizen_name'] = citizenFullName(Database::pdo(), (string)$row['citizen_id']);
            }
            unset($row);
        }
        Response::success(['appointments' => $rows]);
    }

    public function cancel(Request $request): void
    {
        $this->requirePermission($request, 'APPOINTMENT:CREATE');
        $appt = Database::fetchOne('SELECT id, citizen_id, slot_start FROM appointment WHERE id = ?', [$request->routeParams['uuid']]);
        if ($appt === null) {
            Response::notFound('Appointment not found');
        }
        if ($appt['citizen_id'] !== Auth::$context['citizen_id']) {
            Response::forbidden('Not your appointment');
        }
        if (strtotime((string)$appt['slot_start']) - time() < 3600) {
            Response::error('TOO_LATE', 'Appointments can only be cancelled at least 1 hour before the slot', 409);
        }
        Database::run("UPDATE appointment SET status = 'cancelled' WHERE id = ?", [$appt['id']]);
        Audit::log($request, 'CANCEL_APPOINTMENT', 'appointment', $appt['id']);
        Response::success(['status' => 'cancelled']);
    }

    /** Office check-in of the citizen at the desk (spec §19). */
    public function checkIn(Request $request): void
    {
        $this->requirePermission($request, 'APPOINTMENT:MANAGE');
        $appt = Database::fetchOne('SELECT id, status FROM appointment WHERE id = ?', [$request->routeParams['uuid']]);
        if ($appt === null) {
            Response::notFound('Appointment not found');
        }
        if (!in_array($appt['status'], ['booked', 'confirmed'], true)) {
            Response::error('INVALID_STATE', 'Only booked or confirmed appointments can be checked in', 409);
        }
        Database::run(
            "UPDATE appointment SET status = 'checked_in', checked_in_at = NOW() WHERE id = ?",
            [$appt['id']]
        );
        Audit::log($request, 'CHECK_IN_APPOINTMENT', 'appointment', $appt['id']);
        Response::success(['id' => $appt['id'], 'status' => 'checked_in']);
    }

    /** Complete the in-person service; records missed appointments too. */
    public function finish(Request $request): void
    {
        $this->requirePermission($request, 'APPOINTMENT:MANAGE');
        $appt = Database::fetchOne('SELECT id, status FROM appointment WHERE id = ?', [$request->routeParams['uuid']]);
        if ($appt === null) {
            Response::notFound('Appointment not found');
        }
        $action = (string)($request->input('action') ?? 'complete');
        if ($action === 'missed') {
            if (!in_array($appt['status'], ['booked', 'confirmed', 'checked_in'], true)) {
                Response::error('INVALID_STATE', 'Appointment is already resolved', 409);
            }
            Database::run("UPDATE appointment SET status = 'missed' WHERE id = ?", [$appt['id']]);
            Audit::log($request, 'MARK_MISSED_APPOINTMENT', 'appointment', $appt['id']);
            Response::success(['id' => $appt['id'], 'status' => 'missed']);
        }
        if (!in_array($appt['status'], ['checked_in', 'in_service'], true)) {
            Response::error('INVALID_STATE', 'Only attended appointments can be completed', 409);
        }
        Database::run(
            "UPDATE appointment SET status = 'completed', completed_at = NOW() WHERE id = ?",
            [$appt['id']]
        );
        Audit::log($request, 'COMPLETE_APPOINTMENT', 'appointment', $appt['id']);
        Response::success(['id' => $appt['id'], 'status' => 'completed']);
    }
}

final class QueueController extends Controller
{
    public function issue(Request $request): void
    {
        $this->requirePermission($request, 'QUEUE:ISSUE');
        Validator::requireFields($request, ['office_id']);
        $office = Database::fetchOne('SELECT id FROM office WHERE id = ? AND is_active = 1', [$request->input('office_id')]);
        if ($office === null) {
            Response::notFound('Office not found');
        }
        $today = date('Y-m-d');
        $next = Database::fetchOne(
            'SELECT COALESCE(MAX(ticket_number), 0) + 1 AS n FROM queue_ticket WHERE office_id = ? AND created_at >= ?',
            [$office['id'], $today . ' 00:00:00']
        );
        $id = uuid4();
        Database::run(
            'INSERT INTO queue_ticket (id, office_id, service_catalog_id, ticket_number, citizen_id, status, priority)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $office['id'], $request->input('service_id') ?? null, (int)$next['n'],
             Auth::$context['citizen_id'] ?? null, 'waiting', $request->input('priority') ?? 'normal']
        );
        Audit::log($request, 'ISSUE_QUEUE_TICKET', 'queue', $id);
        Response::success(['ticket_number' => (int)$next['n'], 'id' => $id, 'status' => 'waiting'], 201);
    }

    public function callNext(Request $request): void
    {
        $this->requirePermission($request, 'QUEUE:CALL');
        Validator::requireFields($request, ['office_id']);
        $ticket = Database::fetchOne(
            "SELECT * FROM queue_ticket WHERE office_id = ? AND status = 'waiting'
             ORDER BY FIELD(priority, 'elderly', 'disabled', 'normal'), created_at LIMIT 1",
            [$request->input('office_id')]
        );
        if ($ticket === null) {
            Response::error('QUEUE_EMPTY', 'No waiting tickets', 404);
        }
        Database::run(
            "UPDATE queue_ticket SET status = 'called', called_at = NOW() WHERE id = ?",
            [$ticket['id']]
        );
        Audit::log($request, 'CALL_QUEUE_TICKET', 'queue', $ticket['id']);
        Response::success(['ticket_number' => (int)$ticket['ticket_number'], 'status' => 'called']);
    }

    public function status(Request $request): void
    {
        $this->requirePermission($request, 'QUEUE:ISSUE');
        $officeId = $request->query['office_id'] ?? null;
        if ($officeId === null) {
            Response::validationError(['office_id' => 'Missing office']);
        }
        $waiting = (int)Database::fetchOne(
            "SELECT COUNT(*) AS n FROM queue_ticket WHERE office_id = ? AND status = 'waiting'",
            [$officeId]
        )['n'];
        $current = Database::fetchOne(
            "SELECT ticket_number FROM queue_ticket WHERE office_id = ? AND status = 'called' ORDER BY called_at DESC LIMIT 1",
            [$officeId]
        );
        $next = Database::fetchOne(
            "SELECT ticket_number FROM queue_ticket WHERE office_id = ? AND status = 'waiting'
             ORDER BY FIELD(priority, 'elderly', 'disabled', 'normal'), created_at LIMIT 1",
            [$officeId]
        );
        Response::success([
            'office_id' => $officeId,
            'waiting' => $waiting,
            'now_serving' => $current['ticket_number'] ?? null,
            'next_ticket' => $next['ticket_number'] ?? null,
        ]);
    }

    /** Complete a called ticket (served) or mark as no-show. */
    public function resolve(Request $request): void
    {
        $this->requirePermission($request, 'QUEUE:CALL');
        $uuid = $request->routeParams['uuid'];
        $ticket = Database::fetchOne('SELECT * FROM queue_ticket WHERE id = ?', [$uuid]);
        if ($ticket === null) {
            Response::notFound('Ticket not found');
        }
        $action = (string)$request->input('action');
        if (!in_array($action, ['complete', 'no_show'], true)) {
            Response::validationError(['action' => 'Invalid action']);
        }
        if ($ticket['status'] !== 'called') {
            Response::error('INVALID_STATE', 'Only called tickets can be resolved', 409);
        }
        Database::run(
            "UPDATE queue_ticket SET status = ?, completed_at = NOW() WHERE id = ?",
            [$action === 'complete' ? 'completed' : 'no_show', $uuid]
        );
        Audit::log($request, 'RESOLVE_QUEUE_TICKET', 'queue', $uuid, null, ['action' => $action]);
        Response::success(['id' => $uuid, 'status' => $action === 'complete' ? 'completed' : 'no_show']);
    }

    /** Waiting list preview for the display board. */
    public function waiting(Request $request): void
    {
        $this->requirePermission($request, 'QUEUE:ISSUE');
        $officeId = $request->query['office_id'] ?? null;
        if ($officeId === null) {
            Response::validationError(['office_id' => 'Missing office']);
        }
        $rows = Database::fetchAll(
            "SELECT id, ticket_number, priority, status, created_at FROM queue_ticket
             WHERE office_id = ? AND status IN ('waiting', 'called')
             ORDER BY FIELD(priority, 'elderly', 'disabled', 'normal'), created_at LIMIT 30",
            [$officeId]
        );
        Response::success(['tickets' => $rows]);
    }
}

final class ComplaintController extends Controller
{
    public function create(Request $request): void
    {
        $this->requirePermission($request, 'COMPLAINT:CREATE');
        Validator::requireFields($request, ['category', 'description']);
        $limiter = new RateLimiter(Database::pdo());
        if (!$limiter->allow('complaint:' . Auth::$context['user_id'], 5, $request->ip)) {
            Response::error('RATE_LIMITED', 'Complaint limit reached for today', 429);
        }
        $priority = $request->input('priority') ?? 'medium';
        $slaHours = match ($priority) {
            'critical' => 24, 'high' => 72, 'medium' => 168, default => 336,
        };
        $id = uuid4();
        Database::run(
            'INSERT INTO complaint (id, citizen_id, category, description, priority, status, anonymous, sla_deadline)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                Auth::$context['citizen_id'] ?? null,
                $request->input('category'),
                $request->input('description'),
                $priority,
                'submitted',
                $request->input('anonymous') ? 1 : 0,
                date('Y-m-d H:i:s', time() + $slaHours * 3600),
            ]
        );
        Audit::log($request, 'CREATE_COMPLAINT', 'complaint', $id);
        Response::success(['id' => $id, 'status' => 'submitted', 'sla_deadline' => date('c', time() + $slaHours * 3600)], 201);
    }

    public function index(Request $request): void
    {
        $this->requirePermission($request, 'COMPLAINT:VIEW');
        if (Auth::isCitizen($request)) {
            $rows = Database::fetchAll(
                'SELECT id, category, priority, status, created_at, sla_deadline
                 FROM complaint WHERE citizen_id = ? ORDER BY created_at DESC LIMIT 50',
                [Auth::$context['citizen_id']]
            );
        } else {
            $rows = Database::fetchAll(
                'SELECT id, category, priority, status, created_at, sla_deadline, assigned_officer_id
                 FROM complaint WHERE assigned_officer_id = ? OR assigned_officer_id IS NULL
                 ORDER BY FIELD(priority, \'critical\', \'high\', \'medium\', \'low\'), created_at LIMIT 100',
                [Auth::$context['user_id']]
            );
        }
        Response::success(['complaints' => $rows]);
    }

    public function process(Request $request): void
    {
        $this->requirePermission($request, 'COMPLAINT:PROCESS');
        $complaint = Database::fetchOne('SELECT * FROM complaint WHERE id = ?', [$request->routeParams['uuid']]);
        if ($complaint === null) {
            Response::notFound('Complaint not found');
        }
        $action = (string)$request->input('action');
        $status = match ($action) {
            'acknowledge' => 'acknowledged',
            'start' => 'in_progress',
            'resolve' => 'resolved',
            'reject' => 'rejected',
            default => null,
        };
        if ($status === null) {
            Response::validationError(['action' => 'Invalid action']);
        }
        Database::run(
            "UPDATE complaint SET status = ?, assigned_officer_id = ?, resolution = ?, resolved_at = IF(? = 'resolved', NOW(), resolved_at)
             WHERE id = ?",
            [$status, Auth::$context['user_id'], $request->input('resolution'), $status, $complaint['id']]
        );
        Audit::log($request, 'PROCESS_COMPLAINT', 'complaint', $complaint['id'], null,
            ['status' => $status], 'success', $request->input('resolution'));
        Response::success(['status' => $status]);
    }
}

final class PaymentController extends Controller
{
    public function initiate(Request $request): void
    {
        $this->requirePermission($request, 'PAYMENT:INITIATE');
        Validator::requireFields($request, ['amount']);
        if (!is_numeric($request->input('amount')) || (float)$request->input('amount') <= 0) {
            Response::validationError(['amount' => 'Invalid amount']);
        }
        Response::json(PaymentService::initiate(
            $request,
            (float)$request->input('amount'),
            (string)($request->input('currency') ?? 'ETB'),
            $request->input('application_uuid')
        ), 201);
    }

    /** Provider webhook — HMAC verified, idempotent. */
    public function confirm(Request $request): void
    {
        Response::json(PaymentService::confirmWebhook($request, $request->body));
    }

    public function index(Request $request): void
    {
        $this->requirePermission($request, 'PAYMENT:VIEW');
        $rows = Database::fetchAll(
            'SELECT id, amount, currency, status, provider_name, initiated_at, confirmed_at
             FROM payment ORDER BY created_at DESC LIMIT 100'
        );
        Response::success(['payments' => $rows]);
    }
}

final class NotificationController extends Controller
{
    public function send(Request $request): void
    {
        $this->requirePermission($request, 'NOTIFICATION:SEND');
        Validator::requireFields($request, ['body']);
        Response::json(NotificationService::send($request, $request->body), 201);
    }

    public function index(Request $request): void
    {
        $this->requirePermission($request, 'NOTIFICATION:VIEW');
        Response::success(['notifications' => NotificationService::listForUser($request)]);
    }

    public function markRead(Request $request): void
    {
        $this->requirePermission($request, 'NOTIFICATION:VIEW');
        $uuid = $request->routeParams['uuid'];
        $updated = Database::run(
            "UPDATE notification SET status = 'read' WHERE id = ? AND user_id = ?",
            [$uuid, Auth::$context['user_id']]
        )->rowCount();
        if ($updated === 0) {
            Response::notFound('Notification not found');
        }
        Response::success(['id' => $uuid, 'status' => 'read']);
    }

    public function markAllRead(Request $request): void
    {
        $this->requirePermission($request, 'NOTIFICATION:VIEW');
        $updated = Database::run(
            "UPDATE notification SET status = 'read' WHERE user_id = ? AND status != 'read'",
            [Auth::$context['user_id']]
        )->rowCount();
        Response::success(['read' => $updated]);
    }
}

final class ReportController extends Controller
{
    public function serviceSummary(Request $request): void
    {
        $this->requirePermission($request, 'REPORT:VIEW');
        $scope = Auth::$context['scope_subtree'];
        $in = implode(',', array_fill(0, count($scope), '?'));
        $byService = Database::fetchAll(
            'SELECT s.name AS service, COUNT(a.id) AS applications,
                    SUM(a.status = \'completed\') AS completed,
                    ROUND(AVG(TIMESTAMPDIFF(HOUR, a.submitted_at, a.completed_at)), 1) AS avg_hours
             FROM application a JOIN service_catalog s ON s.id = a.service_catalog_id
             WHERE a.admin_unit_id IN (' . $in . ') AND a.submitted_at >= NOW() - INTERVAL 30 DAY
             GROUP BY s.id, s.name ORDER BY applications DESC LIMIT 20',
            $scope
        );
        $openComplaints = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS n FROM complaint c
             LEFT JOIN citizen_address ca ON ca.citizen_id = c.citizen_id AND ca.is_primary = 1
             WHERE c.status IN ('submitted','acknowledged','in_progress')
               AND (ca.admin_unit_id IS NULL OR ca.admin_unit_id IN ($in))",
            $scope
        )['n'] ?? 0);
        $resolvedComplaints = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS n FROM complaint c
             LEFT JOIN citizen_address ca ON ca.citizen_id = c.citizen_id AND ca.is_primary = 1
             WHERE c.status IN ('resolved','rejected')
               AND (ca.admin_unit_id IS NULL OR ca.admin_unit_id IN ($in))",
            $scope
        )['n'] ?? 0);
        Response::success([
            'by_service' => $byService,
            'complaints' => ['total' => $openComplaints + $resolvedComplaints, 'resolved' => $resolvedComplaints],
        ]);
    }

    /** Aggregate KPI tile data for dashboards, filterable by kebele (unit=uuid). */
    public function dashboard(Request $request): void
    {
        $this->requirePermission($request, 'REPORT:VIEW');
        $scope = Auth::$context['scope_subtree'];

        $requestedUnit = $request->query['unit'] ?? null;
        if ($requestedUnit !== null) {
            Auth::assertInScope($request, $requestedUnit);
            $scope = [$requestedUnit];
        }

        $in = implode(',', array_fill(0, count($scope), '?'));
        $count = function (string $sql, array $params = null) use ($scope): int {
            if ($params === null) {
                $params = $scope;
            }
            $row = Database::fetchOne($sql, $params);
            return (int)($row['n'] ?? 0);
        };
        $revenue = (float)(Database::fetchOne(
            "SELECT COALESCE(SUM(p.amount),0) AS n
             FROM payment p LEFT JOIN application a ON a.id = p.application_id
             WHERE p.status = 'confirmed' AND (a.admin_unit_id IN ($in) OR a.admin_unit_id IS NULL)",
            $scope
        )['n'] ?? 0);

        $byUnit = [];
        if ($requestedUnit === null) {
            $units = Database::fetchAll(
                "SELECT u.id, u.name, u.local_name FROM admin_unit u
                 WHERE u.type = 'kebele' AND u.status = 'active' AND u.id IN ($in)
                 ORDER BY u.name",
                $scope
            );
            foreach ($units as $u) {
                $one = [$u['id']];
                $oneIn = '?';
                $byUnit[] = [
                    'id' => $u['id'],
                    'name' => $u['local_name'] ?? $u['name'],
                    'applications' => $count("SELECT COUNT(*) AS n FROM application WHERE admin_unit_id = $oneIn", $one),
                    'in_review' => $count("SELECT COUNT(*) AS n FROM application WHERE status = 'in_review' AND admin_unit_id = $oneIn", $one),
                    'citizens' => $count("SELECT COUNT(*) AS n FROM citizen c JOIN citizen_address a ON a.citizen_id = c.id AND a.is_primary = 1 WHERE a.admin_unit_id = $oneIn", $one),
                    'documents_issued' => $count("SELECT COUNT(*) AS n FROM document d JOIN application a ON a.id = d.application_id WHERE d.status IN ('issued','verified') AND a.admin_unit_id = $oneIn", $one),
                    'complaints' => $count("SELECT COUNT(*) AS n FROM complaint c LEFT JOIN citizen_address ca ON ca.citizen_id = c.citizen_id AND ca.is_primary = 1 WHERE ca.admin_unit_id = $oneIn", $one),
                    'tickets_waiting' => $count("SELECT COUNT(*) AS n FROM queue_ticket WHERE office_id IN (SELECT id FROM office WHERE admin_unit_id = $oneIn)", $one),
                ];
            }
        }

        Response::success([
            'filtered_unit' => $requestedUnit,
            'by_unit' => $byUnit,
            'applications_total' => $count("SELECT COUNT(*) AS n FROM application WHERE admin_unit_id IN ($in)"),
            'applications_in_review' => $count("SELECT COUNT(*) AS n FROM application WHERE status = 'in_review' AND admin_unit_id IN ($in)"),
            'citizens_total' => $count("SELECT COUNT(*) AS n FROM citizen c JOIN citizen_address a ON a.citizen_id = c.id AND a.is_primary = 1 WHERE a.admin_unit_id IN ($in)"),
            'citizens_pending' => $count("SELECT COUNT(*) AS n FROM citizen c JOIN citizen_address a ON a.citizen_id = c.id AND a.is_primary = 1 WHERE c.status = 'pending_verification' AND a.admin_unit_id IN ($in)"),
            'documents_total' => $count("SELECT COUNT(*) AS n FROM document d JOIN application a ON a.id = d.application_id WHERE a.admin_unit_id IN ($in)"),
            'documents_issued' => $count("SELECT COUNT(*) AS n FROM document d JOIN application a ON a.id = d.application_id WHERE d.status IN ('issued','verified') AND a.admin_unit_id IN ($in)"),
            'payments_today' => $count("SELECT COUNT(*) AS n FROM payment p LEFT JOIN application a ON a.id = p.application_id WHERE DATE(p.created_at) = CURDATE() AND (a.admin_unit_id IN ($in) OR a.admin_unit_id IS NULL)"),
            'payments_revenue_total' => $revenue,
            'complaints_open' => $count("SELECT COUNT(*) AS n FROM complaint c
            LEFT JOIN citizen_address ca ON ca.citizen_id = c.citizen_id AND ca.is_primary = 1
            WHERE c.status IN ('submitted','acknowledged','in_progress')
              AND (ca.admin_unit_id IS NULL OR ca.admin_unit_id IN ($in))"),
            'tickets_waiting' => $count("SELECT COUNT(*) AS n FROM queue_ticket WHERE status = 'waiting'", []),
        ]);
    }
}

final class AuditController extends Controller
{
    public function index(Request $request): void
    {
        $this->requirePermission($request, 'AUDIT:VIEW');
        $scope = Auth::$context['scope_subtree'];
        $rows = Database::fetchAll(
            'SELECT timestamp, user_id, role_id, action, resource_type, resource_id, result, reason, ip_address
             FROM audit_log
             WHERE admin_unit_id IS NULL OR admin_unit_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')
             ORDER BY timestamp DESC LIMIT 200',
            $scope
        );
        Audit::log($request, 'VIEW_AUDIT_LOG', 'audit', null);
        Response::success(['audit_logs' => $rows]);
    }
}

final class AdminController extends Controller
{
    public function adminUnits(Request $request): void
    {
        $this->requirePermission($request, 'REPORT:VIEW');
        $rows = Database::fetchAll(
            'SELECT id, name, local_name, code, type, parent_id, status FROM admin_unit ORDER BY type, name'
        );
        Response::success(['admin_units' => $rows]);
    }

    public function createAdminUnit(Request $request): void
    {
        $this->requirePermission($request, 'SYSTEM:MANAGE');
        Validator::requireFields($request, ['name', 'type']);
        $types = ['federal', 'region', 'zone', 'woreda', 'kebele', 'other'];
        $type = (string)$request->input('type');
        if (!in_array($type, $types, true)) {
            Response::validationError(['type' => 'Must be one of ' . implode(', ', $types)]);
        }
        $parentId = $request->input('parent_id');
        if ($parentId !== null) {
            $parent = Database::fetchOne('SELECT id FROM admin_unit WHERE id = ?', [$parentId]);
            if ($parent === null) {
                Response::validationError(['parent_id' => 'Unknown parent unit']);
            }
        }
        $code = $request->input('code');
        if ($code !== null) {
            $dup = Database::fetchOne('SELECT id FROM admin_unit WHERE code = ?', [$code]);
            if ($dup !== null) {
                Response::error('CODE_EXISTS', 'Unit code already in use', 409);
            }
        }
        $id = uuid4();
        Database::run(
            'INSERT INTO admin_unit (id, name, local_name, code, type, parent_id, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                (string)$request->input('name'),
                (string)($request->input('local_name') ?? ''),
                $code,
                $type,
                $parentId,
                (string)($request->input('status') ?? 'active'),
                Auth::$context['user_id'] ?? null,
            ]
        );
        Audit::log($request, 'CREATE_ADMIN_UNIT', 'admin_unit', $id, null, ['type' => $type]);
        Response::success(['id' => $id, 'type' => $type, 'status' => 'created'], 201);
    }

    public function offices(Request $request): void
    {
        $this->requirePermission($request, 'OFFICE:MANAGE');
        $rows = Database::fetchAll(
            'SELECT o.id, o.name, o.local_name, o.address, o.is_active, au.name AS admin_unit_name
             FROM office o JOIN admin_unit au ON au.id = o.admin_unit_id
             ORDER BY au.name, o.name'
        );
        Response::success(['offices' => $rows]);
    }

    /** Active public office catalog for citizens (booking). */
    public function publicOffices(Request $request): void
    {
        $rows = Database::fetchAll(
            'SELECT o.id, o.name, o.local_name, o.address, au.name AS admin_unit_name
             FROM office o JOIN admin_unit au ON au.id = o.admin_unit_id
             WHERE o.is_active = 1
             ORDER BY au.name, o.name'
        );
        Response::success(['offices' => $rows]);
    }

    public function createOffice(Request $request): void
    {
        $this->requirePermission($request, 'OFFICE:MANAGE');
        Validator::requireFields($request, ['name', 'admin_unit_id']);
        $unit = Database::fetchOne('SELECT id FROM admin_unit WHERE id = ?', [$request->input('admin_unit_id')]);
        if ($unit === null) {
            Response::validationError(['admin_unit_id' => 'Unknown unit']);
        }
        Auth::assertInScope($request, $unit['id']);
        $id = uuid4();
        Database::run(
            'INSERT INTO office (id, admin_unit_id, name, address, working_hours, capacity) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $unit['id'], $request->input('name'), $request->input('address'),
             json_encode(['start' => '08:30', 'end' => '17:30']), (int)($request->input('capacity') ?? 20)]
        );
        Audit::log($request, 'CREATE_OFFICE', 'office', $id);
        Response::success(['id' => $id], 201);
    }

    public function updateOffice(Request $request): void
    {
        $this->requirePermission($request, 'OFFICE:MANAGE');
        $uuid = $request->routeParams['uuid'];
        $office = Database::fetchOne('SELECT * FROM office WHERE id = ?', [$uuid]);
        if ($office === null) {
            Response::notFound('Office not found');
        }
        $unitId = Database::fetchOne('SELECT admin_unit_id FROM office WHERE id = ?', [$uuid]);
        Auth::assertInScope($request, $unitId['admin_unit_id']);
        Database::run(
            'UPDATE office SET name = ?, address = ?, capacity = ?, is_active = ? WHERE id = ?',
            [
                (string)($request->input('name') ?? $office['name']),
                (string)($request->input('address') ?? $office['address']),
                (int)($request->input('capacity') ?? $office['capacity']),
                (int)($request->input('is_active') ?? $office['is_active']),
                $uuid,
            ]
        );
        Audit::log($request, 'UPDATE_OFFICE', 'office', $uuid, null, [
            'name' => $request->input('name') ?? $office['name'],
            'is_active' => $request->input('is_active') ?? $office['is_active'],
        ]);
        Response::success(['id' => $uuid, 'status' => 'updated']);
    }

    public function updateAdminUnit(Request $request): void
    {
        $this->requirePermission($request, 'SYSTEM:MANAGE');
        $uuid = $request->routeParams['uuid'];
        $unit = Database::fetchOne('SELECT * FROM admin_unit WHERE id = ?', [$uuid]);
        if ($unit === null) {
            Response::notFound('Administrative unit not found');
        }
        Database::run(
            'UPDATE admin_unit SET name = ?, local_name = ?, code = ?, status = ? WHERE id = ?',
            [
                (string)($request->input('name') ?? $unit['name']),
                (string)($request->input('local_name') ?? $unit['local_name'] ?? ''),
                (string)($request->input('code') ?? $unit['code'] ?? ''),
                (string)($request->input('status') ?? $unit['status']),
                $uuid,
            ]
        );
        Audit::log($request, 'UPDATE_ADMIN_UNIT', 'admin_unit', $uuid);
        Response::success(['id' => $uuid, 'status' => 'updated']);
    }

    public function listUsers(Request $request): void
    {
        $this->requirePermission($request, 'USER:MANAGE');
        $rows = Database::fetchAll(
            'SELECT u.id, u.username_hash, u.status, u.last_login, r.name AS role_name, au.name AS admin_unit_name
             FROM `user` u
             JOIN user_role ur ON ur.user_id = u.id
             JOIN role r ON r.id = ur.role_id
             JOIN admin_unit au ON au.id = ur.admin_unit_id
             WHERE ur.is_active = 1
             ORDER BY u.created_at DESC LIMIT 100'
        );
        Response::success(['users' => $rows]);
    }

public function assignRole(Request $request): void
    {
        $this->requirePermission($request, 'ROLE:ASSIGN');
        Validator::requireFields($request, ['user_id', 'role', 'admin_unit_id']);
        $role = Database::fetchOne('SELECT id FROM role WHERE name = ?', [$request->input('role')]);
        if ($role === null) {
            Response::validationError(['role' => 'Unknown role']);
        }
        $unit = Database::fetchOne('SELECT id FROM admin_unit WHERE id = ?', [$request->input('admin_unit_id')]);
        if ($unit === null) {
            Response::validationError(['admin_unit_id' => 'Unknown unit']);
        }
        Auth::assertInScope($request, $unit['id']);
        $userId = (string)$request->input('user_id');
        if (!Database::fetchOne('SELECT id FROM `user` WHERE id = ?', [$userId])) {
            Response::notFound('User not found');
        }
        Database::run(
            'INSERT INTO user_role (user_id, role_id, admin_unit_id) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE is_active = 1, admin_unit_id = VALUES(admin_unit_id)',
            [$userId, $role['id'], $unit['id']]
        );
        Audit::log($request, 'ASSIGN_ROLE', 'user', $userId, null, ['role' => $request->input('role')]);
        Response::success(['status' => 'assigned']);
    }

    public function configureService(Request $request): void
    {
        $this->requirePermission($request, 'SERVICE:CONFIGURE');
        Validator::requireFields($request, ['name', 'admin_unit_id']);
        $unit = Database::fetchOne('SELECT id FROM admin_unit WHERE id = ?', [$request->input('admin_unit_id')]);
        if ($unit === null) {
            Response::validationError(['admin_unit_id' => 'Unknown unit']);
        }
        Auth::assertInScope($request, $unit['id']);
        $id = uuid4();
        Database::run(
            'INSERT INTO service_catalog (id, name, description, eligibility, required_docs, workflow_id, admin_unit_id, fee_amount, currency, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $request->input('name'),
                $request->input('description'),
                json_encode($request->input('eligibility') ?? []),
                json_encode($request->input('required_docs') ?? []),
                $request->input('workflow_id'),
                $unit['id'],
                (float)($request->input('fee_amount') ?? 0),
                $request->input('currency') ?? 'ETB',
                (int)($request->input('is_active') ?? 1),
            ]
        );
        Audit::log($request, 'CONFIGURE_SERVICE', 'service', $id);
        Response::success(['id' => $id], 201);
    }

    public function updateService(Request $request): void
    {
        $this->requirePermission($request, 'SERVICE:CONFIGURE');
        $uuid = $request->routeParams['uuid'];
        $svc = Database::fetchOne('SELECT * FROM service_catalog WHERE id = ?', [$uuid]);
        if ($svc === null) {
            Response::notFound('Service not found');
        }
        Auth::assertInScope($request, $svc['admin_unit_id']);
        Database::run(
            'UPDATE service_catalog SET name = ?, description = ?, fee_amount = ?, currency = ?, is_active = ?, required_docs = ? WHERE id = ?',
            [
                (string)($request->input('name') ?? $svc['name']),
                (string)($request->input('description') ?? $svc['description'] ?? ''),
                (float)($request->input('fee_amount') ?? $svc['fee_amount']),
                (string)($request->input('currency') ?? $svc['currency']),
                (int)($request->input('is_active') ?? $svc['is_active']),
                json_encode($request->input('required_docs') ?? json_decode((string)$svc['required_docs'], true) ?? []),
                $uuid,
            ]
        );
        Audit::log($request, 'UPDATE_SERVICE', 'service', $uuid, null,
            ['name' => $request->input('name') ?? $svc['name'], 'is_active' => $request->input('is_active') ?? $svc['is_active']]);
        Response::success(['id' => $uuid, 'status' => 'updated']);
    }

    public function setUserStatus(Request $request): void
    {
        $this->requirePermission($request, 'USER:MANAGE');
        Validator::requireFields($request, ['status']);
        $status = (string)$request->input('status');
        if (!in_array($status, ['active', 'inactive', 'locked'], true)) {
            Response::validationError(['status' => 'Invalid status']);
        }
        $uuid = $request->routeParams['uuid'];
        $target = Database::fetchOne('SELECT id, status FROM `user` WHERE id = ?', [$uuid]);
        if ($target === null) {
            Response::notFound('User not found');
        }
        // Lockout guard: the last active administrator must never deactivate
        // themselves or the whole platform becomes unreachable.
        if ($status !== 'active' && $uuid === Auth::$context['user_id']) {
            $others = Database::fetchOne(
                "SELECT COUNT(*) AS active_admins FROM `user` u
                 JOIN user_role ur ON ur.user_id = u.id AND ur.is_active = 1
                 JOIN role r ON r.id = ur.role_id
                 WHERE u.status = 'active' AND u.id != ?
                   AND r.name IN ('system_admin', 'woreda_admin', 'kebele_admin')",
                [$uuid]
            );
            if ((int)($others['active_admins'] ?? 0) === 0) {
                Audit::log($request, 'UPDATE_USER_STATUS', 'user', $uuid, result: 'denied',
                    reason: 'self-deactivation as last active administrator');
                Response::error('LAST_ADMIN',
                    'You are the only active administrator. Create or activate another admin account first.', 409);
            }
            // Revoke this session's tokens so the self-deactivation applies now.
            $claims = Auth::$context['claims'] ?? [];
            if (!empty($claims['jti'])) {
                Database::run(
                    'INSERT IGNORE INTO token_denylist (jti, user_id, expires_at) VALUES (?, ?, ?)',
                    [$claims['jti'], $uuid, date('Y-m-d H:i:s', (int)($claims['exp'] ?? time()))]
                );
            }
        }
        Database::run('UPDATE `user` SET status = ?, locked_until = NULL WHERE id = ?', [$status, $uuid]);
        Audit::log($request, 'UPDATE_USER_STATUS', 'user', $uuid, null, ['status' => $status]);
        Response::success(['id' => $uuid, 'status' => $status]);
    }

    public function revokeRole(Request $request): void
    {
        $this->requirePermission($request, 'ROLE:ASSIGN');
        Validator::requireFields($request, ['user_id', 'role']);
        $uuid = (string)$request->input('user_id');
        $role = Database::fetchOne('SELECT id FROM role WHERE name = ?', [$request->input('role')]);
        if ($role === null) {
            Response::validationError(['role' => 'Unknown role']);
        }
        $updated = Database::run(
            "UPDATE user_role SET is_active = 0 WHERE user_id = ? AND role_id = ?",
            [$uuid, $role['id']]
        )->rowCount();
        Audit::log($request, 'REVOKE_ROLE', 'user', $uuid, null, ['role' => $request->input('role')]);
        Response::success(['revoked' => $updated]);
    }
}
final class SyncController extends Controller
{
    /** Accept a batch of queued local operations from a LOS device. */
    public function push(Request $request): void
    {
        $deviceId = (string)$request->input('device_id');
        if ($deviceId === '') {
            Response::validationError(['device_id' => 'Missing device id']);
        }
        $batch = $request->input('batch');
        if (!is_array($batch)) {
            $batch = [];
        }
        $accepted = 0;
        foreach ($batch as $item) {
            if (!is_array($item)) {
                continue;
            }
            Database::run(
                'INSERT INTO sync_queue (device_id, entity_type, entity_uuid, operation, payload_json, status)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $deviceId,
                    (string)($item['entity_type'] ?? 'unknown'),
                    (string)($item['entity_uuid'] ?? ''),
                    in_array($item['operation'] ?? 'insert', ['insert', 'update', 'delete'], true) ? $item['operation'] : 'insert',
                    json_encode($item['data'] ?? [], JSON_UNESCAPED_UNICODE),
                    'pending',
                ]
            );
            $accepted++;
        }
        Audit::log($request, 'SYNC_PUSH', 'sync_queue', null, null,
            ['device_id' => $deviceId, 'batch_size' => count($batch), 'accepted' => $accepted]);
        Response::success(['accepted' => $accepted, 'server_time' => date('c')], 201);
    }

    /** Acknowledge previously pushed batches as applied centrally. */
    public function ack(Request $request): void
    {
        $ids = $request->input('ids');
        if (!is_array($ids)) {
            Response::validationError(['ids' => 'Missing id list']);
        }
        $updated = 0;
        foreach ($ids as $id) {
            $updated += Database::run("UPDATE sync_queue SET status = 'synced', synced_at = NOW() WHERE id = ? AND status = 'pending'", [(int)$id])->rowCount();
        }
        Audit::log($request, 'SYNC_ACK', 'sync_queue', null, null, ['acked' => $updated]);
        Response::success(['synced' => $updated]);
    }
}

final class HealthController extends Controller
{
    /** Public health check — no personal data, rate limited, DB kept in the loop. */
    public function health(Request $request): void
    {
        $limiter = new RateLimiter(Database::pdo());
        if (!$limiter->allow('health:' . $request->ip, (int)Config::get('rate_limit.public', 30), $request->ip)) {
            Response::error('RATE_LIMITED', 'Too many requests', 429);
        }
        try {
            Database::fetchOne('SELECT 1');
            $db = 'up';
        } catch (Throwable) {
            $db = 'down';
        }
        Response::success([
            'status' => $db === 'up' ? 'ok' : 'degraded',
            'service' => 'locify',
            'version' => '2.1',
            'database' => $db,
            'time' => date('c'),
        ], $db === 'up' ? 200 : 503);
    }
}
