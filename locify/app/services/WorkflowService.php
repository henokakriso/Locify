<?php

declare(strict_types=1);

/** Configurable workflow engine driven by workflow.definition_json.
 *
 *  Lifecycle (spec §5, §17): submitted → received → document_check/verification →
 *  officer_review → approved → document_generation → printing → ready_for_collection/
 *  digitally_delivered → completed, with exceptional states needs_correction,
 *  on_hold, payment_required/verified, review_required, cancelled, rejected.
 *  Every transition is appended to application_status_history and audited.
 */

final class WorkflowService
{
    /** Non-transitional (terminal) statuses — never used as a resume target. */
    private const TERMINAL = ['cancelled', 'rejected', 'completed'];

    /** Resume targets for correction/hold flows. */
    private const RESUME_FROM = ['needs_correction', 'on_hold', 'returned', 'submitted'];

    /**
     * Advance an application. Actions:
     *  next | approve | reject | cancel | hold | resume | mark-ready | complete |
     *  request-correction | return (legacy) | submit-correction
     */
    public static function advance(
        Request $request,
        string $applicationUuid,
        string $action,
        ?string $comments = null
    ): array {
        $app = Database::fetchOne(
            'SELECT a.*, s.name AS service_name, s.issuance_mode, s.service_code FROM application a
             JOIN service_catalog s ON s.id = a.service_catalog_id
             WHERE a.uuid = ?',
            [$applicationUuid]
        );
        if ($app === null) {
            Response::notFound('Application not found');
        }
        Auth::assertResourceScope($request, $app['admin_unit_id']);

        return match ($action) {
            'cancel' => self::cancel($request, $app, $comments),
            'reject' => self::simpleTransition($request, $app, 'rejected', 'REJECT_APPLICATION', 'app_rejected', $comments),
            'return' => self::requestCorrection($request, $app, $comments, null),
            'request-correction' => self::requestCorrection($request, $app, $comments, $request->input('correction_deadline')),
            'submit-correction' => self::submitCorrection($request, $app, $comments),
            'hold' => self::hold($request, $app, $comments),
            'resume' => self::resume($request, $app, $comments),
            'mark-ready' => self::markReady($request, $app, $comments),
            'complete' => self::complete($request, $app, $comments),
            'approve', 'next' => self::stepTransition($request, $app, $action, $comments),
            default => Response::validationError(['action' => 'Invalid action']),
        };
    }

    /** Step-driven transitions: complete current step, move to the next. */
    private static function stepTransition(Request $request, array $app, string $action, ?string $comments): array
    {
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

        $currentIdx = -1;
        foreach ($steps as $i => $step) {
            if ($step['step_id'] === $currentStepId) {
                $currentIdx = $i;
                break;
            }
        }

        if ($action === 'approve') {
            if ($currentIdx >= 0) {
                Database::run(
                    'UPDATE application_step SET status = ?, completed_at = NOW(), comments = ?
                     WHERE application_id = ? AND step_id = ? AND status != ?',
                    ['completed', $comments, $app['id'], $steps[$currentIdx]['step_id'], 'completed']
                );
            }
            self::applyStatus($request, $app, 'approved', $comments, 'APPROVE_APPLICATION', 'app_approved');
            return self::result($app, 'approved');
        }

        // proceed to the next step
        if ($currentIdx >= 0) {
            Database::run(
                'UPDATE application_step SET status = ?, completed_at = NOW(), comments = ?
                 WHERE application_id = ? AND step_id = ? AND status != ?',
                ['completed', $comments, $app['id'], $steps[$currentIdx]['step_id'], 'completed']
            );
        }
        $nextIdx = $currentIdx + 1;
        if ($nextIdx >= count($steps)) {
            self::applyStatus($request, $app, 'approved', $comments, 'COMPLETE_APPLICATION', 'app_approved');
            return self::result($app, 'approved');
        }

        $nextStep = $steps[$nextIdx];
        Database::run(
            'INSERT INTO application_step (id, application_id, step_id, step_name, status)
             VALUES (?, ?, ?, ?, ?)',
            [uuid4(), $app['id'], $nextStep['step_id'], $nextStep['name'], 'in_progress']
        );
        Database::run(
            'UPDATE application SET current_step = ?, updated_at = NOW() WHERE id = ?',
            [$nextStep['step_id'], $app['id']]
        );

        $status = self::statusForStep((string)($nextStep['name'] ?? ''));
        self::recordStepHistory($request, $app, $status);
        Audit::log($request, 'ADVANCE_APPLICATION', 'application', $app['id'], null,
            ['step' => $nextStep['step_id'], 'name' => $nextStep['name'], 'status' => $status]);

        $result = self::result($app, $status);
        $result['current_step'] = $nextStep['step_id'];
        $result['current_step_name'] = $nextStep['name'];
        $result['status'] = $status;
        return $result;
    }

    /** Derive a lifecycle status from the step name (heuristic, overridable per step). */
    private static function statusForStep(string $stepName): string
    {
        $name = strtolower($stepName);
        return match (true) {
            str_contains($name, 'document') || str_contains($name, 'generate')
                || str_contains($name, 'sign') => 'document_generation',
            str_contains($name, 'review') || str_contains($name, 'approv') => 'officer_review',
            str_contains($name, 'check') || str_contains($name, 'inspect') => 'document_check',
            default => 'verification',
        };
    }

    private static function recordStepHistory(Request $request, array $app, string $status): void
    {
        recordStatusHistory($app['id'], $status, $app['status'], null, Auth::$context['user_id'] ?? null);
    }

    private static function cancel(Request $request, array $app, ?string $comments): array
    {
        self::applyStatus($request, $app, 'cancelled', $comments, 'CANCEL_APPLICATION', null);
        return self::result($app, 'cancelled');
    }

    private static function simpleTransition(
        Request $request, array $app, string $status, string $auditAction,
        ?string $template, ?string $comments
    ): array {
        self::applyStatus($request, $app, $status, $comments, $auditAction, $template);
        return self::result($app, $status);
    }

    /** NEEDS CORRECTION: reason + deadline; citizen is notified (spec §17). */
    private static function requestCorrection(Request $request, array $app, ?string $comments, ?string $deadline): array
    {
        $deadline = $deadline ?? $request->input('correction_deadline');
        if ($comments === null || $comments === '') {
            Response::validationError(['comments' => 'A correction reason is required']);
        }
        Database::run(
            'UPDATE application SET status_notes = ?, correction_deadline = ? WHERE id = ?',
            [mb_substr($comments, 0, 500), $deadline !== null ? $deadline . ' 23:59:59' : null, $app['id']]
        );
        self::applyStatus($request, $app, 'needs_correction', $comments, 'CORRECTION_REQUESTED', 'app_correction');
        return self::result($app, 'needs_correction');
    }

    /** Citizen resubmits after correction → back to the last active status. */
    private static function submitCorrection(Request $request, array $app, ?string $comments): array
    {
        if (Auth::isCitizen($request) && $app['citizen_id'] !== Auth::$context['citizen_id']) {
            Response::forbidden('Not your application');
        }
        if ($app['status'] !== 'needs_correction') {
            Response::error('INVALID_STATE', 'Only applications needing correction can be resubmitted', 409);
        }
        $formJson = is_array($request->body['form'] ?? null)
            ? json_encode($request->body['form'], JSON_UNESCAPED_UNICODE)
            : null;
        Database::run(
            "UPDATE application SET correction_submitted_at = NOW(),
                status_notes = CONCAT(COALESCE(status_notes,''), ' | resubmitted: ', ?),
                form_data = COALESCE(?, form_data) WHERE id = ?",
            [mb_substr((string)$comments, 0, 200), $formJson, $app['id']]
        );
        $target = self::lastActiveStatus($app['id']);
        self::applyStatus($request, $app, $target, $comments, 'CORRECTION_SUBMITTED', null);
        return self::result($app, $target);
    }

    private static function hold(Request $request, array $app, ?string $comments): array
    {
        self::applyStatus($request, $app, 'on_hold', $comments, 'HOLD_APPLICATION', null);
        return self::result($app, 'on_hold');
    }

    private static function resume(Request $request, array $app, ?string $comments): array
    {
        $target = self::lastActiveStatus($app['id']);
        self::applyStatus($request, $app, $target, $comments, 'RESUME_APPLICATION', null);
        return self::result($app, $target);
    }

    /** Ready for collection / digital delivery, depending on issuance mode. */
    private static function markReady(Request $request, array $app, ?string $comments): array
    {
        $digital = ($app['issuance_mode'] ?? 'DIGITAL_ONLY') === 'DIGITAL_ONLY';
        $target = $digital ? 'digitally_delivered' : 'ready_for_collection';
        self::applyStatus($request, $app, $target, $comments, 'MARK_READY', 'app_ready');
        return self::result($app, $target);
    }

    /** Officer records collection/delivery → COMPLETED (spec §43). */
    private static function complete(Request $request, array $app, ?string $comments): array
    {
        Database::run(
            'UPDATE application SET completed_at = NOW() WHERE id = ?',
            [$app['id']]
        );
        // A printed (paper) document becomes formally issued only on collection.
        $printed = Database::fetchOne(
            "SELECT id FROM document WHERE application_id = ? AND status = 'printed' LIMIT 1",
            [$app['id']]
        );
        if ($printed !== null) {
            [$ey, $em, $ed] = Calendar::gregDateToEth(date('Y-m-d'));
            Database::run(
                "UPDATE document SET status = 'issued', collected_at = NOW(),
                    issued_at_eth = ?, issued_at_greg = ? WHERE id = ?",
                [Calendar::formatEth($ey, $em, $ed), date('Y-m-d'), $printed['id']]
            );
        }
        // Digitally delivered documents record collection at desk completion.
        Database::run(
            "UPDATE document SET collected_at = NOW()
             WHERE application_id = ? AND status = 'issued' AND collected_at IS NULL",
            [$app['id']]
        );
        self::applyStatus($request, $app, 'completed', $comments, 'COMPLETE_SERVICE', 'app_completed');
        $result = self::result($app, 'completed');
        $result['completed_at'] = date('Y-m-d H:i:s');
        return $result;
    }

    /** The last active (non-terminal, non-paused) status on record. */
    private static function lastActiveStatus(string $applicationId): string
    {
        $placeholders = implode(',', array_fill(0, count(array_merge(self::TERMINAL, self::RESUME_FROM)), '?'));
        $excluded = array_merge(self::TERMINAL, self::RESUME_FROM);
        $row = Database::fetchOne(
            'SELECT status FROM application_status_history
             WHERE application_id = ? AND status NOT IN (' . $placeholders . ')
             ORDER BY created_at DESC, id DESC LIMIT 1',
            array_merge([$applicationId], $excluded)
        );
        return $row['status'] ?? 'verification';
    }

    /** Single write point: update status, history, audit, notification. */
    private static function applyStatus(
        Request $request, array $app, string $status, ?string $notes,
        string $auditAction, ?string $template
    ): void {
        Database::run(
            'UPDATE application SET status = ?, status_notes = ?, updated_at = NOW() WHERE id = ?',
            [$status, $notes !== null ? mb_substr($notes, 0, 500) : $app['status_notes'] ?? null, $app['id']]
        );
        recordStatusHistory($app['id'], $status, $app['status'], $notes, Auth::$context['user_id'] ?? null);
        Audit::log($request, $auditAction, 'application', $app['id'], null, ['status' => $status], 'success', $notes);
        if ($template !== null) {
            NotificationService::sendToCitizen($request, $app['citizen_id'], $template, [
                'service_id' => $app['application_number'],
                'service_name' => $app['service_name'],
                'reason' => $notes !== null ? $notes : 'see your application',
                'deadline' => $app['correction_deadline'] ?? '—',
            ]);
        }
    }

    private static function result(array $app, string $status): array
    {
        return [
            'uuid' => $app['uuid'],
            'application_number' => $app['application_number'],
            'status' => $status,
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
            $status = self::statusForStep((string)($first['name'] ?? ''));
            Database::run(
                'INSERT INTO application_step (id, application_id, step_id, step_name, status)
                 VALUES (?, ?, ?, ?, ?)',
                [uuid4(), $application['id'], $first['step_id'], $first['name'], 'in_progress']
            );
            Database::run(
                'UPDATE application SET current_step = ?, status = ?, updated_at = NOW() WHERE id = ?',
                [$first['step_id'], $status, $application['id']]
            );
        } else {
            Database::run(
                "UPDATE application SET status = 'received', updated_at = NOW() WHERE id = ?",
                [$application['id']]
            );
        }
        $finalStatus = Database::fetchOne('SELECT status FROM application WHERE id = ?', [$application['id']])['status'];
        recordStatusHistory($application['id'], $finalStatus, 'submitted', null, Auth::$context['user_id'] ?? null);
    }
}