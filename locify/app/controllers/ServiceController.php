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
        $rows = Database::fetchAll(
            'SELECT s.id, s.name, s.local_name, s.description, s.fee_amount, s.currency,
                    s.required_docs, au.name AS admin_unit_name
             FROM service_catalog s JOIN admin_unit au ON au.id = s.admin_unit_id
             WHERE s.is_active = 1 AND s.admin_unit_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')
             ORDER BY s.name',
            $scope
        );
        foreach ($rows as &$row) {
            $row['required_docs'] = json_decode((string)$row['required_docs'], true);
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
        Auth::assertInScope($request, $service['admin_unit_id']);

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

        $id = uuid4();
        $uuid = uuid4();
        $number = nextApplicationNumber(Database::pdo(), 'LOC');

        Database::transaction(function () use ($id, $uuid, $number, $citizenId, $service, $request) {
            Database::run(
                'INSERT INTO application (id, uuid, application_number, citizen_id, service_catalog_id,
                    admin_unit_id, status, form_data, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $id, $uuid, $number, $citizenId, $service['id'],
                    $service['admin_unit_id'], 'submitted',
                    json_encode($request->body['form'] ?? [], JSON_UNESCAPED_UNICODE),
                    Auth::$context['user_id'] ?? null,
                ]
            );
            WorkflowService::start($request, [
                'id' => $id,
                'service_catalog_id' => $service['id'],
            ]);
        });

        Audit::log($request, 'CREATE_APPLICATION', 'application', $id);
        NotificationService::sendToCitizen($request, $citizenId, 'app_received', ['application_number' => $number]);

        Response::success([
            'uuid' => $uuid,
            'application_number' => $number,
            'status' => 'submitted',
        ], 201);
    }

    public function show(Request $request): void
    {
        $this->requirePermission($request, 'APPLICATION:VIEW');
        $app = Database::fetchOne(
            'SELECT a.*, s.name AS service_name, c.uuid AS citizen_uuid
             FROM application a
             JOIN service_catalog s ON s.id = a.service_catalog_id
             JOIN citizen c ON c.id = a.citizen_id
             WHERE a.uuid = ?',
            [$request->routeParams['uuid']]
        );
        if ($app === null) {
            Response::notFound('Application not found');
        }
        Auth::assertInScope($request, $app['admin_unit_id']);
        // citizens can only view their own applications
        if (Auth::isCitizen($request) && $app['citizen_id'] !== Auth::$context['citizen_id']) {
            Response::forbidden('Not your application');
        }
        $steps = Database::fetchAll(
            'SELECT step_id, step_name, status, started_at, completed_at, comments
             FROM application_step WHERE application_id = ? ORDER BY step_id',
            [$app['id']]
        );
        Audit::log($request, 'VIEW_APPLICATION', 'application', $app['id']);
        Response::success([
            'uuid' => $app['uuid'],
            'application_number' => $app['application_number'],
            'service_name' => $app['service_name'],
            'status' => $app['status'],
            'current_step' => $app['current_step'],
            'submitted_at' => $app['submitted_at'],
            'completed_at' => $app['completed_at'],
            'steps' => $steps,
        ]);
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
            $sql = 'SELECT a.uuid, a.application_number, a.status, s.name AS service_name, a.created_at
                    FROM application a JOIN service_catalog s ON s.id = a.service_catalog_id
                    WHERE a.admin_unit_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')
                    ORDER BY a.created_at DESC LIMIT 100';
            $rows = Database::fetchAll($sql, $scope);
        }
        Response::success(['applications' => $rows]);
    }

    /** Advance/approve/reject — officer actions. */
    public function advance(Request $request): void
    {
        $this->requirePermission($request, 'APPLICATION:PROCESS');
        Validator::requireFields($request, ['action']);
        $action = (string)$request->input('action');
        if (!in_array($action, ['approve', 'reject', 'return', 'cancel', 'next'], true)) {
            Response::validationError(['action' => 'Invalid action']);
        }
        Response::json(WorkflowService::advance(
            $request,
            $request->routeParams['uuid'],
            $action,
            $request->input('comments')
        ));
    }
}
