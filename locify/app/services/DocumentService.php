<?php

declare(strict_types=1);

/** Document lifecycle: generation, signing, public verification. */

final class DocumentService
{
    /**
     * Create a draft document for a completed/reviewed application.
     * In production the file is produced from an official template; here a
     * signed payload JSON is stored as the document content in storage.
     */
    public static function create(Request $request, string $applicationUuid, array $data): array
    {
        $app = Database::fetchOne(
            'SELECT a.*, s.service_code, s.issuance_mode FROM application a
             JOIN service_catalog s ON s.id = a.service_catalog_id
             WHERE a.uuid = ?',
            [$applicationUuid]
        );
        if ($app === null) {
            Response::notFound('Application not found');
        }
        Auth::assertInScope($request, $app['admin_unit_id']);

        $id = uuid4();
        $uuid = uuid4();
        $docNumber = nextDocumentNumber(Database::pdo(), $app['service_code'] ?? null);

        $content = [
            'document_type' => $data['document_type'] ?? 'certificate',
            'application_number' => $app['application_number'],
            'citizen_uuid' => (Database::fetchOne('SELECT uuid FROM citizen WHERE id = ?', [$app['citizen_id']])['uuid'] ?? null),
            'generated_at' => date('c'),
            'fields' => $data['fields'] ?? [],
        ];
        $fileContent = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $fileName = 'doc_' . $uuid . '.json';
        $filePath = LOCIFY_STORAGE . '/documents/' . $fileName;
        if (!is_dir(dirname($filePath))) {
            mkdir(dirname($filePath), 0700, true);
        }
        // Encrypt document content at rest
        file_put_contents($filePath, Crypto::encrypt($fileContent) ?? '');
        chmod($filePath, 0600);

        $printRequired = in_array($app['issuance_mode'], ['PRINT_ONLY', 'DIGITAL_AND_PRINT'], true) ? 1 : 0;

        Database::run(
            'INSERT INTO document (id, uuid, document_number, document_type, title,
                application_id, original_document_id, citizen_id, file_path_enc, file_hash,
                status, print_required, version, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $uuid,
                $docNumber,
                $data['document_type'] ?? 'certificate',
                $data['title'] ?? $docNumber,
                $app['id'],
                $app['requested_document_id'] ?? null,
                $app['citizen_id'],
                Crypto::encrypt($filePath),
                hash_file('sha256', $filePath),
                'draft',
                $printRequired,
                1,
                Auth::$context['user_id'] ?? null,
            ]
        );

        Database::run(
            'INSERT INTO document_version (id, document_id, version_no, file_path_enc, created_by)
             VALUES (?, ?, ?, ?, ?)',
            [uuid4(), $id, 1, Crypto::encrypt($filePath), Auth::$context['user_id'] ?? null]
        );

        Audit::log($request, 'CREATE_DOCUMENT', 'document', $id);
        return ['uuid' => $uuid, 'document_number' => $docNumber, 'status' => 'draft'];
    }

    /** Sign a document: hash + verification code + QR payload (signature
     *  infrastructure REQUIRES OFFICIAL INTEGRATION; this records an
     *  internal signature record with document hash). */
    public static function sign(Request $request, string $documentUuid): array
    {
        $doc = Database::fetchOne('SELECT * FROM document WHERE uuid = ?', [$documentUuid]);
        if ($doc === null) {
            Response::notFound('Document not found');
        }
        if (in_array($doc['status'], ['signed', 'issued', 'revoked'], true)) {
            Response::error('DOCUMENT_STATE', 'Document cannot be signed in its current state', 409);
        }

        $filePath = Crypto::decrypt($doc['file_path_enc']);
        $docHash = $filePath !== null && is_file($filePath) ? hash_file('sha256', $filePath) : $doc['file_hash'];
        $code = verificationCode();

        Database::transaction(function () use ($doc, $code, $docHash, $request) {
            $signatureId = uuid4();
            Database::run(
                'INSERT INTO digital_signature (id, document_id, signer_user_id, signature_value,
                    document_hash, hash_algorithm, timestamp)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [
                    $signatureId,
                    $doc['id'],
                    Auth::$context['user_id'] ?? null,
                    'sig_' . Jwt::randomToken(24), // placeholder; real value from e-signature HSM/API
                    $docHash,
                    'SHA-256',
                ]
            );
            Database::run(
                "UPDATE document SET status = 'signed', verification_code = ?, file_hash = ? WHERE id = ?",
                [$code, $docHash, $doc['id']]
            );
        });

        Audit::log($request, 'SIGN_DOCUMENT', 'document', $doc['id'], null,
            ['verification_code' => $code, 'document_hash' => $docHash]);

        return [
            'uuid' => $documentUuid,
            'status' => 'signed',
            'verification_code' => $code,
            'document_hash' => $docHash,
            'qr_payload' => self::qrPayload($code, $doc['document_number']),
        ];
    }

    /** Public verification — returns NO personal data. */
    public static function verify(Request $request, string $code): array
    {
        $code = strtoupper(trim($code));
        $doc = Database::fetchOne(
            'SELECT d.id, d.document_number, d.document_type, d.status, d.issued_at_eth, d.issued_at_greg,
                    d.expires_at, d.file_hash, au.name AS issuing_authority, o.name AS office_name
             FROM document d
             LEFT JOIN office o ON o.id = d.issuing_office_id
             LEFT JOIN admin_unit au ON au.id = o.admin_unit_id
             WHERE d.verification_code = ?',
            [$code]
        );
        if ($doc === null) {
            Database::run(
                'INSERT INTO verification_record (id, document_id, verification_method, result, source_ip_hash)
                 VALUES (?, NULL, ?, ?, ?)',
                [uuid4(), 'code', 'invalid', hash('sha256', $request->ip)]
            );
            return ['status' => 'invalid'];
        }

        $result = match ($doc['status']) {
            'revoked' => 'revoked',
            'expired' => 'expired',
            'signed', 'issued', 'verified' => 'valid',
            default => 'invalid',
        };

        Database::run(
            'INSERT INTO verification_record (id, document_id, verification_method, result, source_ip_hash)
             VALUES (?, ?, ?, ?, ?)',
            [uuid4(), $doc['id'], 'code', $result, hash('sha256', $request->ip)]
        );

        if ($result !== 'valid') {
            return ['status' => $result];
        }

        return [
            'status' => 'valid',
            'document_type' => $doc['document_type'],
            'document_number' => $doc['document_number'],
            'issuing_authority' => $doc['issuing_authority'],
            'office' => $doc['office_name'],
            'issue_date_eth' => $doc['issued_at_eth'],
            'issue_date_greg' => $doc['issued_at_greg'],
            'document_hash' => $doc['file_hash'],
        ];
    }

    public static function qrPayload(string $code, string $docNumber): string
    {
        $base = Config::get('app.url');
        return $base . '/verify?code=' . urlencode($code);
    }

    /** Issue (publish to citizen) and set both calendar dates. */
    public static function issue(Request $request, string $documentUuid): array
    {
        $doc = Database::fetchOne('SELECT * FROM document WHERE uuid = ?', [$documentUuid]);
        if ($doc === null) {
            Response::notFound('Document not found');
        }
        [$ey, $em, $ed] = Calendar::gregDateToEth(date('Y-m-d'));
        Database::run(
            "UPDATE document SET status = 'issued', issued_at_eth = ?, issued_at_greg = ?,
                issuing_office_id = ? WHERE id = ?",
            [
                Calendar::formatEth($ey, $em, $ed),
                date('Y-m-d'),
                Database::fetchOne(
                    'SELECT o.id FROM office o
                     JOIN citizen_address ca ON ca.admin_unit_id = o.admin_unit_id
                     WHERE ca.citizen_id = ? AND ca.is_primary = 1 AND o.is_active = 1 LIMIT 1',
                    [$doc['citizen_id']]
                )['id'] ?? Database::fetchOne(
                    'SELECT id FROM office WHERE admin_unit_id = ? LIMIT 1',
                    [Auth::$context['scope_unit'] ?? '']
                )['id'] ?? null,
                $doc['id'],
            ]
        );
        Audit::log($request, 'ISSUE_DOCUMENT', 'document', $doc['id']);
        NotificationService::sendToCitizen($request, $doc['citizen_id'], 'doc_ready', [
            'document_type' => $doc['document_type'],
            'document_number' => $doc['document_number'],
        ]);
        return ['uuid' => $documentUuid, 'status' => 'issued'];
    }

    /** Revoke a document (signed + audited). */
    public static function revoke(Request $request, string $documentUuid, ?string $reason): array
    {
        $doc = Database::fetchOne('SELECT * FROM document WHERE uuid = ?', [$documentUuid]);
        if ($doc === null) {
            Response::notFound('Document not found');
        }
        Database::run("UPDATE document SET status = 'revoked' WHERE id = ?", [$doc['id']]);
        Audit::log($request, 'REVOKE_DOCUMENT', 'document', $doc['id'], null, ['revoked' => true], 'success', $reason);
        return ['uuid' => $documentUuid, 'status' => 'revoked'];
    }
}
