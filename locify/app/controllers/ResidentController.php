<?php

declare(strict_types=1);

/**
 * Resident management: resident registration, households, residence timeline,
 * move-in/move-out/transfer records and per-resident document verification view.
 * Correctness is enforced by the same RBAC scope rules as everywhere else.
 */

final class ResidentController extends Controller
{
    private const OPEN_MOVE_TYPES = ['move_in', 'move_out', 'transfer'];
    private const MEMBER_ROLES = ['head', 'spouse', 'child', 'parent', 'sibling', 'other'];

    // ---------------------------------------------------------------- register

    /** Register a resident: citizen record + residence + move-in history. */
    public function register(Request $request): void
    {
        $this->requirePermission($request, 'RESIDENT:REGISTER');
        $this->requirePermission($request, 'CITIZEN:CREATE');
        Validator::requireFields($request, ['first_name', 'last_name']);
        if (!empty($request->input('dob_eth'))) {
            $errors = Validator::validate($request->body, ['dob_eth' => 'date', 'dob_greg' => 'date', 'sex' => 'in:M,F,O']);
            if ($errors !== []) {
                Response::validationError($errors);
            }
        }
        $adminUnitId = $request->input('address.admin_unit_id') ?? Auth::$context['scope_unit'];
        if ($adminUnitId === null || !Database::fetchOne('SELECT id FROM admin_unit WHERE id = ? AND status = ?', [$adminUnitId, 'active'])) {
            Response::validationError(['admin_unit_id' => 'Invalid administrative unit']);
        }
        Auth::assertInScope($request, $adminUnitId);

        $movedOn = $this->dateOrToday($request->input('moved_on'));
        $data = $request->body;
        $data['address'] = array_merge(
            ['admin_unit_id' => $adminUnitId],
            is_array($request->body['address'] ?? null) ? $request->body['address'] : []
        );
        $citizen = CitizenService::create($request, $data);

        $citizenId = Database::fetchOne('SELECT id FROM citizen WHERE uuid = ?', [$citizen['uuid']])['id'];
        $householdId = null;
        if (!empty($request->input('household_no'))) {
            $householdId = $this->attachHousehold($request, $citizenId, (string)$request->input('household_no'));
        }

        Database::transaction(function () use ($citizenId, $adminUnitId, $movedOn, $request) {
            $this->createResidenceRecord(
                $citizenId,
                $adminUnitId,
                $movedOn,
                $request,
                is_array($request->body['address'] ?? null) ? $request->body['address'] : []
            );
            $this->insertMoveRecord($citizenId, 'move_in', null, $adminUnitId, $request, $movedOn);
            Database::run(
                'UPDATE citizen_address SET admin_unit_id = ?, is_primary = 1
                 WHERE citizen_id = ? AND is_primary = 1',
                [$adminUnitId, $citizenId]
            );
        });

        Audit::log($request, 'REGISTER_RESIDENT', 'citizen', $citizenId, null, [
            'admin_unit_id' => $adminUnitId, 'household_id' => $householdId, 'moved_on' => $movedOn,
        ]);
        Response::success([
            'uuid' => $citizen['uuid'],
            'status' => $citizen['status'],
            'household_id' => $householdId,
            'moved_on' => $movedOn,
        ], 201);
    }

    // ---------------------------------------------------------------- profile

    /** Resident profile: citizen, current residence, households, verification hints. */
    public function profile(Request $request): void
    {
        $this->requirePermission($request, 'RESIDENT:VIEW');
        $citizen = $this->citizenScopeOrFail($request, $request->routeParams['uuid']);

        $residence = Database::fetchOne(
            'SELECT rr.*, au.name AS admin_unit_name, au.code AS admin_unit_code
             FROM residence_record rr JOIN admin_unit au ON au.id = rr.admin_unit_id
             WHERE rr.citizen_id = ? AND rr.is_current = 1 ORDER BY rr.started_at DESC LIMIT 1',
            [$citizen['id']]
        );

        $households = Database::fetchAll(
            'SELECT h.id, h.household_no, h.admin_unit_id, h.status, hm.member_role, hm.joined_at,
                    au.name AS admin_unit_name,
                    (SELECT COUNT(*) FROM household_member hm2 WHERE hm2.household_id = h.id AND hm2.left_at IS NULL) AS member_count
             FROM household_member hm
             JOIN household h ON h.id = hm.household_id
             JOIN admin_unit au ON au.id = h.admin_unit_id
             WHERE hm.citizen_id = ? AND hm.left_at IS NULL AND h.status = ?',
            [$citizen['id'], 'active']
        );
        foreach ($households as $i => $hh) {
            $households[$i]['name'] = Crypto::decrypt($hh['name_enc'] ?? null);
        }

        $moves = Database::fetchAll(
            'SELECT id, move_type, from_admin_unit_id, to_admin_unit_id, reason, moved_on, status
             FROM resident_move WHERE citizen_id = ? ORDER BY moved_on DESC LIMIT 20',
            [$citizen['id']]
        );

        $verificationStatus = Database::fetchOne(
            'SELECT status FROM identity_verification WHERE citizen_id = ? ORDER BY created_at DESC LIMIT 1',
            [$citizen['id']]
        );

        Audit::log($request, 'VIEW_RESIDENT', 'citizen', $citizen['id']);
        Response::success([
            ...CitizenService::present($citizen),
            'admin_unit_id' => $residence['admin_unit_id'] ?? null,
            'admin_unit_name' => $residence['admin_unit_name'] ?? null,
            'residence' => $residence !== null ? [
                'residence_type' => $residence['residence_type'],
                'village' => Crypto::decrypt($residence['village_enc'] ?? null),
                'house_no' => Crypto::decrypt($residence['house_no_enc'] ?? null),
                'started_at' => $residence['started_at'],
                'admin_unit_code' => $residence['admin_unit_code'],
            ] : null,
            'households' => $households,
            'moves_count' => count($moves),
            'identity_verification' => $verificationStatus['status'] ?? 'none',
        ]);
    }

    // ---------------------------------------------------------------- history

    /** Full resident history: residences, moves, verifications, documents. */
    public function history(Request $request): void
    {
        $this->requirePermission($request, 'RESIDENT:VIEW');
        $citizen = $this->citizenScopeOrFail($request, $request->routeParams['uuid']);

        $timeline = [];

        foreach (Database::fetchAll(
            'SELECT rr.id, rr.residence_type, rr.started_at, rr.ended_at, rr.is_current, rr.created_at,
                    au.name AS admin_unit_name, au.code AS admin_unit_code, rr.village_enc, rr.house_no_enc
             FROM residence_record rr JOIN admin_unit au ON au.id = rr.admin_unit_id
             WHERE rr.citizen_id = ? ORDER BY rr.started_at',
            [$citizen['id']]
        ) as $r) {
            $timeline[] = [
                'type' => 'residence',
                'date' => $r['started_at'],
                'created_at' => $r['created_at'],
                'data' => [
                    'residence_type' => $r['residence_type'],
                    'village' => Crypto::decrypt($r['village_enc'] ?? null),
                    'house_no' => Crypto::decrypt($r['house_no_enc'] ?? null),
                    'started_at' => $r['started_at'],
                    'ended_at' => $r['ended_at'],
                    'is_current' => (bool)$r['is_current'],
                    'admin_unit' => $r['admin_unit_name'],
                    'admin_unit_code' => $r['admin_unit_code'],
                ],
            ];
        }

        foreach (Database::fetchAll(
            'SELECT rm.id, rm.move_type, rm.moved_on, rm.reason, rm.note, rm.status, rm.created_at,
                    fu.name AS from_unit, tu.name AS to_unit
             FROM resident_move rm
             LEFT JOIN admin_unit fu ON fu.id = rm.from_admin_unit_id
             LEFT JOIN admin_unit tu ON tu.id = rm.to_admin_unit_id
             WHERE rm.citizen_id = ? ORDER BY rm.moved_on',
            [$citizen['id']]
        ) as $m) {
            $timeline[] = [
                'type' => 'move',
                'date' => $m['moved_on'],
                'created_at' => $m['created_at'],
                'data' => [
                    'move_type' => $m['move_type'],
                    'from' => $m['from_unit'],
                    'to' => $m['to_unit'],
                    'reason' => $m['reason'],
                    'note' => $m['note'],
                    'status' => $m['status'],
                ],
            ];
        }

        foreach (Database::fetchAll(
            'SELECT iv.verification_type, iv.status, iv.verified_at, iv.created_at
             FROM identity_verification iv WHERE iv.citizen_id = ? ORDER BY iv.created_at',
            [$citizen['id']]
        ) as $v) {
            $timeline[] = [
                'type' => 'verification',
                'date' => substr((string)($v['verified_at'] ?? $v['created_at']), 0, 10),
                'created_at' => $v['created_at'],
                'data' => ['verification_type' => $v['verification_type'], 'status' => $v['status'], 'verified_at' => $v['verified_at']],
            ];
        }

        foreach (Database::fetchAll(
            'SELECT d.document_number, d.document_type, d.title, d.status, d.created_at
             FROM document d WHERE d.citizen_id = ? ORDER BY d.created_at',
            [$citizen['id']]
        ) as $d) {
            $timeline[] = [
                'type' => 'document',
                'date' => substr((string)$d['created_at'], 0, 10),
                'created_at' => $d['created_at'],
                'data' => $d,
            ];
        }

        usort($timeline, fn($a, $b) => strcmp((string)$b['date'], (string)$a['date']));
        Audit::log($request, 'VIEW_RESIDENT_HISTORY', 'citizen', $citizen['id']);
        Response::success(['uuid' => $citizen['uuid'], 'timeline' => $timeline]);
    }

    // ---------------------------------------------------------------- move-in/out

    /** Record a move-in, move-out or transfer, maintaining the residence timeline. */
    public function move(Request $request): void
    {
        $this->requirePermission($request, 'MOVE:RECORD');
        Validator::requireFields($request, ['move_type']);
        $moveType = (string)$request->input('move_type');
        if (!in_array($moveType, self::OPEN_MOVE_TYPES, true)) {
            Response::validationError(['move_type' => 'Must be one of ' . implode(', ', self::OPEN_MOVE_TYPES)]);
        }
        $citizen = $this->citizenScopeOrFail($request, $request->routeParams['uuid']);
        $movedOn = $this->dateOrToday($request->input('moved_on'));

        $current = Database::fetchOne(
            'SELECT * FROM residence_record WHERE citizen_id = ? AND is_current = 1 ORDER BY started_at DESC LIMIT 1',
            [$citizen['id']]
        );
        $fromUnit = $current['admin_unit_id'] ?? null;

        if ($moveType === 'move_in') {
            $toUnit = (string)($request->input('to_admin_unit_id') ?? Auth::$context['scope_unit']);
        } elseif ($moveType === 'transfer') {
            $toUnit = (string)$request->input('to_admin_unit_id');
        } else {
            $toUnit = null;
        }
        if ($toUnit !== null) {
            $unit = Database::fetchOne('SELECT id FROM admin_unit WHERE id = ? AND status = ?', [$toUnit, 'active']);
            if ($unit === null) {
                Response::validationError(['to_admin_unit_id' => 'Invalid administrative unit']);
            }
            Auth::assertInScope($request, $toUnit);
        }
        if ($moveType === 'move_out' && $current === null) {
            Response::error('NO_CURRENT_RESIDENCE', 'No current residence to end. Record a move-in first.', 409);
        }

        Database::transaction(function () use ($citizen, $moveType, $movedOn, $fromUnit, $toUnit, $current, $request) {
            if ($current !== null && $moveType !== 'move_in') {
                Database::run(
                    'UPDATE residence_record SET is_current = 0, ended_at = ? WHERE id = ?',
                    [date('Y-m-d', strtotime($movedOn . ' -1 day')), $current['id']]
                );
            }
            if ($toUnit !== null) {
                $this->createResidenceRecord($citizen['id'], $toUnit, $movedOn, $request);
                Database::run(
                    'UPDATE citizen_address SET admin_unit_id = ? WHERE citizen_id = ? AND is_primary = 1',
                    [$toUnit, $citizen['id']]
                );
            }
            $this->insertMoveRecord($citizen['id'], $moveType, $fromUnit, $toUnit, $request, $movedOn);
        });

        Audit::log($request, 'MOVE_RECORD', 'citizen', $citizen['id'], null, [
            'move_type' => $moveType, 'from' => $fromUnit, 'to' => $toUnit, 'moved_on' => $movedOn,
        ]);
        Response::success(['uuid' => $citizen['uuid'], 'move_type' => $moveType, 'status' => 'recorded'], 201);
    }

    // ---------------------------------------------------------------- verifications

    /** Documents + identity verification history for a resident. */
    public function verifications(Request $request): void
    {
        $this->requirePermission($request, 'RESIDENT:VIEW');
        $citizen = $this->citizenScopeOrFail($request, $request->routeParams['uuid']);

        $documents = Database::fetchAll(
            'SELECT uuid, document_number, document_type, title, status, verification_code, created_at
             FROM document WHERE citizen_id = ? ORDER BY created_at DESC',
            [$citizen['id']]
        );
        $verifications = Database::fetchAll(
            'SELECT verification_type, status, verified_at, external_ref, notes
             FROM identity_verification WHERE citizen_id = ? ORDER BY created_at DESC',
            [$citizen['id']]
        );
        Audit::log($request, 'VIEW_RESIDENT_VERIFICATIONS', 'citizen', $citizen['id']);
        Response::success(['documents' => $documents, 'identity_verifications' => $verifications]);
    }

    // ---------------------------------------------------------------- households

    /** List households in scope (optional admin_unit_id filter, q on household_no). */
    public function households(Request $request): void
    {
        $this->requirePermission($request, 'HOUSEHOLD:VIEW');
        $scope = Auth::$context['scope_subtree'];
        $sql = 'SELECT h.id, h.household_no, h.status, h.name_enc, h.village_enc, h.house_no_enc,
                       h.created_at, au.name AS admin_unit_name, h.admin_unit_id,
                       (SELECT COUNT(*) FROM household_member hm WHERE hm.household_id = h.id AND hm.left_at IS NULL) AS member_count
                FROM household h JOIN admin_unit au ON au.id = h.admin_unit_id
                WHERE h.admin_unit_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
        $params = $scope;
        if (!empty($request->query['admin_unit_id'])) {
            $sql .= ' AND h.admin_unit_id = ?';
            $params[] = $request->query['admin_unit_id'];
        }
        if (!empty($request->query['q'])) {
            $sql .= ' AND h.household_no LIKE ?';
            $params[] = '%' . (string)$request->query['q'] . '%';
        }
        $sql .= ' ORDER BY h.created_at DESC LIMIT 200';
        $rows = Database::fetchAll($sql, $params);
        foreach ($rows as $i => $r) {
            $rows[$i]['name'] = Crypto::decrypt($r['name_enc'] ?? null);
            $rows[$i]['village'] = Crypto::decrypt($r['village_enc'] ?? null);
            $rows[$i]['house_no'] = Crypto::decrypt($r['house_no_enc'] ?? null);
        }
        Audit::log($request, 'VIEW_HOUSEHOLDS', 'household', null, null, ['count' => count($rows), 'admin_unit_id' => $request->query['admin_unit_id'] ?? null]);
        Response::success(['households' => $rows, 'count' => count($rows)]);
    }

    /** Household detail with member list. */
    public function householdShow(Request $request): void
    {
        $this->requirePermission($request, 'HOUSEHOLD:VIEW');
        $household = $this->householdScopeOrFail($request, $request->routeParams['uuid']);
        $members = Database::fetchAll(
            'SELECT hm.id AS membership_id, hm.member_role, hm.joined_at, hm.left_at,
                    c.uuid AS citizen_uuid, c.first_name_enc, c.middle_name_enc, c.last_name_enc, c.status
             FROM household_member hm JOIN citizen c ON c.id = hm.citizen_id
             WHERE hm.household_id = ? ORDER BY (hm.member_role = ?) DESC, hm.joined_at',
            [$household['id'], 'head']
        );
        foreach ($members as $i => $m) {
            $members[$i]['name'] = trim(implode(' ', array_filter([
                Crypto::decrypt($m['first_name_enc'] ?? null),
                Crypto::decrypt($m['middle_name_enc'] ?? null),
                Crypto::decrypt($m['last_name_enc'] ?? null),
            ])));
            unset($members[$i]['first_name_enc'], $members[$i]['middle_name_enc'], $members[$i]['last_name_enc']);
        }
        $head = Database::fetchOne(
            'SELECT c.uuid FROM citizen c WHERE c.id = ?', [$household['head_citizen_id']]
        );
        Audit::log($request, 'VIEW_HOUSEHOLD', 'household', $household['id']);
        Response::success([
            'household' => [
                'id' => $household['id'],
                'household_no' => $household['household_no'],
                'status' => $household['status'],
                'name' => Crypto::decrypt($household['name_enc'] ?? null),
                'village' => Crypto::decrypt($household['village_enc'] ?? null),
                'house_no' => Crypto::decrypt($household['house_no_enc'] ?? null),
                'admin_unit_name' => Database::fetchOne('SELECT name FROM admin_unit WHERE id = ?', [$household['admin_unit_id']])['name'] ?? null,
                'head_citizen_uuid' => $head['uuid'] ?? null,
                'members' => $members,
            ],
        ]);
    }

    /** Create a household with a head of household. */
    public function householdCreate(Request $request): void
    {
        $this->requirePermission($request, 'HOUSEHOLD:MANAGE');
        Validator::requireFields($request, ['admin_unit_id', 'head_citizen_uuid']);
        $unit = Database::fetchOne('SELECT id, code FROM admin_unit WHERE id = ? AND status = ?', [$request->input('admin_unit_id'), 'active']);
        if ($unit === null) {
            Response::validationError(['admin_unit_id' => 'Invalid administrative unit']);
        }
        Auth::assertInScope($request, $unit['id']);
        $head = Database::fetchOne('SELECT id, status FROM citizen WHERE uuid = ?', [$request->input('head_citizen_uuid')]);
        if ($head === null || $head['status'] !== 'active') {
            Response::validationError(['head_citizen_uuid' => 'Head of household must be an active citizen']);
        }
        $this->assertCitizenInScope($request, $head['id']);

        $householdNo = nextHouseholdNumber(Database::pdo(), (string)$unit['code']);
        $householdId = uuid4();
        Database::transaction(function () use ($householdId, $householdNo, $unit, $head, $request) {
            Database::run(
                'INSERT INTO household (id, household_no, admin_unit_id, name_enc, village_enc, house_no_enc, head_citizen_id, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $householdId, $householdNo, $unit['id'],
                    Crypto::encrypt($request->input('name') ? (string)$request->input('name') : null),
                    Crypto::encrypt($request->input('village') ? (string)$request->input('village') : null),
                    Crypto::encrypt($request->input('house_no') ? (string)$request->input('house_no') : null),
                    $head['id'], Auth::$context['user_id'] ?? null,
                ]
            );
            Database::run(
                'INSERT INTO household_member (id, household_id, citizen_id, member_role, joined_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [uuid4(), $householdId, $head['id'], 'head', date('Y-m-d'), Auth::$context['user_id'] ?? null]
            );
        });
        Audit::log($request, 'CREATE_HOUSEHOLD', 'household', $householdId, null, ['head' => $head['id']]);
        Response::success(['id' => $householdId, 'household_no' => $householdNo, 'status' => 'active'], 201);
    }

    /** Add a member (or move them from another household) to a household. */
    public function householdAddMember(Request $request): void
    {
        $this->requirePermission($request, 'HOUSEHOLD:MANAGE');
        Validator::requireFields($request, ['citizen_uuid', 'member_role']);
        $household = $this->householdScopeOrFail($request, $request->routeParams['uuid']);
        $role = (string)$request->input('member_role');
        if (!in_array($role, self::MEMBER_ROLES, true)) {
            Response::validationError(['member_role' => 'Must be one of ' . implode(', ', self::MEMBER_ROLES)]);
        }
        $member = Database::fetchOne('SELECT id, status FROM citizen WHERE uuid = ?', [$request->input('citizen_uuid')]);
        if ($member === null || $member['status'] !== 'active') {
            Response::validationError(['citizen_uuid' => 'Invalid citizen']);
        }
        $this->assertCitizenInScope($request, $member['id']);

        $existing = Database::fetchOne(
            'SELECT hm.id, hm.household_id, hm.left_at FROM household_member hm WHERE hm.citizen_id = ? AND hm.left_at IS NULL',
            [$member['id']]
        );
        if ($existing !== null && $existing['household_id'] === $household['id']) {
            Response::error('ALREADY_MEMBER', 'This citizen is already a member of the household', 409);
        }
        if ($role === 'head' && $existing === null && $household['head_citizen_id'] !== null) {
            Response::error('HEAD_EXISTS', 'This household already has a head. Use another role or transfer headship.', 409);
        }
        $membershipId = uuid4();
        Database::transaction(function () use ($membershipId, $household, $member, $role, $existing, $request) {
            if ($existing !== null) {
                Database::run('UPDATE household_member SET left_at = ? WHERE id = ?', [date('Y-m-d'), $existing['id']]);
            }
            Database::run(
                'INSERT INTO household_member (id, household_id, citizen_id, member_role, joined_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$membershipId, $household['id'], $member['id'], $role, date('Y-m-d'), Auth::$context['user_id'] ?? null]
            );
            if ($role === 'head') {
                Database::run('UPDATE household SET head_citizen_id = ?, updated_by = ? WHERE id = ?',
                    [$member['id'], Auth::$context['user_id'] ?? null, $household['id']]);
            }
        });
        Audit::log($request, 'ADD_HOUSEHOLD_MEMBER', 'household', $household['id'], null, ['member' => $member['id'], 'role' => $role]);
        Response::success(['membership_id' => $membershipId, 'role' => $role], 201);
    }

    /** Remove a member (sets left_at; history is preserved). */
    public function householdRemoveMember(Request $request): void
    {
        $this->requirePermission($request, 'HOUSEHOLD:MANAGE');
        $household = $this->householdScopeOrFail($request, $request->routeParams['uuid']);
        $membership = Database::fetchOne(
            'SELECT id, citizen_id, member_role FROM household_member WHERE id = ? AND household_id = ?',
            [$request->routeParams['memberId'], $household['id']]
        );
        if ($membership === null) {
            Response::notFound('Membership not found');
        }
        if ($membership['member_role'] === 'head') {
            Response::error('HEAD_NOT_REMOVABLE', 'Transfer headship to another member before removing the head', 409);
        }
        Database::run('UPDATE household_member SET left_at = ? WHERE id = ?', [date('Y-m-d'), $membership['id']]);
        Audit::log($request, 'REMOVE_HOUSEHOLD_MEMBER', 'household', $household['id'], null, ['member' => $membership['citizen_id']]);
        Response::success(['status' => 'removed']);
    }

    // ---------------------------------------------------------------- helpers

    private function assertCitizenInScope(Request $request, string $citizenId): void
    {
        $unit = Database::fetchOne(
            'SELECT a.admin_unit_id FROM citizen_address a WHERE a.citizen_id = ? AND a.is_primary = 1',
            [$citizenId]
        );
        Auth::assertInScope($request, $unit['admin_unit_id'] ?? Auth::$context['scope_unit']);
    }

    private function citizenScopeOrFail(Request $request, string $uuid): array
    {
        $citizen = CitizenService::findByUuid($uuid);
        if ($citizen === null) {
            Response::notFound('Resident not found');
        }
        $this->assertCitizenInScope($request, $citizen['id']);
        return $citizen;
    }

    private function householdScopeOrFail(Request $request, string $uuid): array
    {
        $household = Database::fetchOne('SELECT * FROM household WHERE id = ?', [$uuid]);
        if ($household === null) {
            Response::notFound('Household not found');
        }
        Auth::assertInScope($request, $household['admin_unit_id']);
        return $household;
    }

    private function createResidenceRecord(string $citizenId, string $adminUnitId, string $startedAt, Request $request, array $address = []): void
    {
        $current = Database::fetchOne(
            'SELECT id FROM residence_record WHERE citizen_id = ? AND is_current = 1 LIMIT 1',
            [$citizenId]
        );
        Database::run(
            'INSERT INTO residence_record (id, citizen_id, admin_unit_id, residence_type, village_enc, house_no_enc, started_at, is_current, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)',
            [
                uuid4(),
                $citizenId,
                $adminUnitId,
                $current === null ? 'primary' : 'secondary',
                Crypto::encrypt(isset($address['village']) ? (string)$address['village'] : null),
                Crypto::encrypt(isset($address['house_no']) ? (string)$address['house_no'] : null),
                $startedAt,
                Auth::$context['user_id'] ?? null,
            ]
        );
    }

    private function insertMoveRecord(string $citizenId, string $moveType, ?string $from, ?string $to, Request $request, string $movedOn): void
    {
        Database::run(
            'INSERT INTO resident_move (id, citizen_id, move_type, from_admin_unit_id, to_admin_unit_id, reason, note, moved_on, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                uuid4(),
                $citizenId,
                $moveType,
                $from,
                $to,
                $request->input('reason') ? substr((string)$request->input('reason'), 0, 255) : null,
                $request->input('note') ? substr((string)$request->input('note'), 0, 500) : null,
                $movedOn,
                Auth::$context['user_id'] ?? null,
            ]
        );
    }

    /** Accept YYYY-MM-DD or default to today. */
    private function dateOrToday(mixed $value): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            [$y, $m, $d] = array_map('intval', explode('-', $value));
            if ($y >= 1900 && $m >= 1 && $m <= 12 && $d >= 1 && $d <= 31) {
                return $value;
            }
        }
        return date('Y-m-d');
    }

    /** Join an existing household by household number (must be active + in scope). */
    private function attachHousehold(Request $request, string $citizenId, string $householdNo): ?string
    {
        $household = Database::fetchOne(
            'SELECT id, admin_unit_id, head_citizen_id FROM household WHERE household_no = ? AND status = ?',
            [$householdNo, 'active']
        );
        if ($household === null) {
            Response::validationError(['household_no' => 'Unknown household number']);
        }
        Auth::assertInScope($request, $household['admin_unit_id']);
        $existing = Database::fetchOne(
            'SELECT id FROM household_member WHERE citizen_id = ? AND left_at IS NULL',
            [$citizenId]
        );
        if ($existing === null) {
            Database::run(
                'INSERT INTO household_member (id, household_id, citizen_id, member_role, joined_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [uuid4(), $household['id'], $citizenId, 'other', date('Y-m-d'), Auth::$context['user_id'] ?? null]
            );
        }
        return $household['id'];
    }
}