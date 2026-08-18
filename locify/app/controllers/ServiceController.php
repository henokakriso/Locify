<?php

declare(strict_types=1);

/** Service catalog and application endpoints. */

final class ServiceController extends Controller
{
    /** Public-ish catalog listing for citizens in scope. */
    public function catalog(Request $request): void
    {
        $this->requirePermission($request, 'APPLICATION:CREATE');
        $scope = Auth::$context['scope_subtree'];
        if (Auth::isCitizen($request)) {
            // Citizens see services of their kebele and any ancestor unit.
            $myUnit = Database::fetchOne(
                'SELECT admin_unit_id FROM citizen_address WHERE citizen_id = ? AND is_primary = 1',
                [Auth::$context['citizen_id']]
            );
            $scope = $myUnit !== null
                ? Auth::unitAncestorIds($myUnit['admin_unit_id'])
                : $scope;
        }
        $rows = Database::fetchAll(
            'SELECT s.id, s.name, s.local_name, s.description, s.fee_amount, s.currency,
                    s.required_docs, s.issuance_mode, s.requires_appointment, s.requires_approval,
                    s.requires_signature, s.allows_download, s.sla_hours, s.fields_json, s.service_code,
                    au.name AS admin_unit_name
             FROM service_catalog s JOIN admin_unit au ON au.id = s.admin_unit_id
             WHERE s.is_active = 1 AND s.admin_unit_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')
             ORDER BY s.name',
            $scope
        );
        foreach ($rows as &$row) {
            $row['required_docs'] = json_decode((string)$row['required_docs'], true) ?? [];
            $row['fields'] = json_decode((string)$row['fields_json'], true) ?? [];
            unset($row['fields_json']);
        }
        Response::success(['services' => $rows]);
    }

    /** Workflow definitions — officers see the official step sequence. */
    public function workflows(Request $request): void
    {
        $this->requirePermission($request, 'APPLICATION:VIEW');
        $rows = Database::fetchAll(
            'SELECT id, name, version, status, definition_json, updated_at
             FROM workflow WHERE status != \'archived\' ORDER BY name'
        );
        $workflows = [];
        foreach ($rows as $w) {
            $definition = json_decode((string)$w['definition_json'], true);
            $steps = $definition['steps'] ?? [];
            $workflows[] = [
                'id' => $w['id'],
                'name' => $w['name'],
                'version' => (int)$w['version'],
                'status' => $w['status'],
                'steps' => array_map(fn($s) => [
                    'step_id' => $s['step_id'] ?? null,
                    'name' => $s['name'] ?? $s['step_id'] ?? null,
                    'approval_required' => !empty($s['approval_required']),
                ], $steps),
                'updated_at' => $w['updated_at'],
            ];
        }
        Audit::log($request, 'VIEW_WORKFLOWS', 'workflow');
        Response::success(['workflows' => $workflows]);
    }
}

final class ApplicationController extends Controller
{
    public function create(Request $request): void
    {
        $this->requirePermission($request, 'APPLICATION:CREATE');
        Validator::requireFields($request, ['service_id']);
        $service = Database::fetchOne(
            'SELECT * FROM service_catalog WHERE id = ? AND is_active = 1',
            [$request->input('service_id')]
        );
        if ($service === null) {
            Response::validationError(['service_id' => 'Unknown service']);
        }
        Auth::assertResourceScope($request, $service['admin_unit_id']);

        $citizenId = Auth::$context['citizen_id'];
        if ($citizenId === null) {
            $citizen = Database::fetchOne(
                'SELECT id FROM citizen WHERE uuid = ?',
                [$request->input('citizen_uuid')]
            );
            if ($citizen === null) {
                Response::validationError(['citizen_uuid' => 'Unknown citizen']);
            }
            $citizenId = $citizen['id'];
        }
        $citizenRow = Database::fetchOne('SELECT status FROM citizen WHERE id = ?', [$citizenId]);
        if ($citizenRow === null || $citizenRow['status'] !== 'active') {
            Response::error('CITIZEN_NOT_VERIFIED', 'Citizen must be verified to apply', 409);
        }

        // Duplicate-application guard (spec §40)
        $active = ['submitted', 'received', 'verification', 'document_check', 'officer_review',
            'approved', 'on_hold', 'needs_correction', 'payment_required', 'payment_verified',
            'review_required', 'document_generation', 'printing', 'ready_for_collection', 'digitally_delivered'];
        $dup = Database::fetchOne(
            'SELECT application_number FROM application
             WHERE citizen_id = ? AND service_catalog_id = ? AND status IN (' .
                implode(',', array_fill(0, count($active), '?')) . ')
             ORDER BY created_at DESC LIMIT 1',
            array_merge([$citizenId, $service['id']], $active)
        );
        if ($dup !== null) {
            Response::error('DUPLICATE_APPLICATION',
                'You already have an active application (' . $dup['application_number'] . ') for this service', 409);
        }

        $unit = Database::fetchOne('SELECT code FROM admin_unit WHERE id = ?', [$service['admin_unit_id']]);
        $id = uuid4();
        $uuid = uuid4();
        $number = nextServiceNumber(Database::pdo(), (string)($unit['code'] ?? 'KBL'), (string)$service['service_code']);

        // Reissue/copy chain (spec §13-§14): a DOC application must reference an
        // already issued document owned by the applicant.
        $requestedDocumentId = null;
        if (($service['service_code'] ?? '') === 'DOC') {
            $origUuid = (string)($request->input('requested_document_id') ?? '');
            if (!isValidUuid($origUuid)) {
                Response::validationError(['requested_document_id' => 'Required: the original document to copy or reissue']);
            }
            $orig = Database::fetchOne(
                'SELECT id, citizen_id, status FROM document WHERE uuid = ?',
                [$origUuid]
            );
            if ($orig === null || !in_array($orig['status'], ['issued', 'verified', 'printed'], true)) {
                Response::validationError(['requested_document_id' => 'Only issued documents can be copied or reissued']);
            }
            if ($orig['citizen_id'] !== $citizenId && !Auth::isCitizen($request)) {
                Response::validationError(['requested_document_id' => 'Original document does not belong to the applicant']);
            }
            $requestedDocumentId = $orig['id'];
        }

        Database::transaction(function () use ($id, $uuid, $number, $citizenId, $service, $request, $requestedDocumentId) {
            $dueAt = null;
            if ((int)$service['sla_hours'] > 0) {
                $dueAt = Database::fetchOne(
                    'SELECT DATE_ADD(NOW(), INTERVAL ? HOUR) AS due', [(int)$service['sla_hours']]
                )['due'];
            }
            Database::run(
                'INSERT INTO application (id, uuid, application_number, citizen_id, service_catalog_id,
                    admin_unit_id, status, submitted_at, due_at, requested_document_id, form_data, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)',
                [
                    $id, $uuid, $number, $citizenId, $service['id'],
                    $service['admin_unit_id'], 'submitted', $dueAt, $requestedDocumentId,
                    json_encode($request->body['form'] ?? [], JSON_UNESCAPED_UNICODE),
                    Auth::$context['user_id'] ?? null,
                ]
            );
            WorkflowService::start($request, [
                'id' => $id,
                'service_catalog_id' => $service['id'],
            ]);
        });

        $created = Database::fetchOne('SELECT status FROM application WHERE id = ?', [$id]);
        Audit::log($request, 'CREATE_APPLICATION', 'application', $id);
        NotificationService::sendToCitizen($request, $citizenId, 'app_received', [
            'application_number' => $number,
            'service_id' => $number,
        ]);

        Response::success([
            'uuid' => $uuid,
            'application_number' => $number,
            'status' => $created['status'] ?? 'submitted',
        ], 201);
    }

    public function show(Request $request): void
    {
        $this->requirePermission($request, 'APPLICATION:VIEW');
        $app = Database::fetchOne(
            'SELECT a.*, s.name AS service_name, s.issuance_mode, s.sla_hours,
                    s.service_code, c.uuid AS citizen_uuid
             FROM application a
             JOIN service_catalog s ON s.id = a.service_catalog_id
             JOIN citizen c ON c.id = a.citizen_id
             WHERE a.uuid = ? OR a.application_number = ?',
            [$request->routeParams['uuid'], $request->routeParams['uuid']]
        );
        if ($app === null) {
            Response::notFound('Application not found');
        }
        Auth::assertResourceScope($request, $app['admin_unit_id']);
        // citizens can only view their own applications
        if (Auth::isCitizen($request) && $app['citizen_id'] !== Auth::$context['citizen_id']) {
            Response::forbidden('Not your application');
        }
        $steps = Database::fetchAll(
            'SELECT step_id, step_name, status, started_at, completed_at, comments
             FROM application_step WHERE application_id = ? ORDER BY step_id',
            [$app['id']]
        );
        $history = Database::fetchAll(
            'SELECT status, previous_status, notes, created_at
             FROM application_status_history WHERE application_id = ? ORDER BY created_at DESC, id DESC',
            [$app['id']]
        );
        $attachments = Database::fetchAll(
            'SELECT id, document_type, original_filename_enc, mime_type, size_bytes,
                    verification_status, uploaded_at
             FROM application_documents WHERE application_id = ? ORDER BY uploaded_at ASC',
            [$app['id']]
        );
        foreach ($attachments as &$att) {
            $att['original_name'] = Crypto::decrypt($att['original_filename_enc']);
            unset($att['original_filename_enc']);
        }
        unset($att);
        Audit::log($request, 'VIEW_APPLICATION', 'application', $app['id']);
        Response::success([
            'uuid' => $app['uuid'],
            'application_number' => $app['application_number'],
            'service_name' => $app['service_name'],
            'service_code' => $app['service_code'],
            'issuance_mode' => $app['issuance_mode'],
            'status' => $app['status'],
            'status_notes' => $app['status_notes'],
            'current_step' => $app['current_step'],
            'submitted_at' => $app['submitted_at'],
            'completed_at' => $app['completed_at'],
            'due_at' => $app['due_at'],
            'sla_hours' => (int)$app['sla_hours'],
            'overdue' => $app['due_at'] !== null && $app['status'] !== 'completed'
                && strtotime((string)$app['due_at']) < time(),
            'needs_correction' => $app['status'] === 'needs_correction',
            'requested_document_id' => $app['requested_document_id'],
            'correction_reason' => $app['status'] === 'needs_correction' ? $app['status_notes'] : null,
            'correction_deadline' => $app['correction_deadline'],
            'correction_submitted_at' => $app['correction_submitted_at'],
            'citizen_name' => citizenFullName(Database::pdo(), (string)$app['citizen_id']),
            'form_data' => json_decode((string)$app['form_data'], true) ?? (object)[],
            'steps' => $steps,
            'history' => $history,
            'attachments' => $attachments,
        ]);
    }

    /** Track an application by its public Service ID (spec §15). */
    public function byServiceNumber(Request $request): void
    {
        Auth::require($request);
        $this->requirePermission($request, Auth::isCitizen($request) ? 'APPLICATION:CREATE' : 'APPLICATION:VIEW');
        $request->routeParams['uuid'] = (string)$request->routeParams['number'];
        $this->show($request);
    }

    public function index(Request $request): void
    {
        $this->requirePermission($request, 'APPLICATION:VIEW');
        if (Auth::isCitizen($request)) {
            $sql = 'SELECT a.uuid, a.application_number, a.status, s.name AS service_name, a.created_at
                    FROM application a JOIN service_catalog s ON s.id = a.service_catalog_id
                    WHERE a.citizen_id = ? ORDER BY a.created_at DESC LIMIT 100';
            $rows = Database::fetchAll($sql, [Auth::$context['citizen_id']]);
        } else {
            $scope = Auth::$context['scope_subtree'];
            $sql = 'SELECT a.uuid, a.application_number, a.status, a.current_step, s.name AS service_name,
                           a.created_at, a.citizen_id
                    FROM application a JOIN service_catalog s ON s.id = a.service_catalog_id
                    WHERE a.admin_unit_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')
                    ORDER BY a.created_at DESC LIMIT 100';
            $rows = Database::fetchAll($sql, $scope);
            foreach ($rows as &$row) {
                $row['citizen_name'] = citizenFullName(Database::pdo(), (string)$row['citizen_id']);
            }
            unset($row);
        }
        Response::success(['applications' => $rows]);
    }

    /** Advance/approve/reject/correct — lifecycle actions (spec §5, §17). */
    public function advance(Request $request): void
    {
        Validator::requireFields($request, ['action']);
        $action = (string)$request->input('action');
        $isCitizenAction = in_array($action, ['submit-correction'], true);
        $this->requirePermission($request, $isCitizenAction ? 'APPLICATION:CREATE' : 'APPLICATION:PROCESS');
        $allowed = ['next', 'approve', 'reject', 'return', 'cancel', 'hold', 'resume',
            'mark-ready', 'complete', 'request-correction', 'submit-correction'];
        if (!in_array($action, $allowed, true)) {
            Response::validationError(['action' => 'Invalid action']);
        }
        $result = WorkflowService::advance(
            $request,
            $request->routeParams['uuid'],
            $action,
            $request->input('comments')
        );
        Response::json($result);
    }
}
