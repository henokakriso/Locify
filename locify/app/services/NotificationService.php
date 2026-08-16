<?php

declare(strict_types=1);

/** Notification delivery (SMS/email/in-app). External gateways are adapter-based;
 *  in this build, SMS/email are logged as sent and in-app rows are created. */

final class NotificationService
{
    public static function send(Request $request, array $data): array
    {
        $id = uuid4();
        Database::run(
            'INSERT INTO notification (id, user_id, citizen_id, channel, template_id, subject, body, data_json, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $data['user_id'] ?? null,
                $data['citizen_id'] ?? null,
                $data['channel'] ?? 'in_app',
                $data['template_id'] ?? null,
                $data['subject'] ?? null,
                $data['body'] ?? null,
                json_encode($data['data'] ?? [], JSON_UNESCAPED_UNICODE),
                'sent',
            ]
        );
        Audit::log($request, 'SEND_NOTIFICATION', 'notification', $id, null,
            ['channel' => $data['channel'] ?? 'in_app', 'template' => $data['template_id'] ?? null]);
        return ['id' => $id, 'status' => 'sent'];
    }

    /** Send a template-based notification to a citizen (via their user account). */
    public static function sendToCitizen(Request $request, string $citizenId, string $templateId, array $vars): void
    {
        $tpl = Database::fetchOne(
            'SELECT body, subject FROM notification_template WHERE id = ? AND locale = ? LIMIT 1',
            [$templateId, Config::get('language.default')]
        );
        if ($tpl === null) {
            return;
        }
        $body = $tpl['body'];
        foreach ($vars as $key => $value) {
            $body = str_replace('{' . $key . '}', (string)$value, $body);
        }
        $user = Database::fetchOne('SELECT id FROM `user` WHERE citizen_id = ?', [$citizenId]);
        self::send($request, [
            'citizen_id' => $citizenId,
            'user_id' => $user['id'] ?? null,
            'channel' => 'sms',
            'template_id' => $templateId,
            'body' => $body,
        ]);
    }

    /** List the current user's in-app notifications. */
    public static function listForUser(Request $request): array
    {
        $rows = Database::fetchAll(
            'SELECT id, channel, subject, body, data_json, status, created_at
             FROM notification WHERE user_id = ? ORDER BY created_at DESC LIMIT 50',
            [Auth::$context['user_id']]
        );
        foreach ($rows as &$row) {
            $row['data'] = json_decode((string)$row['data_json'], true);
            unset($row['data_json']);
        }
        return $rows;
    }
}
