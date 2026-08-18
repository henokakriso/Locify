<?php

declare(strict_types=1);

/** Digital Kebele Services part 2: print queue + validated document uploads. */

final class PortalController extends Controller
{
    /** Public: active kebele list for address selection during registration. */
    public function units(Request $request): void
    {
        $rows = Database::fetchAll(
            "SELECT id, name, local_name, code FROM admin_unit
             WHERE type = 'kebele' AND status = 'active' ORDER BY name"
        );
        Response::success(['units' => $rows]);
    }

    /** Public self-registration (§3.1): creates a citizen, address and portal user. */
    public function register(Request $request): void
    {
        $limiter = new RateLimiter(Database::pdo());
        if (!$limiter->allow('portal-register:' . $request->ip, 10, $request->ip)) {
            Response::error('RATE_LIMITED', 'Too many registration attempts', 429);
        }
        Validator::requireFields($request, ['first_name', 'last_name', 'username', 'password', 'admin_unit_id']);
        $unit = Database::fetchOne(
            "SELECT id FROM admin_unit WHERE id = ? AND type = 'kebele' AND status = 'active'",
            [$request->input('admin_unit_id')]
        );
        if ($unit === null) {
            Response::validationError(['admin_unit_id' => 'Unknown kebele']);
        }
        $username = trim((string)$request->input('username'));
        if (!preg_match('/^[a-zA-Z0-9._-]{3,40}$/', $username)) {
            Response::validationError(['username' => '3-40 characters: letters, digits, dot, dash, underscore']);
        }
        if (Database::fetchOne('SELECT id FROM `user` WHERE username_hash = ?', [hash('sha256', $username)])) {
            Response::error('DUPLICATE_USER', 'Username already exists', 409);
        }
        $password = (string)$request->input('password');
        if (strlen($password) < 8) {
            Response::validationError(['password' => 'Password must be at least 8 characters']);
        }
        $dobEth = (string)($request->input('dob_eth') ?? '');
        if ($dobEth !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dobEth)) {
            Response::validationError(['dob_eth' => 'Expected YYYY-MM-DD in the Ethiopian calendar']);
        }
        $sex = strtoupper((string)($request->input('sex') ?? 'O'));
        if (!in_array($sex, ['M', 'F', 'O'], true)) {
            Response::validationError(['sex' => 'Must be M, F or O']);
        }

        $citizen = CitizenService::create($request, [
            'first_name' => (string)$request->input('first_name'),
            'middle_name' => (string)($request->input('middle_name') ?? ''),
            'last_name' => (string)$request->input('last_name'),
            'local_name' => (string)($request->input('local_name') ?? ''),
            'dob_eth' => $dobEth,
            'dob_greg' => (string)($request->input('dob_greg') ?? ''),
            'sex' => $sex,
            'phone' => (string)($request->input('phone') ?? ''),
            'email' => (string)($request->input('email') ?? ''),
            'national_id' => (string)($request->input('national_id') ?? ''),
            'address' => [
                'admin_unit_id' => $unit['id'],
                'village' => (string)($request->input('village') ?? ''),
                'house_no' => (string)($request->input('house_no') ?? ''),
            ],
        ], true);

        $citizenRow = Database::fetchOne('SELECT id, uuid FROM citizen WHERE uuid = ?', [$citizen['uuid']]);
        $userId = uuid4();
        Database::transaction(function () use ($userId, $username, $password, $citizenRow, $unit, $request, $citizen) {
            Database::run(
                'INSERT INTO `user` (id, username_hash, password_hash, citizen_id, status, mfa_enabled)
                 VALUES (?, ?, ?, ?, ?, 0)',
                [
                    $userId,
                    hash('sha256', $username),
                    password_hash($password, PASSWORD_ARGON2ID),
                    $citizenRow['id'],
                    'active',
                ]
            );
            $role = Database::fetchOne('SELECT id FROM role WHERE name = ?', ['citizen']);
            if ($role !== null) {
                Database::run(
                    'INSERT IGNORE INTO user_role (user_id, role_id, admin_unit_id) VALUES (?, ?, ?)',
                    [$userId, $role['id'], $unit['id']]
                );
            }
            Audit::log($request, 'PORTAL_REGISTER', 'citizen', $citizenRow['id'],
                null, ['username_hash' => hash('sha256', $username), 'admin_unit_id' => $unit['id']]);
        });

        Response::success([
            'citizen_uuid' => $citizen['uuid'],
            'verification_status' => 'pending_verification',
            'message' => 'Registration received. A kebele officer will verify your identity before you can apply for services.',
        ], 201);
    }

    /** Authenticated: the citizen's own profile + address (spec §3.3). */
    public function profile(Request $request): void
    {
        $this->requirePermission($request, 'CITIZEN:VIEW_SELF');
        $citizenId = Auth::$context['citizen_id'] ?? null;
        if ($citizenId === null) {
            Response::forbidden('No citizen profile linked to this account');
        }
        $row = Database::fetchOne(
            'SELECT c.*, ca.admin_unit_id, ca.village_enc, ca.house_no_enc, ca.gps_lat, ca.gps_long
             FROM citizen c
             LEFT JOIN citizen_address ca ON ca.citizen_id = c.id AND ca.is_primary = 1
             WHERE c.id = ?',
            [$citizenId]
        );
        if ($row === null) {
            Response::notFound('Citizen profile not found');
        }
        $unit = Database::fetchOne('SELECT name, local_name, code FROM admin_unit WHERE id = ?', [$row['admin_unit_id']]);
        Audit::log($request, 'VIEW_SELF_PROFILE', 'citizen', $citizenId);
        Response::success([
            ...CitizenService::present($row),
            'phone_mask' => $row['phone_hash'] !== null ? substr((string)$row['phone_hash'], 0, 8) . '…' : null,
            'address' => $row['admin_unit_id'] !== null ? [
                'admin_unit_id' => $row['admin_unit_id'],
                'admin_unit_name' => $unit['name'] ?? null,
                'admin_unit_local_name' => $unit['local_name'] ?? null,
                'admin_unit_code' => $unit['code'] ?? null,
                'village' => Crypto::decrypt($row['village_enc'] ?? null),
                'house_no' => Crypto::decrypt($row['house_no_enc'] ?? null),
            ] : null,
        ]);
    }
}

final class PrintController extends Controller
{
    /** Office-scoped print queue. */
    public function jobs(Request $request): void
    {
        $this->requirePermission($request, 'PRINT:OPERATE');
        $scope = Auth::$context['scope_subtree'];
        $rows = Database::fetchAll(
            'SELECT pj.id, pj.job_number, pj.document_id, d.document_number, pj.reason,
                    pj.reprint_reason, pj.status, pj.attempts, pj.print_started_at,
                    pj.printed_at, pj.created_at, a.application_number, a.citizen_id
             FROM print_jobs pj
             JOIN document d ON d.id = pj.document_id
             JOIN application a ON a.id = d.application_id
             WHERE a.admin_unit_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')
             ORDER BY pj.created_at DESC LIMIT 200',
            $scope
        );
        $pending = 0;
        foreach ($rows as &$row) {
            $row['citizen_name'] = citizenFullName(Database::pdo(), (string)$row['citizen_id']);
            if (in_array($row['status'], ['queued', 'printing'], true)) {
                $pending++;
            }
        }
        unset($row);
        Audit::log($request, 'VIEW_PRINT_JOBS', 'print_jobs');
        Response::success(['print_jobs' => $rows, 'pending' => $pending]);
    }

    /** Create a print job for a document (spec §25). */
    public function create(Request $request): void
    {
        $this->requirePermission($request, 'PRINT:OPERATE');
        $doc = Database::fetchOne(
            'SELECT d.*, a.admin_unit_id, a.citizen_id, s.issuance_mode
             FROM document d
             JOIN application a ON a.id = d.application_id
             JOIN service_catalog s ON s.id = a.service_catalog_id
             WHERE d.uuid = ?',
            [$request->routeParams['uuid']]
        );
        if ($doc === null) {
            Response::notFound('Document not found');
        }
        Auth::assertInScope($request, $doc['admin_unit_id']);

        $reason = (string)($request->input('reason') ?? 'original');
        if (!in_array($reason, ['original', 'copy', 'reissue', 'duplicate'], true)) {
            Response::validationError(['reason' => 'Invalid reason']);
        }
        if ($reason !== 'original' && (string)($request->input('reprint_reason') ?? '') === '') {
            Response::validationError(['reprint_reason' => 'Required for non-original print jobs']);
        }
        $open = Database::fetchOne(
            "SELECT id FROM print_jobs WHERE document_id = ? AND status IN ('queued','printing')",
            [$doc['id']]
        );
        if ($open !== null) {
            Response::error('JOB_EXISTS', 'Document already has an open print job', 409);
        }

        $id = uuid4();
        $number = nextPrintJobNumber(Database::pdo());
        Database::run(
            'INSERT INTO print_jobs (id, document_id, job_number, reason, reprint_reason,
                operator_user_id, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id, $doc['id'], $number, $reason,
                $reason !== 'original' ? mb_substr((string)$request->input('reprint_reason'), 0, 255) : null,
                Auth::$context['user_id'] ?? null, 'queued',
                Auth::$context['user_id'] ?? null,
            ]
        );
        Audit::log($request, 'CREATE_PRINT_JOB', 'print_job', $id, null,
            ['document' => $doc['document_number'], 'job_number' => $number, 'reason' => $reason]);
        Response::success(['id' => $id, 'job_number' => $number, 'status' => 'queued'], 201);
    }

    /** Advance a print job: start | complete | fail | cancel (spec §26). */
    public function update(Request $request): void
    {
        $this->requirePermission($request, 'PRINT:OPERATE');
        $job = Database::fetchOne(
            'SELECT pj.*, d.uuid AS document_uuid, d.id AS document_id, a.admin_unit_id
             FROM print_jobs pj
             JOIN document d ON d.id = pj.document_id
             JOIN application a ON a.id = d.application_id
             WHERE pj.id = ?',
            [$request->routeParams['uuid']]
        );
        if ($job === null) {
            Response::notFound('Print job not found');
        }
        Auth::assertInScope($request, $job['admin_unit_id']);
        $action = (string)$request->input('action');

        switch ($action) {
            case 'start':
                if (!in_array($job['status'], ['queued', 'quality_failed'], true)) {
                    Response::error('INVALID_STATE', 'Job cannot be started in its current state', 409);
                }
                $attempts = (int)$job['attempts'] + 1;
                if ($attempts > 3) {
                    Response::error('MAX_ATTEMPTS', 'Print job has reached the maximum retry limit', 409);
                }
                Database::run(
                    'UPDATE print_jobs SET status = ?, attempts = ?, print_started_at = NOW() WHERE id = ?',
                    ['printing', $attempts, $job['id']]
                );
                break;
            case 'complete':
                if ($job['status'] !== 'printing') {
                    Response::error('INVALID_STATE', 'Only printing jobs can be completed', 409);
                }
                Database::run(
                    'UPDATE print_jobs SET status = ?, printed_at = NOW(), printed_by = ? WHERE id = ?',
                    ['printed', Auth::$context['user_id'] ?? null, $job['id']]
                );
                Database::run(
                    'UPDATE document SET printed_at = NOW(), printed_by = ? WHERE id = ?',
                    [Auth::$context['user_id'] ?? null, $job['document_id']]
                );
                if (in_array($job['reason'], ['copy', 'reissue', 'duplicate'], true)) {
                    Database::run('UPDATE document SET status = ? WHERE id = ? AND status IN (?, ?)',
                        ['printed', $job['document_id'], 'signed', 'reviewed']);
                }
                Audit::log($request, 'COMPLETE_PRINT_JOB', 'print_job', $job['id'],
                    null, ['job_number' => $job['job_number']]);
                break;
            case 'fail':
                if ($job['status'] !== 'printing') {
                    Response::error('INVALID_STATE', 'Only printing jobs can fail the quality check', 409);
                }
                Database::run(
                    'UPDATE print_jobs SET status = ?, reprint_reason = ? WHERE id = ?',
                    ['quality_failed', mb_substr((string)($request->input('reason') ?? 'quality check failed'), 0, 255), $job['id']]
                );
                break;
            case 'cancel':
                if (in_array($job['status'], ['printed', 'cancelled'], true)) {
                    Response::error('INVALID_STATE', 'Job cannot be cancelled in its current state', 409);
                }
                Database::run(
                    "UPDATE print_jobs SET status = 'cancelled' WHERE id = ?",
                    [$job['id']]
                );
                break;
            default:
                Response::validationError(['action' => 'Invalid action']);
        }
        Audit::log($request, 'UPDATE_PRINT_JOB', 'print_job', $job['id'], null,
            ['action' => $action, 'job_number' => $job['job_number']]);
        Response::success(['id' => $job['id'], 'job_number' => $job['job_number'],
            'action' => $action, 'status' => $action === 'complete' ? 'printed' : null]);
    }
}

final class UploadController extends Controller
{
    private const ALLOWED_MIME = ['application/pdf', 'image/jpeg', 'image/png'];
    private const MAX_BYTES = 8 * 1024 * 1024;

    /** Multipart upload of a supporting document for an application (spec §34, §39). */
    public function upload(Request $request): void
    {
        Auth::require($request);
        if (!Auth::isCitizen($request)) {
            $this->requirePermission($request, 'APPLICATION:PROCESS');
        } else {
            $this->requirePermission($request, 'APPLICATION:CREATE');
        }
        $app = Database::fetchOne(
            'SELECT a.*, s.issuance_mode FROM application a
             JOIN service_catalog s ON s.id = a.service_catalog_id
             WHERE a.uuid = ?',
            [$request->routeParams['uuid']]
        );
        if ($app === null) {
            Response::notFound('Application not found');
        }
        Auth::assertResourceScope($request, $app['admin_unit_id']);
        if (Auth::isCitizen($request) && $app['citizen_id'] !== (Auth::$context['citizen_id'] ?? '')) {
            Response::forbidden('Not your application');
        }
        if (!in_array($app['status'], ['submitted', 'received', 'verification', 'document_check',
            'officer_review', 'needs_correction', 'review_required', 'on_hold'], true)) {
            Response::error('INVALID_STATE', 'Attachments can only be added while the application is under review', 409);
        }
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Response::validationError(['file' => 'A valid file is required']);
        }
        $file = $_FILES['file'];
        if ((int)$file['size'] > self::MAX_BYTES || (int)$file['size'] <= 0) {
            Response::validationError(['file' => 'File must be between 1 byte and 8 MB']);
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
        if (!is_string($mime) || !in_array($mime, self::ALLOWED_MIME, true)) {
            Response::validationError(['file' => 'Only PDF, JPG and PNG files are accepted']);
        }
        $originalName = basename((string)($file['original_name'] ?? $file['name'] ?? ''));
        if ($originalName === '' || preg_match('/[\\\\\/\0]/', $originalName)) {
            $originalName = 'document.' . ($mime === 'application/pdf' ? 'pdf' : 'jpg');
        }

        $fileName = 'upload_' . uuid4() . '_' . bin2hex(random_bytes(8));
        $dir = LOCIFY_STORAGE . '/uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $filePath = $dir . '/' . $fileName;
        $encrypted = Crypto::encrypt((string)file_get_contents((string)$file['tmp_name']));
        if ($encrypted === null || file_put_contents($filePath, $encrypted) === false) {
            Response::error('STORAGE_FAILED', 'Could not store the uploaded file', 500);
        }
        chmod($filePath, 0600);

        $docType = mb_substr((string)($request->input('document_type') ?? 'supporting_document'), 0, 64);
        $id = uuid4();
        Database::run(
            'INSERT INTO application_documents (id, application_id, document_type, file_reference,
                original_filename_enc, mime_type, size_bytes, uploaded_by, verification_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id, $app['id'], $docType,
                Crypto::encrypt($filePath) ?? $filePath,
                Crypto::encrypt($originalName),
                $mime, (int)$file['size'],
                Auth::$context['user_id'] ?? null, 'pending',
            ]
        );
        Audit::log($request, 'UPLOAD_APPLICATION_DOCUMENT', 'application_document', $id,
            null, ['application' => $app['application_number'], 'mime' => $mime, 'bytes' => (int)$file['size']]);
        Response::success([
            'id' => $id,
            'document_type' => $docType,
            'original_name' => $originalName,
            'mime_type' => $mime,
            'size_bytes' => (int)$file['size'],
            'verification_status' => 'pending',
        ], 201);
    }

    /** Officer verification of an uploaded supporting document (spec §34). */
    public function review(Request $request): void
    {
        $this->requirePermission($request, 'APPLICATION:PROCESS');
        $upload = Database::fetchOne(
            'SELECT ad.*, a.admin_unit_id FROM application_documents ad
             JOIN application a ON a.id = ad.application_id
             WHERE ad.id = ?',
            [$request->routeParams['docUuid']]
        );
        if ($upload === null) {
            Response::notFound('Upload not found');
        }
        Auth::assertInScope($request, $upload['admin_unit_id']);
        $action = (string)$request->input('action');
        if (!in_array($action, ['verify', 'reject'], true)) {
            Response::validationError(['action' => 'Verify or reject expected']);
        }
        if ($upload['verification_status'] !== 'pending') {
            Response::error('INVALID_STATE', 'Upload has already been reviewed', 409);
        }
        Database::run(
            'UPDATE application_documents SET verification_status = ?, verified_by = ?, verified_at = NOW()
             WHERE id = ?',
            [$action === 'verify' ? 'verified' : 'rejected', Auth::$context['user_id'] ?? null, $upload['id']]
        );
        Audit::log($request, 'REVIEW_APPLICATION_DOCUMENT', 'application_document', $upload['id'],
            null, ['action' => $action]);
        Response::success(['id' => $upload['id'], 'verification_status' => $action === 'verify' ? 'verified' : 'rejected']);
    }
}