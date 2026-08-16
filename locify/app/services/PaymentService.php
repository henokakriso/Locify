<?php

declare(strict_types=1);

/**
 * Payment abstraction layer. LOCIFY never processes payments directly;
 * provider adapters implement initiate/confirm/refund. The default 'mock'
 * provider simulates a gateway with an HMAC-signed webhook.
 */

interface PaymentProvider
{
    public function initiate(float $amount, string $currency, string $reference, string $redirectUrl): array;
    public function confirm(string $providerRef): array;
}

final class MockPaymentProvider implements PaymentProvider
{
    public function initiate(float $amount, string $currency, string $reference, string $redirectUrl): array
    {
        $ref = 'MOCK-' . strtoupper(Jwt::randomToken(10));
        return [
            'provider_ref' => $ref,
            'redirect_url' => $redirectUrl . '?ref=' . $ref,
            'status' => 'pending',
        ];
    }

    public function confirm(string $providerRef): array
    {
        return ['status' => 'confirmed'];
    }
}

final class PaymentService
{
    public static function provider(): PaymentProvider
    {
        $name = (string)Config::get('payment.provider', 'mock');
        return match ($name) {
            'mock' => new MockPaymentProvider(),
            default => new MockPaymentProvider(),
        };
    }

    /** Create a pending payment record and initiate with the provider. */
    public static function initiate(Request $request, float $amount, string $currency, ?string $applicationUuid): array
    {
        if ($applicationUuid !== null) {
            $app = Database::fetchOne('SELECT * FROM application WHERE uuid = ?', [$applicationUuid]);
            if ($app === null) {
                Response::notFound('Application not found');
            }
        }

        $id = uuid4();
        $idempotency = Jwt::randomToken(20);
        Database::run(
            'INSERT INTO payment (id, application_id, amount, currency, status, idempotency_key, initiated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$id, $app['id'] ?? null, $amount, $currency, 'pending', $idempotency]
        );

        $result = self::provider()->initiate(
            $amount,
            $currency,
            $id,
            (string)Config::get('app.url') . '/payment/callback'
        );
        Database::run(
            'UPDATE payment SET provider_ref = ?, provider_name = ? WHERE id = ?',
            [$result['provider_ref'], 'mock', $id]
        );

        Audit::log($request, 'INITIATE_PAYMENT', 'payment', $id, null,
            ['amount' => $amount, 'currency' => $currency]);
        return ['payment_id' => $id, ...$result];
    }

    /** Confirm via webhook. HMAC signature must match the webhook secret. */
    public static function confirmWebhook(Request $request, array $data): array
    {
        $signature = $request->header('x-locify-signature') ?? '';
        $expected = hash_hmac('sha256', json_encode($data), (string)Config::get('payment.webhook_secret'));
        if (!hash_equals($expected, $signature)) {
            SecurityEvent::log('invalid_payment_webhook', 'high', $request);
            Response::unauthorized('Invalid webhook signature');
        }

        $ref = $data['provider_ref'] ?? null;
        if ($ref === null) {
            Response::validationError(['provider_ref' => 'Missing provider reference']);
        }
        $payment = Database::fetchOne('SELECT * FROM payment WHERE provider_ref = ?', [$ref]);
        if ($payment === null) {
            Response::notFound('Payment not found');
        }
        if ($payment['status'] === 'confirmed') {
            return ['status' => 'confirmed', 'duplicate' => true]; // idempotent
        }

        $result = self::provider()->confirm($ref);
        Database::run(
            "UPDATE payment SET status = 'confirmed', confirmed_at = NOW() WHERE id = ?",
            [$payment['id']]
        );
        Audit::log($request, 'CONFIRM_PAYMENT', 'payment', $payment['id'], ['status' => 'pending'], ['status' => 'confirmed']);

        if ($payment['application_id'] !== null) {
            NotificationService::sendToCitizen($request, self::appCitizen($payment['application_id']), 'pay_confirmed', []);
        }
        return ['status' => $result['status']];
    }

    private static function appCitizen(string $applicationId): ?string
    {
        $app = Database::fetchOne('SELECT citizen_id FROM application WHERE id = ?', [$applicationId]);
        return $app['citizen_id'] ?? null;
    }

    /** Reconciliation stub: match provider reports (production: scheduled job). */
    public static function reconcile(): array
    {
        $pending = Database::fetchAll('SELECT * FROM payment WHERE status = ?', ['pending']);
        $updated = 0;
        foreach ($pending as $p) {
            if (strtotime((string)$p['initiated_at']) < time() - 86400) {
                Database::run("UPDATE payment SET status = 'expired' WHERE id = ?", [$p['id']]);
                $updated++;
            }
        }
        return ['expired' => $updated, 'pending_remaining' => count($pending) - $updated];
    }
}
