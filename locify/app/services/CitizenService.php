<?php

declare(strict_types=1);

/** Citizen lifecycle: creation with deduplication, encryption, verification. */

final class CitizenService
{
    public static function create(Request $request, array $data, bool $skipScope = false): array
    {
        $adminUnitId = $data['address']['admin_unit_id']
            ?? Auth::$context['scope_unit']
            ?? null;
        if ($adminUnitId === null || !Database::fetchOne('SELECT id FROM admin_unit WHERE id = ?', [$adminUnitId])) {
            Response::validationError(['admin_unit_id' => 'Invalid administrative unit']);
        }
        if (!$skipScope) {
            Auth::assertInScope($request, $adminUnitId);
        }

        $nationalIdHash = isset($data['national_id']) && $data['national_id'] !== ''
            ? hash('sha256', $data['national_id'])
            : null;
        $phoneHash = isset($data['phone']) && $data['phone'] !== '' ? hash('sha256', $data['phone']) : null;

        if ($nationalIdHash !== null && self::findDuplicate($nationalIdHash)) {
            SecurityEvent::log('duplicate_citizen_detected', 'low', $request, ['national_id_hash' => $nationalIdHash]);
            Audit::log($request, 'CREATE_CITIZEN', 'citizen', null, result: 'denied', reason: 'duplicate national ID');
            Response::error('DUPLICATE_CITIZEN', 'A citizen with this national ID already exists', 409);
        }

        $uuid = self::insert($request, $data, $adminUnitId, $nationalIdHash, $phoneHash);

        $citizenRow = Database::fetchOne('SELECT id FROM citizen WHERE uuid = ?', [$uuid]);
        Audit::log($request, 'CREATE_CITIZEN', 'citizen', $citizenRow['id'] ?? null, null,
            Audit::mask($data, ['national_id', 'phone', 'email']));

        return [
            'uuid' => $uuid,
            'status' => 'pending_verification',
            'created_at' => date('c'),
        ];
    }

    /** Batch import from CSV rows (see CitizenController::import). */
    public static function bulkCreate(Request $request, array $rows, string $defaultUnitId): array
    {
        $created = [];
        $errors = [];
        $count = 0;
        foreach ($rows as $i => $row) {
            $line = $i + 2; // line 1 is the header
            if ($count >= (int)Config::get('import.max_rows', 500)) {
                $errors[] = ['line' => $line, 'error' => 'Import limit reached (500 rows)'];
                break;
            }
            $data = [
                'national_id' => self::cleanStr($row['national_id'] ?? ''),
                'first_name' => self::cleanStr($row['first_name'] ?? ''),
                'middle_name' => self::cleanStr($row['middle_name'] ?? ''),
                'last_name' => self::cleanStr($row['last_name'] ?? ''),
                'local_name' => self::cleanStr($row['local_name'] ?? ''),
                'dob_eth' => self::cleanDate($row['dob_eth'] ?? ''),
                'sex' => strtoupper(self::cleanStr($row['sex'] ?? '')),
                'phone' => self::cleanStr($row['phone'] ?? ''),
                'email' => self::cleanStr($row['email'] ?? ''),
                'address' => [
                    'admin_unit_id' => $defaultUnitId,
                    'village' => self::cleanStr($row['village'] ?? ''),
                    'house_no' => self::cleanStr($row['house_no'] ?? ''),
                ],
            ];
            $unitCode = self::cleanStr($row['admin_unit_code'] ?? '');
            if ($unitCode !== '') {
                $unit = Database::fetchOne('SELECT id, code, status FROM admin_unit WHERE code = ? AND status = ?', [$unitCode, 'active']);
                if ($unit === null) {
                    $errors[] = ['line' => $line, 'error' => "Unknown admin_unit_code '$unitCode'"];
                    continue;
                }
                Auth::assertInScope($request, $unit['id']);
                $data['address']['admin_unit_id'] = $unit['id'];
            }
            if ($data['first_name'] === '' || $data['last_name'] === '') {
                $errors[] = ['line' => $line, 'error' => 'first_name and last_name are required'];
                continue;
            }
            if ($data['sex'] !== '' && !in_array($data['sex'], ['M', 'F', 'O'], true)) {
                $errors[] = ['line' => $line, 'error' => 'sex must be M, F or O'];
                continue;
            }
            if ($data['dob_eth'] !== '') {
                [$ey, $em, $ed] = array_map('intval', explode('-', $data['dob_eth']));
                if ($ey < 1900 || $em < 1 || $em > 13 || $ed < 1 || $ed > 30) {
                    $errors[] = ['line' => $line, 'error' => 'Invalid dob_eth (expected YYYY-MM-DD in Ethiopian calendar)'];
                    continue;
                }
            }

            $nationalIdHash = $data['national_id'] !== '' ? hash('sha256', $data['national_id']) : null;
            $phoneHash = $data['phone'] !== '' ? hash('sha256', $data['phone']) : null;
            if ($nationalIdHash !== null && self::findDuplicate($nationalIdHash)) {
                $errors[] = ['line' => $line, 'error' => 'A citizen with this national ID already exists'];
                continue;
            }

            try {
                $uuid = self::insert($request, $data, $data['address']['admin_unit_id'], $nationalIdHash, $phoneHash);
            } catch (Throwable $e) {
                error_log('[LOCIFY] import row failed: ' . $e->getMessage());
                $errors[] = ['line' => $line, 'error' => 'Database error, row skipped'];
                continue;
            }
            $created[] = ['uuid' => $uuid, 'line' => $line];
            $count++;
        }

        Audit::log($request, 'IMPORT_CITIZENS', 'citizen', null, null, [
            'rows' => count($rows),
            'created' => count($created),
            'errors' => count($errors),
        ]);
        return ['created' => $created, 'errors' => $errors, 'total' => count($rows)];
    }

    private static function cleanStr(string $value): string
    {
        return trim(mb_substr($value, 0, 255));
    }

    private static function cleanDate(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) ? trim($value) : '';
    }

    private static function findDuplicate(string $nationalIdHash): bool
    {
        return Database::fetchOne(
            'SELECT id FROM citizen WHERE national_id_hash = ? AND status != ?',
            [$nationalIdHash, 'archived']
        ) !== null;
    }

    private static function insert(Request $request, array $data, string $adminUnitId, ?string $nationalIdHash, ?string $phoneHash): string
    {
        $id = uuid4();
        $uuid = uuid4();
        $dobEth = $data['dob_eth'] ?? null;
        $dobGreg = $data['dob_greg'] ?? null;

        if ($dobEth !== null && $dobEth !== '' && ($dobGreg === null || $dobGreg === '')) {
            [$ey, $em, $ed] = array_map('intval', explode('-', (string)$dobEth));
            $dobGreg = Calendar::ethToGregDate($ey, $em, $ed);
        }
        if ($dobGreg === '') {
            $dobGreg = null;
        }

        Database::transaction(function () use ($id, $uuid, $nationalIdHash, $data, $dobEth, $dobGreg, $phoneHash, $adminUnitId) {
            Database::run(
                'INSERT INTO citizen (id, uuid, national_id_hash, national_id_mask,
                    first_name_enc, middle_name_enc, last_name_enc, local_name_enc,
                    dob_eth, dob_greg, sex, phone_hash, email_hash, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $id,
                    $uuid,
                    $nationalIdHash,
                    isset($data['national_id']) && $data['national_id'] !== '' ? maskMiddle((string)$data['national_id']) : null,
                    Crypto::encrypt($data['first_name'] ?? null),
                    Crypto::encrypt($data['middle_name'] ?? null),
                    Crypto::encrypt($data['last_name'] ?? null),
                    Crypto::encrypt($data['local_name'] ?? null),
                    $dobEth !== '' ? $dobEth : null,
                    $dobGreg,
                    $data['sex'] !== '' ? $data['sex'] : null,
                    $phoneHash,
                    isset($data['email']) && $data['email'] !== '' ? hash('sha256', $data['email']) : null,
                    'pending_verification',
                    Auth::$context['user_id'] ?? null,
                ]
            );

            if (isset($data['address']) && is_array($data['address'])) {
                Database::run(
                    'INSERT INTO citizen_address (id, citizen_id, admin_unit_id, village_enc, house_no_enc, gps_lat, gps_long, is_primary)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1)',
                    [
                        uuid4(),
                        $id,
                        $adminUnitId,
                        Crypto::encrypt($data['address']['village'] ?? null),
                        Crypto::encrypt($data['address']['house_no'] ?? null),
                        $data['address']['gps_lat'] ?? null,
                        $data['address']['gps_long'] ?? null,
                    ]
                );
            }

            Database::run(
                'INSERT INTO consent (id, citizen_id, scope, granted) VALUES (?, ?, ?, 1)',
                [uuid4(), $id, 'identity_verification']
            );
        });

        return $uuid;
    }

    /** Search citizens. Returns limited, decrypted fields. */
    public static function search(Request $request, array $filters): array
    {
        $sql = 'SELECT id, uuid, national_id_mask, first_name_enc, middle_name_enc, last_name_enc,
                       dob_eth, dob_greg, sex, status, created_at
                FROM citizen WHERE 1=1';
        $params = [];

        if (!empty($filters['national_id'])) {
            $sql .= ' AND national_id_hash = ?';
            $params[] = hash('sha256', (string)$filters['national_id']);
        }
        if (!empty($filters['phone'])) {
            $sql .= ' AND phone_hash = ?';
            $params[] = hash('sha256', (string)$filters['phone']);
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['name'])) {
            $sql .= ' AND (first_name_enc IS NOT NULL)'; // names are encrypted; exact-match by other identifiers
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 100';

        $rows = Database::fetchAll($sql, $params);
        return array_map([CitizenService::class, 'present'], $rows);
    }

    public static function present(array $row): array
    {
        return [
            'uuid' => $row['uuid'],
            'name' => implode(' ', array_filter([
                Crypto::decrypt($row['first_name_enc'] ?? null),
                Crypto::decrypt($row['middle_name_enc'] ?? null),
                Crypto::decrypt($row['last_name_enc'] ?? null),
            ])),
            'national_id_mask' => $row['national_id_mask'] ?? null,
            'dob_eth' => $row['dob_eth'],
            'dob_greg' => $row['dob_greg'],
            'sex' => $row['sex'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
        ];
    }

    public static function findByUuid(string $uuid): ?array
    {
        return Database::fetchOne('SELECT * FROM citizen WHERE uuid = ?', [$uuid]);
    }

    /** Initiate identity verification (creates a verification record; external
     *  national ID integration REQUIRES OFFICIAL INTEGRATION — simulated here). */
    public static function initiateVerification(Request $request, string $citizenUuid): array
    {
        $citizen = self::findByUuid($citizenUuid);
        if ($citizen === null) {
            Response::notFound('Citizen not found');
        }
        $recordId = uuid4();
        Database::run(
            'INSERT INTO identity_verification (id, citizen_id, verification_type, status, verified_by)
             VALUES (?, ?, ?, ?, ?)',
            [$recordId, $citizen['id'], 'national_id', 'pending', Auth::$context['user_id'] ?? null]
        );
        Audit::log($request, 'VERIFY_IDENTITY_INITIATE', 'citizen', $citizen['id']);
        return ['verification_id' => $recordId, 'status' => 'pending'];
    }

    /** Approve verification (Verification Officer). */
    public static function approveVerification(Request $request, string $citizenUuid): array
    {
        $citizen = self::findByUuid($citizenUuid);
        if ($citizen === null) {
            Response::notFound('Citizen not found');
        }
        Database::run(
            'UPDATE identity_verification SET status = ?, verified_at = NOW(), notes = ? WHERE citizen_id = ? AND status = ?',
            ['success', 'verified via authorized verification flow', $citizen['id'], 'pending']
        );
        Database::run(
            'UPDATE citizen SET status = ?, updated_by = ? WHERE id = ?',
            ['active', Auth::$context['user_id'] ?? null, $citizen['id']]
        );
        Audit::log($request, 'VERIFY_IDENTITY_APPROVE', 'citizen', $citizen['id']);
        return ['uuid' => $citizenUuid, 'status' => 'active'];
    }

    /** Update editable citizen fields (records officer). */
    public static function update(Request $request, string $citizenUuid, array $data): array
    {
        $citizen = self::findByUuid($citizenUuid);
        if ($citizen === null) {
            Response::notFound('Citizen not found');
        }
        $unit = Database::fetchOne(
            'SELECT a.admin_unit_id FROM citizen_address a WHERE a.citizen_id = ? AND a.is_primary = 1',
            [$citizen['id']]
        );
        Auth::assertInScope($request, $unit['admin_unit_id'] ?? Auth::$context['scope_unit']);

        $dobEth = $data['dob_eth'] ?? $citizen['dob_eth'];
        $dobGreg = $data['dob_greg'] ?? $citizen['dob_greg'];
        if (($data['dob_eth'] ?? null) !== null && ($data['dob_greg'] ?? null) === null) {
            [$ey, $em, $ed] = array_map('intval', explode('-', (string)$dobEth));
            $dobGreg = Calendar::ethToGregDate($ey, $em, $ed);
        }

        Database::run(
            'UPDATE citizen SET
                first_name_enc = ?, middle_name_enc = ?, last_name_enc = ?, local_name_enc = ?,
                dob_eth = ?, dob_greg = ?, sex = ?, phone_hash = ?, email_hash = ?, status = ?, updated_by = ?
             WHERE id = ?',
            [
                Crypto::encrypt($data['first_name'] ?? Crypto::decrypt($citizen['first_name_enc'] ?? '')),
                Crypto::encrypt($data['middle_name'] ?? Crypto::decrypt($citizen['middle_name_enc'] ?? '')),
                Crypto::encrypt($data['last_name'] ?? Crypto::decrypt($citizen['last_name_enc'] ?? '')),
                Crypto::encrypt($data['local_name'] ?? Crypto::decrypt($citizen['local_name_enc'] ?? '')),
                $dobEth,
                $dobGreg,
                $data['sex'] ?? $citizen['sex'],
                isset($data['phone']) && $data['phone'] !== '' ? hash('sha256', $data['phone']) : $citizen['phone_hash'],
                isset($data['email']) && $data['email'] !== '' ? hash('sha256', $data['email']) : $citizen['email_hash'],
                $data['status'] ?? $citizen['status'],
                Auth::$context['user_id'] ?? null,
                $citizen['id'],
            ]
        );

        if (isset($data['address']) && is_array($data['address'])) {
            $unitId = $data['address']['admin_unit_id'] ?? $unit['admin_unit_id'] ?? Auth::$context['scope_unit'];
            if ($unitId !== null) {
                Auth::assertInScope($request, $unitId);
                $existing = Database::fetchOne(
                    'SELECT id FROM citizen_address WHERE citizen_id = ? AND is_primary = 1',
                    [$citizen['id']]
                );
                if ($existing !== null) {
                    Database::run(
                        'UPDATE citizen_address SET admin_unit_id = ?, village_enc = ?, house_no_enc = ?, gps_lat = ?, gps_long = ? WHERE id = ?',
                        [
                            $unitId,
                            Crypto::encrypt($data['address']['village'] ?? Crypto::decrypt($existing['village_enc'] ?? '')),
                            Crypto::encrypt($data['address']['house_no'] ?? Crypto::decrypt($existing['house_no_enc'] ?? '')),
                            $data['address']['gps_lat'] ?? $existing['gps_lat'],
                            $data['address']['gps_long'] ?? $existing['gps_long'],
                            $existing['id'],
                        ]
                    );
                } else {
                    Database::run(
                        'INSERT INTO citizen_address (id, citizen_id, admin_unit_id, village_enc, house_no_enc, gps_lat, gps_long, is_primary)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 1)',
                        [
                            uuid4(), $citizen['id'], $unitId,
                            Crypto::encrypt($data['address']['village'] ?? null),
                            Crypto::encrypt($data['address']['house_no'] ?? null),
                            $data['address']['gps_lat'] ?? null,
                            $data['address']['gps_long'] ?? null,
                        ]
                    );
                }
            }
        }

        Audit::log($request, 'UPDATE_CITIZEN', 'citizen', $citizen['id'], null, [
            'fields' => array_keys(array_filter($data, fn($v) => $v !== null && $v !== '')),
        ]);
        return ['uuid' => $citizenUuid, 'status' => 'updated'];
    }
}
