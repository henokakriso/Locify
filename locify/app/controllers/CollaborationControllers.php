<?php

declare(strict_types=1);

/** Collaboration: chat (citizen ↔ office) and government institutions. */

final class ChatController extends Controller
{
    /** Public catalog of active kebeles a citizen can open a chat with. */
    public function units(Request $request): void
    {
        $this->requireAuth($request);
        $rows = Database::fetchAll(
            "SELECT u.id, u.name, u.local_name, o.name AS office_name
             FROM admin_unit u
             JOIN office o ON o.admin_unit_id = u.id AND o.is_active = 1
             WHERE u.type = 'kebele' AND u.status = 'active'
             ORDER BY u.name"
        );
        Response::success(['units' => $rows]);
    }

    /** Citizen opens a new conversation with an office. */
    public function create(Request $request): void
    {
        $this->requirePermission($request, 'CHAT:SEND');
        Validator::requireFields($request, ['admin_unit_id', 'subject', 'message']);
        if (Auth::$context['citizen_id'] === null) {
            Response::forbidden('Citizen role required');
        }
        $unit = Database::fetchOne(
            "SELECT id FROM admin_unit WHERE id = ? AND type IN ('kebele','woreda') AND status = 'active'",
            [$request->input('admin_unit_id')]
        );
        if ($unit === null) {
            Response::validationError(['admin_unit_id' => 'Unknown unit']);
        }
        $subject = trim((string)$request->input('subject'));
        $message = trim((string)$request->input('message'));
        if (mb_strlen($subject) > 255 || mb_strlen($message) > 600) {
            Response::validationError(['message' => 'Subject max 255 chars, message max 600 chars']);
        }
        $convId = uuid4();
        Database::transaction(function () use ($convId, $request, $unit, $subject, $message) {
            Database::run(
                'INSERT INTO conversation (id, citizen_id, admin_unit_id, subject, status)
                 VALUES (?, ?, ?, ?, ?)',
                [$convId, Auth::$context['citizen_id'], $unit['id'], $subject, 'open']
            );
            Database::run(
                'INSERT INTO message (id, conversation_id, sender_id, sender_role, body_enc, is_read)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [uuid4(), $convId, Auth::$context['citizen_id'], 'citizen',
                 Crypto::encrypt($message), 1]
            );
        });
        Audit::log($request, 'CREATE_CONVERSATION', 'chat', $convId);
        Response::success(['id' => $convId, 'status' => 'open'], 201);
    }

    /** Conversations visible to the caller (citizen: own; officer: scope units). */
    public function index(Request $request): void
    {
        $this->requirePermission($request, 'CHAT:VIEW');
        if (Auth::isCitizen($request)) {
            $rows = Database::fetchAll(
                "SELECT c.id, c.subject, c.status, c.created_at, u.name AS unit_name,
                        (SELECT COUNT(*) FROM message m WHERE m.conversation_id = c.id AND m.is_read = 0 AND m.sender_role = 'officer') AS unread
                 FROM conversation c JOIN admin_unit u ON u.id = c.admin_unit_id
                 WHERE c.citizen_id = ? ORDER BY c.updated_at DESC LIMIT 100",
                [Auth::$context['citizen_id']]
            );
        } else {
            $scope = Auth::$context['scope_subtree'];
            $rows = Database::fetchAll(
                "SELECT c.id, c.subject, c.status, c.created_at, u.name AS unit_name,
                        (SELECT COUNT(*) FROM message m WHERE m.conversation_id = c.id AND m.is_read = 0 AND m.sender_role = 'citizen') AS unread,
                        (SELECT COUNT(*) FROM message m WHERE m.conversation_id = c.id) AS total_messages
                 FROM conversation c JOIN admin_unit u ON u.id = c.admin_unit_id
                 WHERE c.admin_unit_id IN (" . implode(',', array_fill(0, count($scope), '?')) . ")
                 ORDER BY c.updated_at DESC LIMIT 100",
                $scope
            );
        }
        Response::success(['conversations' => $rows]);
    }

    /** Full message thread. Both parties must be participants. */
    public function messages(Request $request): void
    {
        $this->requirePermission($request, 'CHAT:VIEW');
        $conv = Database::fetchOne('SELECT * FROM conversation WHERE id = ?', [$request->routeParams['uuid']]);
        if ($conv === null) {
            Response::notFound('Conversation not found');
        }
        $this->assertParticipant($request, $conv);
        $rows = Database::fetchAll(
            'SELECT id, sender_role, body_enc, is_read, created_at FROM message
             WHERE conversation_id = ? ORDER BY created_at ASC LIMIT 500',
            [$conv['id']]
        );
        foreach ($rows as &$row) {
            $row['body'] = Crypto::decrypt((string)$row['body_enc']) ?? '';
            unset($row['body_enc']);
        }
        Audit::log($request, 'VIEW_CONVERSATION', 'chat', $conv['id']);
        Response::success([
            'conversation' => [
                'id' => $conv['id'], 'subject' => $conv['subject'],
                'status' => $conv['status'], 'created_at' => $conv['created_at'],
                'admin_unit_id' => $conv['admin_unit_id'],
            ],
            'messages' => $rows,
        ]);
    }

    public function send(Request $request): void
    {
        $this->requirePermission($request, 'CHAT:SEND');
        Validator::requireFields($request, ['message']);
        $conv = Database::fetchOne('SELECT * FROM conversation WHERE id = ?', [$request->routeParams['uuid']]);
        if ($conv === null) {
            Response::notFound('Conversation not found');
        }
        $this->assertParticipant($request, $conv);
        if ($conv['status'] === 'closed') {
            Response::error('CONVERSATION_CLOSED', 'This conversation has been closed', 409);
        }
        $message = trim((string)$request->input('message'));
        if ($message === '' || mb_strlen($message) > 600) {
            Response::validationError(['message' => 'Message 1–600 chars']);
        }
        $isCitizen = Auth::isCitizen($request) && (string)Auth::$context['citizen_id'] === (string)$conv['citizen_id'];
        Database::run(
            'INSERT INTO message (id, conversation_id, sender_id, sender_role, body_enc, is_read)
             VALUES (?, ?, ?, ?, ?, ?)',
            [uuid4(), $conv['id'], Auth::$context['user_id'], $isCitizen ? 'citizen' : 'officer',
             Crypto::encrypt($message), $isCitizen ? 1 : 0]
        );
        Database::run('UPDATE conversation SET updated_at = NOW() WHERE id = ?', [$conv['id']]);
        Audit::log($request, 'SEND_MESSAGE', 'chat', $conv['id']);
        Response::success(['status' => 'sent'], 201);
    }

    public function markRead(Request $request): void
    {
        $this->requirePermission($request, 'CHAT:VIEW');
        $conv = Database::fetchOne('SELECT * FROM conversation WHERE id = ?', [$request->routeParams['uuid']]);
        if ($conv === null) {
            Response::notFound('Conversation not found');
        }
        $this->assertParticipant($request, $conv);
        $marker = Auth::isCitizen($request) && (string)Auth::$context['citizen_id'] === (string)$conv['citizen_id']
            ? 'officer' : 'citizen';
        Database::run(
            'UPDATE message SET is_read = 1, read_at = NOW()
             WHERE conversation_id = ? AND sender_role = ?',
            [$conv['id'], $marker]
        );
        Response::success(['status' => 'read']);
    }

    public function close(Request $request): void
    {
        $this->requirePermission($request, 'CHAT:MANAGE');
        $conv = Database::fetchOne('SELECT * FROM conversation WHERE id = ?', [$request->routeParams['uuid']]);
        if ($conv === null) {
            Response::notFound('Conversation not found');
        }
        Auth::assertInScope($request, $conv['admin_unit_id']);
        Database::run('UPDATE conversation SET status = ? WHERE id = ?', ['closed', $conv['id']]);
        Audit::log($request, 'CLOSE_CONVERSATION', 'chat', $conv['id']);
        Response::success(['id' => $conv['id'], 'status' => 'closed']);
    }

    private function assertParticipant(Request $request, array $conv): void
    {
        if (Auth::isCitizen($request)) {
            if (Auth::$context['citizen_id'] === null || (string)Auth::$context['citizen_id'] !== (string)$conv['citizen_id']) {
                Response::forbidden('Not your conversation');
            }
            return;
        }
        Auth::assertInScope($request, $conv['admin_unit_id']);
    }
}

final class InstitutionController extends Controller
{
    /** Catalog for officers (active institutions only). */
    public function index(Request $request): void
    {
        $this->requirePermission($request, 'INSTITUTION:VIEW');
        $rows = Database::fetchAll(
            'SELECT i.id, i.name, i.short_name, i.category, i.contact, i.is_active,
                    i.has_token AS has_token
             FROM (SELECT i.*, (i.api_token_hash IS NOT NULL) AS has_token FROM institution i) i
             ORDER BY i.name'
        );
        Response::success(['institutions' => $rows]);
    }

    public function create(Request $request): void
    {
        $this->requirePermission($request, 'INSTITUTION:MANAGE');
        Validator::requireFields($request, ['name']);
        $name = trim((string)$request->input('name'));
        if (mb_strlen($name) > 255) {
            Response::validationError(['name' => 'Too long']);
        }
        if (Database::fetchOne('SELECT id FROM institution WHERE name = ?', [$name])) {
            Response::error('DUPLICATE_INSTITUTION', 'Institution with this name exists', 409);
        }
        $category = (string)($request->input('category') ?? 'other_gov');
        $allowed = ['kebele', 'woreda', 'zone', 'region', 'federal_agency', 'ministry', 'other_gov'];
        if (!in_array($category, $allowed, true)) {
            Response::validationError(['category' => 'Invalid category']);
        }
        $id = uuid4();
        $contact = trim((string)($request->input('contact') ?? ''));
        Database::run(
            'INSERT INTO institution (id, name, short_name, category, admin_unit_id, contact, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $name, $request->input('short_name') ?: null, $category,
             $request->input('admin_unit_id') ?: null, $contact ?: null, 1]
        );
        Audit::log($request, 'CREATE_INSTITUTION', 'institution', $id);
        Response::success(['id' => $id], 201);
    }

    public function updateStatus(Request $request): void
    {
        $this->requirePermission($request, 'INSTITUTION:MANAGE');
        $inst = Database::fetchOne('SELECT * FROM institution WHERE id = ?', [$request->routeParams['uuid']]);
        if ($inst === null) {
            Response::notFound('Institution not found');
        }
        $status = (int)($request->input('is_active') ?? 1);
        Database::run('UPDATE institution SET is_active = ? WHERE id = ?', [$status, $inst['id']]);
        Audit::log($request, 'UPDATE_INSTITUTION', 'institution', $inst['id'], null, ['is_active' => $status]);
        Response::success(['id' => $inst['id'], 'is_active' => $status]);
    }

    /** Generate a fresh API token (shown once). INSTITUTION:MANAGE. */
    public function issueToken(Request $request): void
    {
        $this->requirePermission($request, 'INSTITUTION:MANAGE');
        $inst = Database::fetchOne('SELECT * FROM institution WHERE id = ?', [$request->routeParams['uuid']]);
        if ($inst === null) {
            Response::notFound('Institution not found');
        }
        $token = bin2hex(random_bytes(32));
        Database::run(
            'UPDATE institution SET api_token_hash = ? WHERE id = ?',
            [hash('sha256', $token), $inst['id']]
        );
        Audit::log($request, 'ISSUE_INSTITUTION_TOKEN', 'institution', $inst['id']);
        Response::success(['token' => $token, 'token_hash' => hash('sha256', $token)]);
    }

    /** Institution pulls document metadata by verification code (creates pending request on first contact). */
    public function inspect(Request $request): void
    {
        $inst = $this->authenticateInstitution($request);
        Validator::requireFields($request, ['verification_code']);
        $code = trim((string)$request->input('verification_code'));
        $doc = Database::fetchOne(
            'SELECT d.id, d.document_number, d.document_type, d.title, d.status FROM document d
             WHERE d.verification_code = ?',
            [$code]
        );
        if ($doc === null || $doc['status'] !== 'issued') {
            Response::notFound('Issued document not found for this verification code');
        }
        $existing = Database::fetchOne(
            'SELECT * FROM institution_document_request WHERE institution_id = ? AND document_id = ? AND status = ?',
            [$inst['id'], $doc['id'], 'approved']
        );
        if ($existing === null) {
            Database::run(
                'INSERT IGNORE INTO institution_document_request (id, institution_id, document_id, purpose, status)
                 VALUES (?, ?, ?, ?, ?)',
                [uuid4(), $inst['id'], $doc['id'],
                 (string)($request->input('purpose') ?? 'Official verification'), 'pending']
            );
            SecurityEvent::log('institution_document_inspected', 'info', $request, ['institution_id' => $inst['id'], 'document_id' => $doc['id']]);
            Response::success([
                'status' => 'request_required',
                'message' => 'Request forwarded to the issuing office. Approved institutions receive full record access.',
                'document' => [
                    'document_number' => $doc['document_number'],
                    'document_type' => $doc['document_type'],
                    'title' => $doc['title'],
                    'status' => $doc['status'],
                ],
            ]);
        }
        Response::success(['status' => 'approved', 'document' => $this->fullRecord($request, $doc['id'])]);
    }

    /** Officer reviews pending institution requests (scoped to their units). */
    public function requests(Request $request): void
    {
        $this->requirePermission($request, 'INSTITUTION:VIEW');
        $scope = Auth::$context['scope_subtree'];
        $rows = Database::fetchAll(
            'SELECT r.id, r.document_id, r.purpose, r.status, r.created_at,
                    i.name AS institution_name, i.category,
                    d.document_number, d.document_type,
                    a.admin_unit_id
             FROM institution_document_request r
             JOIN institution i ON i.id = r.institution_id
             JOIN document d ON d.id = r.document_id
             JOIN application a ON a.id = d.application_id
             WHERE r.status = "pending" AND a.admin_unit_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')
             ORDER BY r.created_at DESC LIMIT 100',
            $scope
        );
        Response::success(['requests' => $rows]);
    }

    public function decide(Request $request): void
    {
        $this->requirePermission($request, 'DOCUMENT:PULL');
        Validator::requireFields($request, ['decision']);
        $req = Database::fetchOne(
            'SELECT r.*, a.admin_unit_id FROM institution_document_request r
             JOIN document d ON d.id = r.document_id
             JOIN application a ON a.id = d.application_id
             WHERE r.id = ?',
            [$request->routeParams['uuid']]
        );
        if ($req === null) {
            Response::notFound('Request not found');
        }
        Auth::assertInScope($request, $req['admin_unit_id']);
        $decision = (string)$request->input('decision');
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            Response::validationError(['decision' => 'Invalid decision']);
        }
        Database::run(
            'UPDATE institution_document_request SET status = ?, decided_by = ?, decided_at = NOW()
             WHERE id = ?',
            [$decision, Auth::$context['user_id'], $req['id']]
        );
        Audit::log($request, 'DECIDE_INSTITUTION_REQUEST', 'institution_request', $req['id'], null, ['decision' => $decision]);
        Response::success(['id' => $req['id'], 'status' => $decision]);
    }

    /** Full record pull by an approved institution (token-authenticated). */
    public function pull(Request $request): void
    {
        $inst = $this->authenticateInstitution($request);
        $doc = Database::fetchOne(
            'SELECT id, uuid, document_number, document_type, title, status FROM document WHERE uuid = ?',
            [$request->routeParams['uuid']]
        );
        if ($doc === null || $doc['status'] !== 'issued') {
            Response::notFound('Document not found or not issued');
        }
        $approved = Database::fetchOne(
            'SELECT * FROM institution_document_request
             WHERE institution_id = ? AND document_id = ? AND status = ?',
            [$inst['id'], $doc['id'], 'approved']
        );
        if ($approved === null) {
            Response::forbidden('No approved access for this document');
        }
        SecurityEvent::log('institution_document_pulled', 'info', $request, ['institution_id' => $inst['id'], 'document_id' => $doc['id']]);
        Response::success(['document' => $this->fullRecord($request, $doc['id'])]);
    }

    private function authenticateInstitution(Request $request): array
    {
        $token = (string)($request->header('Authorization') ?? '');
        if (str_starts_with($token, 'Bearer ')) {
            $token = substr($token, 7);
        }
        if ($token === '') {
            Response::unauthorized('Institution API token required');
        }
        $inst = Database::fetchOne(
            'SELECT id, name, is_active, api_token_hash FROM institution WHERE api_token_hash = ?',
            [hash('sha256', $token)]
        );
        if ($inst === null || $inst['is_active'] != 1) {
            SecurityEvent::log('invalid_institution_token', 'high', $request);
            Response::unauthorized('Invalid institution token');
        }
        $rateOk = (new RateLimiter(Database::pdo()))->allow('inst:' . $inst['id'], 30, $request->ip);
        if (!$rateOk) {
            Response::error('RATE_LIMITED', 'Institution request limit reached', 429);
        }
        return $inst;
    }

    private function fullRecord(Request $request, string $documentId): array
    {
        $row = Database::fetchOne(
            'SELECT d.document_number, d.document_type, d.title, d.status, d.issued_at_eth,
                    d.issued_at_greg, d.verification_code, c.first_name_enc, c.middle_name_enc,
                    c.last_name_enc, au.name AS issuing_unit
             FROM document d
             JOIN application a ON a.id = d.application_id
             JOIN citizen c ON c.id = d.citizen_id
             JOIN admin_unit au ON au.id = a.admin_unit_id
             WHERE d.id = ?',
            [$documentId]
        );
        if ($row === null) {
            Response::notFound('Document not found');
        }
        return [
            'document_number' => $row['document_number'],
            'document_type' => $row['document_type'],
            'title' => $row['title'],
            'status' => $row['status'],
            'issued_ethiopian' => $row['issued_at_eth'],
            'issued_gregorian' => $row['issued_at_greg'],
            'verification_code' => $row['verification_code'],
            'issuing_unit' => $row['issuing_unit'],
            'citizen' => [
                'first_name' => Crypto::decrypt((string)$row['first_name_enc']),
                'middle_name' => Crypto::decrypt((string)$row['middle_name_enc']),
                'last_name' => Crypto::decrypt((string)$row['last_name_enc']),
            ],
        ];
    }
}

/**
 * PaperController — printable paper copy of an issued document.
 * Lookup by document number or verification code; full record scoped
 * to the issuing unit (officers only). The printable view embeds the
 * verification code as a QR so anyone can verify authenticity.
 */
final class PaperController extends Controller
{
    public function lookup(Request $request): void
    {
        $this->requirePermission($request, 'DOCUMENT:VIEW');
        $key = trim((string)($request->query['number'] ?? ''));
        $code = trim((string)($request->query['code'] ?? ''));
        if ($key === '' && $code === '') {
            Response::validationError(['number' => 'Document number or verification code required']);
        }
        if ($key !== '' && !preg_match('/^LOC-DOC-\d{4}-\d{6}$/', $key)) {
            Response::validationError(['number' => 'Invalid document number']);
        }
        if ($code !== '' && !preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code)) {
            Response::validationError(['code' => 'Invalid verification code']);
        }
        $row = Database::fetchOne(
            "SELECT d.id, d.uuid, d.document_number, d.document_type, d.title, d.status,
                    d.issued_at_eth, d.issued_at_greg, d.verification_code,
                    c.first_name_enc, c.middle_name_enc, c.last_name_enc, au.name AS issuing_unit
             FROM document d
             JOIN application a ON a.id = d.application_id
             JOIN citizen c ON c.id = d.citizen_id
             JOIN admin_unit au ON au.id = a.admin_unit_id
             WHERE (? != '' AND d.document_number = ?) OR (? != '' AND d.verification_code = ?)
             LIMIT 1",
            [$key, $key, $code, $code]
        );
        if ($row === null) {
            Response::notFound('Document not found');
        }
        Auth::assertInScope(
            $request,
            (string)Database::fetchOne(
                'SELECT a.admin_unit_id FROM application a JOIN document d ON d.application_id = a.id WHERE d.id = ?',
                [$row['id']]
            )['admin_unit_id']
        );
        if ($row['status'] !== 'issued' && $row['status'] !== 'verified') {
            Response::error('NOT_ISSUED', 'Only issued documents can be printed', 409);
        }
        Audit::log($request, 'PRINT_PAPER_COPY', 'document', $row['uuid']);
        Response::success([
            'uuid' => $row['uuid'],
            'document_number' => $row['document_number'],
            'document_type' => $row['document_type'],
            'title' => $row['title'],
            'status' => $row['status'],
            'issued_ethiopian' => $row['issued_at_eth'],
            'issued_gregorian' => $row['issued_at_greg'],
            'verification_code' => $row['verification_code'],
            'verify_url' => 'http' . (($_SERVER['HTTPS'] ?? '') !== '' ? 's' : '') . '://' . ($_SERVER['HTTP_HOST'] ?? 'locify.local') . '/verify?code=' . rawurlencode($row['verification_code']),
            'issuing_unit' => $row['issuing_unit'],
            'citizen' => [
                'first_name' => Crypto::decrypt((string)$row['first_name_enc']),
                'middle_name' => Crypto::decrypt((string)$row['middle_name_enc']),
                'last_name' => Crypto::decrypt((string)$row['last_name_enc']),
            ],
        ]);
    }
}