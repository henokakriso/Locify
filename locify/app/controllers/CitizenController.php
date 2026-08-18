<?php

declare(strict_types=1);

/** Citizen management endpoints. */

final class CitizenController extends Controller
{
    public function create(Request $request): void
    {
        $this->requirePermission($request, 'CITIZEN:CREATE');
        Validator::requireFields($request, ['first_name', 'last_name']);
        $errors = Validator::validate($request->body, [
            'dob_eth' => 'date', 'dob_greg' => 'date',
            'sex' => 'in:M,F,O',
        ]);
        if ($errors !== []) {
            Response::validationError($errors);
        }
        Response::json(CitizenService::create($request, $request->body), 201);
    }

    public function show(Request $request): void
    {
        $this->requirePermission($request, 'CITIZEN:VIEW');
        $citizen = CitizenService::findByUuid($request->routeParams['uuid']);
        if ($citizen === null) {
            Response::notFound('Citizen not found');
        }
        $unit = Database::fetchOne(
            'SELECT a.admin_unit_id FROM citizen_address a WHERE a.citizen_id = ? AND a.is_primary = 1',
            [$citizen['id']]
        );
        Auth::assertInScope($request, $unit['admin_unit_id'] ?? Auth::$context['scope_unit']);

        $addresses = Database::fetchAll(
            'SELECT ca.is_primary, ca.gps_lat, ca.gps_long, au.name AS admin_unit_name, ca.admin_unit_id
             FROM citizen_address ca JOIN admin_unit au ON au.id = ca.admin_unit_id
             WHERE ca.citizen_id = ?',
            [$citizen['id']]
        );

        Audit::log($request, 'VIEW_CITIZEN', 'citizen', $citizen['id']);
        Response::success([
            ...CitizenService::present($citizen),
            'addresses' => array_map(fn($a) => [
                'admin_unit_id' => $a['admin_unit_id'],
                'admin_unit_name' => $a['admin_unit_name'],
                'gps_lat' => $a['gps_lat'],
                'gps_long' => $a['gps_long'],
                'is_primary' => (bool)$a['is_primary'],
            ], $addresses),
        ]);
    }

    public function search(Request $request): void
    {
        $this->requirePermission($request, 'CITIZEN:SEARCH');
        $results = CitizenService::search($request, $request->query);
        Audit::log($request, 'SEARCH_CITIZEN', 'citizen', null, null, ['count' => count($results)]);
        Response::success(['results' => $results, 'count' => count($results)]);
    }

    /** Bulk CSV import — rows are validated individually; bad rows never abort the batch. */
    public function import(Request $request): void
    {
        $this->requirePermission($request, 'CITIZEN:CREATE');
        $limiter = new RateLimiter(Database::pdo());
        if (!$limiter->allow('import:' . $request->ip, 5, $request->ip)) {
            Response::error('RATE_LIMITED', 'Import limit reached — try again in a few minutes', 429);
        }
        $csv = (string)($request->input('csv') ?? '');
        if ($csv === '') {
            Response::validationError(['csv' => 'CSV content is required']);
        }
        if (strlen($csv) > 2 * 1024 * 1024) {
            Response::validationError(['csv' => 'CSV is too large (max 2 MB)']);
        }
        $rows = self::parseCsv($csv);
        if ($rows === []) {
            Response::validationError(['csv' => 'CSV must include a header row: ' . implode(',', self::CSV_COLUMNS)]);
        }
        $unknown = array_diff(array_keys($rows[0]), self::CSV_COLUMNS);
        if ($unknown !== []) {
            Response::validationError(['csv' => 'Unknown column(s): ' . implode(', ', $unknown)]);
        }
        $defaultUnitId = (string)($request->input('admin_unit_id') ?? Auth::$context['scope_unit']);
        $unit = Database::fetchOne('SELECT id, status FROM admin_unit WHERE id = ?', [$defaultUnitId]);
        if ($unit === null || $unit['status'] !== 'active') {
            Response::validationError(['admin_unit_id' => 'Invalid administrative unit']);
        }
        Auth::assertInScope($request, $defaultUnitId);

        $result = CitizenService::bulkCreate($request, $rows, $defaultUnitId);
        Response::json($result, $result['errors'] === [] ? 201 : 207);
    }

    /** CSV export of citizens within the actor's administrative scope. */
    public function export(Request $request): void
    {
        $this->requirePermission($request, 'CITIZEN:SEARCH');
        $scope = Auth::$context['scope_subtree'];
        $rows = Database::fetchAll(
            'SELECT c.uuid, c.national_id_mask, c.first_name_enc, c.middle_name_enc, c.last_name_enc,
                    c.local_name_enc, c.dob_eth, c.dob_greg, c.sex, c.status, c.created_at,
                    au.code AS admin_unit_code, au.name AS admin_unit_name
             FROM citizen c
             JOIN citizen_address ca ON ca.citizen_id = c.id AND ca.is_primary = 1
             JOIN admin_unit au ON au.id = ca.admin_unit_id
             WHERE ca.admin_unit_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')
             ORDER BY c.created_at DESC',
            $scope
        );

        $out = fopen('php://output', 'w');
        if ($out === false) {
            Response::error('INTERNAL_ERROR', 'Cannot stream export', 500);
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="locify-citizens-' . date('Ymd-His') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
        fputcsv($out, self::CSV_COLUMNS);
        $count = 0;
        foreach ($rows as $r) {
            $name = function ($enc) { return self::csvSafe((string)(Crypto::decrypt($enc ?? null) ?? '')); };
            fputcsv($out, [
                $r['uuid'],
                $r['national_id_mask'] ?? '',
                $name($r['first_name_enc']),
                $name($r['middle_name_enc']),
                $name($r['last_name_enc']),
                $name($r['local_name_enc']),
                $r['dob_eth'] ?? '',
                $r['dob_greg'] ?? '',
                $r['sex'] ?? '',
                $r['status'],
                $r['admin_unit_code'] ?? '',
                $r['admin_unit_name'] ?? '',
                $r['created_at'],
            ]);
            $count++;
        }
        fclose($out);
        Audit::log($request, 'EXPORT_CITIZENS', 'citizen', null, null, ['rows' => $count]);
        exit;
    }

    private const CSV_COLUMNS = [
        'national_id', 'first_name', 'middle_name', 'last_name', 'local_name',
        'dob_eth', 'sex', 'phone', 'email', 'village', 'house_no', 'admin_unit_code',
    ];

    /** @return list<array<string,string>> rows keyed by header */
    private static function parseCsv(string $csv): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];
        $header = null;
        while (($line = fgetcsv($stream, 0, ',')) !== false) {
            if ($header === null) {
                $header = array_map(fn($c) => trim((string)$c), $line);
                continue;
            }
            $row = [];
            foreach ($header as $i => $col) {
                if ($col === '') {
                    continue;
                }
                $row[$col] = ($line[$i] ?? '');
            }
            $rows[] = $row;
        }
        fclose($stream);
        return $rows;
    }

    /** Neutralize CSV formula injection (=, +, -, @ prefixes). */
    private static function csvSafe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    public function verify(Request $request): void
    {
        $this->requirePermission($request, 'CITIZEN:VERIFY_INITIATE');
        Response::json(CitizenService::initiateVerification($request, $request->routeParams['uuid']), 202);
    }

    public function approveVerification(Request $request): void
    {
        $this->requirePermission($request, 'CITIZEN:VERIFY_APPROVE');
        Response::json(CitizenService::approveVerification($request, $request->routeParams['uuid']));
    }

    public function update(Request $request): void
    {
        $this->requirePermission($request, 'CITIZEN:EDIT');
        Response::json(CitizenService::update($request, $request->routeParams['uuid'], $request->body));
    }

    public function relationships(Request $request): void
    {
        $this->requirePermission($request, 'CITIZEN:VIEW_FAMILY');
        $citizen = CitizenService::findByUuid($request->routeParams['uuid']);
        if ($citizen === null) {
            Response::notFound('Citizen not found');
        }
        $unit = Database::fetchOne(
            'SELECT a.admin_unit_id FROM citizen_address a WHERE a.citizen_id = ? AND a.is_primary = 1',
            [$citizen['id']]
        );
        Auth::assertInScope($request, $unit['admin_unit_id'] ?? Auth::$context['scope_unit']);

        $rows = Database::fetchAll(
            'SELECT r.id AS rel_id, r.relation_type, r.verified, r.created_at, r.citizen_id AS subject_id, r.related_citizen_id AS object_id
             FROM citizen_relationship r
             WHERE r.citizen_id = ? OR r.related_citizen_id = ?',
            [$citizen['id'], $citizen['id']]
        );
        $names = $this->familyNameMap($rows, $citizen['id']);
        $relationships = [];
        foreach ($rows as $r) {
            if ($r['subject_id'] === $citizen['id']) {
                $otherId = $r['object_id'];
                $type = $r['relation_type'];
            } else {
                $otherId = $r['subject_id'];
                $type = $this->invertRelation((string)$r['relation_type']);
            }
            $relationships[] = [
                'id' => $r['rel_id'],
                'relation_type' => $type,
                'verified' => (bool)$r['verified'],
                'created_at' => $r['created_at'],
                'related_citizen' => $names[$otherId] ?? null,
            ];
        }
        Audit::log($request, 'VIEW_CITIZEN_FAMILY', 'citizen', $citizen['id']);
        Response::success(['citizen_uuid' => $citizen['uuid'], 'relationships' => $relationships]);
    }

    public function linkRelationship(Request $request): void
    {
        $this->requirePermission($request, 'CITIZEN:EDIT');
        Validator::requireFields($request, ['related_citizen_uuid', 'relation_type']);
        $citizen = CitizenService::findByUuid($request->routeParams['uuid']);
        if ($citizen === null) {
            Response::notFound('Citizen not found');
        }
        $related = CitizenService::findByUuid((string)$request->body['related_citizen_uuid']);
        if ($related === null) {
            Response::notFound('Related citizen not found');
        }
        if ($related['id'] === $citizen['id']) {
            Response::validationError(['related_citizen_uuid' => 'Cannot link a citizen to themselves']);
        }
        $unit = Database::fetchOne(
            'SELECT a.admin_unit_id FROM citizen_address a WHERE a.citizen_id = ? AND a.is_primary = 1',
            [$citizen['id']]
        );
        Auth::assertInScope($request, $unit['admin_unit_id'] ?? Auth::$context['scope_unit']);
        $relationType = (string)$request->body['relation_type'];
        $allowed = ['spouse', 'parent', 'child', 'sibling', 'household_head', 'guardian', 'other'];
        if (!in_array($relationType, $allowed, true)) {
            Response::validationError(['relation_type' => 'Must be one of ' . implode(', ', $allowed)]);
        }
        $exists = Database::fetchOne(
            'SELECT id FROM citizen_relationship
             WHERE (citizen_id = ? AND related_citizen_id = ?) OR (citizen_id = ? AND related_citizen_id = ?)',
            [$citizen['id'], $related['id'], $related['id'], $citizen['id']]
        );
        if ($exists !== null) {
            Response::error('RELATIONSHIP_EXISTS', 'Relationship already recorded', 409);
        }
        $relId = uuid4();
        Database::run(
            'INSERT INTO citizen_relationship (id, citizen_id, related_citizen_id, relation_type, verified, created_by)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $relId,
                $citizen['id'],
                $related['id'],
                $relationType,
                !empty($request->body['verified']) ? 1 : 0,
                Auth::$context['user_id'] ?? null,
            ]
        );
        Audit::log($request, 'LINK_CITIZEN', 'citizen', $citizen['id'], null, ['related' => $related['id'], 'relation' => $relationType]);
        Response::success([
            'id' => $relId,
            'citizen_uuid' => $citizen['uuid'],
            'related_citizen_uuid' => $related['uuid'],
            'relation_type' => $relationType,
            'verified' => !empty($request->body['verified']),
        ], 201);
    }

    public function deleteRelationship(Request $request): void
    {
        $this->requirePermission($request, 'CITIZEN:EDIT');
        $citizen = CitizenService::findByUuid($request->routeParams['uuid']);
        if ($citizen === null) {
            Response::notFound('Citizen not found');
        }
        $unit = Database::fetchOne(
            'SELECT a.admin_unit_id FROM citizen_address a WHERE a.citizen_id = ? AND a.is_primary = 1',
            [$citizen['id']]
        );
        Auth::assertInScope($request, $unit['admin_unit_id'] ?? Auth::$context['scope_unit']);
        $rel = Database::fetchOne(
            'SELECT id, citizen_id, related_citizen_id, relation_type FROM citizen_relationship WHERE id = ?',
            [$request->routeParams['relUuid']]
        );
        if ($rel === null || ($rel['citizen_id'] !== $citizen['id'] && $rel['related_citizen_id'] !== $citizen['id'])) {
            Response::notFound('Relationship not found');
        }
        Database::run('DELETE FROM citizen_relationship WHERE id = ?', [$rel['id']]);
        Audit::log($request, 'UNLINK_CITIZEN', 'citizen', $citizen['id'], null, ['related' => $rel['related_citizen_id']]);
        Response::success(['status' => 'unlinked']);
    }

    private function familyNameMap(array $rows, string $subjectId): array
    {
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = $r['subject_id'] === $subjectId ? $r['object_id'] : $r['subject_id'];
        }
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $map = [];
        foreach (Database::fetchAll(
            "SELECT id, uuid, first_name_enc, middle_name_enc, last_name_enc FROM citizen WHERE id IN ($placeholders)",
            $ids
        ) as $c) {
            $map[$c['id']] = [
                'uuid' => $c['uuid'],
                'name' => trim(implode(' ', array_filter([
                    Crypto::decrypt($c['first_name_enc'] ?? null),
                    Crypto::decrypt($c['middle_name_enc'] ?? null),
                    Crypto::decrypt($c['last_name_enc'] ?? null),
                ]))),
            ];
        }
        return $map;
    }

    private function invertRelation(string $type): string
    {
        return match ($type) {
            'parent' => 'child',
            'child' => 'parent',
            'household_head' => 'household_member',
            'guardian' => 'ward',
            default => $type,
        };
    }
}
