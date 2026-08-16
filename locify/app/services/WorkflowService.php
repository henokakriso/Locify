<?php

declare(strict_types=1);

/** Configurable workflow engine driven by workflow.definition_json. */

final class WorkflowService
{
    /** Advance an application to the next step, or complete/reject it. */
    public static function advance(
        Request $request,
        string $applicationUuid,
        string $action,
        ?string $comments = null
    ): array {
        $app = Database::fetchOne(
            'SELECT a.*, s.name AS service_name FROM application a
             JOIN service_catalog s ON s.id = a.service_catalog_id
             WHERE a.uuid = ?',
            [$applicationUuid]
        );
        if ($app === null) {
            Response::notFound('Application not found');
        }
        Auth::assertInScope($request, $app['admin_unit_id']);

        $workflow = Database::fetchOne(
            'SELECT w.definition_json FROM workflow w JOIN service_catalog s ON s.workflow_id = w.id
             WHERE s.id = ? AND w.status = ?',
            [$app['service_catalog_id'], 'active']
        );
        if ($workflow === null) {
            Response::error('NO_WORKFLOW', 'Service has no active workflow', 409);
        }

        $definition = json_decode((string)$workflow['definition_json'], true);
        $steps = $definition['steps'] ?? [];
        $currentStepId = $app['current_step'];

        // Find the current step; when none, start at the first.
        $currentIdx = -1;
        foreach ($steps as $i => $step) {
            if ($step['step_id'] === $currentStepId) {
                $currentIdx = $i;
                break;
            }
        }

        if ($action === 'cancel') {
            Database::run(
                "UPDATE application SET status = 'cancelled', updated_at = NOW() WHERE id = ?",
                [$app['id']]
            );
            Audit::log($request, 'CANCEL_APPLICATION', 'application', $app['id']);
            return ['uuid' => $applicationUuid, 'status' => 'cancelled'];
        }

        if ($action === 'reject') {
            Database::run(
                "UPDATE application SET status = 'rejected', updated_at = NOW() WHERE id = ?",
                [$app['id']]
            );
            Audit::log($request, 'REJECT_APPLICATION', 'application', $app['id'], null, null, 'success', $comments);
            return ['uuid' => $applicationUuid, 'status' => 'rejected'];
        }

        if ($action === 'return') {
            Database::run(
                "UPDATE application SET status = 'returned', updated_at = NOW() WHERE id = ?",
                [$app['id']]
            );
            Audit::log($request, 'RETURN_APPLICATION', 'application', $app['id'], null, null, 'success', $comments);
            return ['uuid' => $applicationUuid, 'status' => 'returned'];
        }

        // approval/generation: mark current step completed, move to next
        if ($currentIdx >= 0) {
            $step = $steps[$currentIdx];
            Database::run(
                'UPDATE application_step SET status = ?, completed_at = NOW(), comments = ?
                 WHERE application_id = ? AND step_id = ? AND status != ?',
                ['completed', $comments, $app['id'], $step['step_id'], 'completed']
            );
        }

        $nextIdx = $currentIdx + 1;
        if ($nextIdx >= count($steps)) {
            Database::run(
                "UPDATE application SET status = 'completed', completed_at = NOW(), current_step = NULL
                 WHERE id = ?",
                [$app['id']]
            );
            Audit::log($request, 'COMPLETE_APPLICATION', 'application', $app['id']);
            return ['uuid' => $applicationUuid, 'status' => 'completed'];
        }

        $nextStep = $steps[$nextIdx];
        Database::run(
            'INSERT INTO application_step (id, application_id, step_id, step_name, status)
             VALUES (?, ?, ?, ?, ?)',
            [uuid4(), $app['id'], $nextStep['step_id'], $nextStep['name'], 'in_progress']
        );
        Database::run(
            "UPDATE application SET current_step = ?, status = 'in_review', updated_at = NOW() WHERE id = ?",
            [$nextStep['step_id'], $app['id']]
        );
        Audit::log($request, 'ADVANCE_APPLICATION', 'application', $app['id'], null,
            ['step' => $nextStep['step_id'], 'name' => $nextStep['name']]);

        return [
            'uuid' => $applicationUuid,
            'status' => 'in_review',
            'current_step' => $nextStep['step_id'],
            'current_step_name' => $nextStep['name'],
        ];
    }

    /** Initialize a new application into the first workflow step. */
    public static function start(Request $request, array $application): void
    {
        $workflow = Database::fetchOne(
            'SELECT w.definition_json FROM workflow w JOIN service_catalog s ON s.workflow_id = w.id
             WHERE s.id = ? AND w.status = ?',
            [$application['service_catalog_id'], 'active']
        );
        $definition = $workflow !== null ? json_decode((string)$workflow['definition_json'], true) : null;
        $steps = $definition['steps'] ?? [];
        if ($steps !== []) {
            $first = $steps[0];
            Database::run(
                'INSERT INTO application_step (id, application_id, step_id, step_name, status)
                 VALUES (?, ?, ?, ?, ?)',
                [uuid4(), $application['id'], $first['step_id'], $first['name'], 'in_progress']
            );
            Database::run(
                "UPDATE application SET current_step = ?, status = 'in_review' WHERE id = ?",
                [$first['step_id'], $application['id']]
            );
        }
    }
}
