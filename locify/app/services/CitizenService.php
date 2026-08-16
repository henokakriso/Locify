<?php

declare(strict_types=1);

/** Citizen lifecycle: creation with deduplication, encryption, verification. */

final class CitizenService
{
    public static function create(Request $request, array $data): array
    {
        $adminUnitId = $data['address']['admin_unit_id']
            ?? Auth::$context['scope_unit']
            ?? null;
        if ($adminUnitId === null || !Database::fetchOne('SELECT id FROM admin_unit WHERE id = ?', [$adminUnitId])) {
            Response::validationError(['admin_unit_id' => 'Invalid administrative unit']);
        }
        Auth::assertInScope($request, $adminUnitId);

        $nationalIdHash = isset($data['national_id']) && $data['national_id'] !== ''
            ? hash('sha256', $data['national_id'])
            : null;
        $phoneHash = isset($data['phone']) && $data['phone'] !== '' ? hash('sha256', $data['phone']) : null;

        if ($nationalIdHash !== null) {
            $existing = Database::fetchOne(
                'SELECT id FROM citizen WHERE national_id_hash = ? AND status != ?',
                [$nationalIdHash, 'archived']
            );
            if ($existing !== null) {
                SecurityEvent::log('duplicate_citizen_detected', 'low', $request, ['national_id_hash' => $nationalIdHash]);
                Audit::log($request, 'CREATE_CITIZEN', 'citizen', $existing['id'], result: 'denied', reason: 'duplicate national ID');
                Response::error('DUPLICATE_CITIZEN', 'A citizen with this national ID already exists', 409);
            }
        }

        $id = uuid4();
        $uuid = uuid4();
        $dobEth = $data['dob_eth'] ?? null;
        $dobGreg = $data['dob_greg'] ?? null;

        if ($dobEth !== null && $dobGreg === null) {
            [$ey, $em, $ed] = array_map('intval', explode('-', (string)$dobEth));
            $dobGreg = Calendar::ethToGregDate($ey, $em, $ed);
        }

        Database::run(
            'INSERT INTO citizen (id, uuid, national_id_hash, national_id_mask,
                first_name_enc, middle_name_enc, last_name_enc, local_name_enc,
                dob_eth, dob_greg, sex, phone_hash, email_hash, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $uuid,
                $nationalIdHash,
                isset($data['national_id']) ? maskMiddle((string)$data['national_id']) : null,
                Crypto::encrypt($data['first_name'] ?? null),
                Crypto::encrypt($data['middle_name'] ?? null),
                Crypto::encrypt($data['last_name'] ?? null),
                Crypto::encrypt($data['local_name'] ?? null),
                $dobEth,
                $dobGreg,
                $data['sex'] ?? null,
                $phoneHash,
                isset($data['email']) && $data['email'] !== '' ? hash('sha256', $data['email']) : null,
                'pending_verification',
                Auth::$context['user_id'] ?? null,
            ]
        );

        if (isset($data['address'])) {
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

        Audit::log($request, 'CREATE_CITIZEN', 'citizen', $id, null,
            Audit::mask($data, ['national_id', 'phone', 'email']));

        return [
            'uuid' => $uuid,
            'status' => 'pending_verification',
            'created_at' => date('c'),
        ];
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
