<?php

declare(strict_types=1);

/** Authentication: login, MFA-ready sessions, refresh, officer registration. */

final class AuthController extends Controller
{
    public function login(Request $request): void
    {
        Validator::requireFields($request, ['username', 'password']);
        $username = (string)$request->input('username');
        $password = (string)$request->input('password');

        $user = Database::fetchOne(
            'SELECT id, username_hash, password_hash, citizen_id, status, mfa_enabled,
                    failed_attempts, locked_until
             FROM `user` WHERE username_hash = ?',
            [hash('sha256', $username)]
        );

        $rateOk = (new RateLimiter(Database::pdo()))->allow('login:' . $username, 10, $request->ip);
        if (!$rateOk) {
            SecurityEvent::log('login_rate_limited', 'high', $request, ['username_hash' => hash('sha256', $username)]);
            Response::error('RATE_LIMITED', 'Too many login attempts', 429);
        }

        if ($user === null) {
            SecurityEvent::log('failed_login', 'low', $request, ['username_hash' => hash('sha256', $username)]);
            Response::unauthorized('Invalid credentials');
        }

        if ($user['locked_until'] !== null && strtotime((string)$user['locked_until']) > time()) {
            Response::error('ACCOUNT_LOCKED', 'Account is temporarily locked', 423);
        }
        if ($user['status'] !== 'active') {
            Response::unauthorized('Account is not active');
        }

        if (!password_verify($password, $user['password_hash'])) {
            $attempts = (int)$user['failed_attempts'] + 1;
            $lockedUntil = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
            Database::run(
                'UPDATE `user` SET failed_attempts = ?, locked_until = ? WHERE id = ?',
                [$attempts, $lockedUntil, $user['id']]
            );
            SecurityEvent::log('failed_login', 'medium', $request, ['username_hash' => hash('sha256', $username), 'attempts' => $attempts]);
            Response::unauthorized('Invalid credentials');
        }

        if (Config::get('security.mfa_enforced', false) && (int)$user['mfa_enabled'] === 0) {
            Response::error('MFA_REQUIRED', 'Multi-factor authentication must be enabled', 403);
        }

        Database::run(
            'UPDATE `user` SET failed_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?',
            [$user['id']]
        );

        $access = Jwt::encode([
            'sub' => $user['id'],
            'citizen_id' => $user['citizen_id'],
            'type' => 'access',
        ]);
        $refresh = Jwt::encode([
            'sub' => $user['id'],
            'type' => 'refresh',
        ], (int)Config::get('security.jwt_refresh_ttl', 2592000));

        Auth::$context = ['user_id' => $user['id'], 'citizen_id' => $user['citizen_id']];
        Audit::log($request, 'LOGIN', 'user', $user['id']);
        SecurityEvent::log('successful_login', 'info', $request);

        Response::success([
            'access_token' => $access,
            'refresh_token' => $refresh,
            'token_type' => 'Bearer',
            'expires_in' => (int)Config::get('security.jwt_ttl', 900),
        ]);
    }

    public function refresh(Request $request): void
    {
        Validator::requireFields($request, ['refresh_token']);
        $claims = Jwt::decode((string)$request->input('refresh_token'));
        if ($claims === null || ($claims['type'] ?? '') !== 'refresh' || !isset($claims['sub'])) {
            SecurityEvent::log('invalid_refresh_token', 'medium', $request);
            Response::unauthorized('Invalid refresh token');
        }
        $user = Database::fetchOne('SELECT id, status FROM `user` WHERE id = ?', [$claims['sub']]);
        if ($user === null || $user['status'] !== 'active') {
            Response::unauthorized('Account not active');
        }
        Response::success([
            'access_token' => Jwt::encode(['sub' => $user['id'], 'type' => 'access']),
        ]);
    }

    public function me(Request $request): void
    {
        $this->requireAuth($request);
        $ctx = $this->context();
        $user = Database::fetchOne(
            'SELECT u.id, u.username_hash, u.status, u.mfa_enabled, u.last_login, c.uuid AS citizen_uuid
             FROM `user` u LEFT JOIN citizen c ON c.id = u.citizen_id WHERE u.id = ?',
            [$ctx['user_id']]
        );
        Response::success([
            'user_id' => $user['id'],
            'citizen_uuid' => $user['citizen_uuid'],
            'mfa_enabled' => (int)$user['mfa_enabled'],
            'last_login' => $user['last_login'],
            'roles' => array_map(fn($r) => ['name' => $r['role_name'], 'unit' => $r['unit_name'], 'unit_type' => $r['unit_type']], $ctx['roles']),
            'permissions' => $ctx['permissions'],
            'scope_unit' => $ctx['scope_unit'],
        ]);
    }

    /** Register an officer user (Kebele Admin / System Admin). */
    public function registerUser(Request $request): void
    {
        $this->requirePermission($request, 'USER:MANAGE');
        Validator::requireFields($request, ['username', 'password', 'role', 'admin_unit_id']);

        $role = Database::fetchOne('SELECT id FROM role WHERE name = ?', [$request->input('role')]);
        if ($role === null) {
            Response::validationError(['role' => 'Unknown role']);
        }
        $unit = Database::fetchOne('SELECT id FROM admin_unit WHERE id = ?', [$request->input('admin_unit_id')]);
        if ($unit === null) {
            Response::validationError(['admin_unit_id' => 'Unknown administrative unit']);
        }
        Auth::assertInScope($request, $unit['id']);

        $password = (string)$request->input('password');
        if (strlen($password) < 12 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password)
            || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
            Response::validationError(['password' => 'Password must be 12+ chars with upper, lower, number and special character']);
        }
        if (Database::fetchOne('SELECT id FROM `user` WHERE username_hash = ?', [hash('sha256', $request->input('username'))])) {
            Response::error('DUPLICATE_USER', 'Username already exists', 409);
        }

        $id = uuid4();
        Database::transaction(function () use ($id, $request, $role, $unit, $password) {
            Database::run(
                'INSERT INTO `user` (id, username_hash, password_hash, status, mfa_enabled)
                 VALUES (?, ?, ?, ?, 1)',
                [$id, hash('sha256', $request->input('username')), password_hash($password, PASSWORD_ARGON2ID), 'active']
            );
            Database::run(
                'INSERT INTO user_role (user_id, role_id, admin_unit_id) VALUES (?, ?, ?)',
                [$id, $role['id'], $unit['id']]
            );
        });

        Audit::log($request, 'CREATE_USER', 'user', $id, null,
            ['username_hash' => hash('sha256', $request->input('username')), 'role' => $request->input('role'), 'admin_unit_id' => $unit['id']]);
        Response::success(['user_id' => $id], 201);
    }

    public function logout(Request $request): void
    {
        $this->requireAuth($request);
        $claims = $this->context()['claims'] ?? [];
        $jti = (string)($claims['jti'] ?? '');
        if ($jti !== '') {
            Database::run(
                'INSERT IGNORE INTO token_denylist (jti, user_id, expires_at)
                 VALUES (?, ?, ?)',
                [$jti, $this->context()['user_id'], date('Y-m-d H:i:s', (int)($claims['exp'] ?? time()))]
            );
        }
        Audit::log($request, 'LOGOUT', 'user', $this->context()['user_id']);
        Response::success(['status' => 'logged_out']);
    }

    /** Self-service password change (requires current password). */
    public function changePassword(Request $request): void
    {
        $this->requireAuth($request);
        Validator::requireFields($request, ['current_password', 'new_password']);

        $userId = $this->context()['user_id'];
        $rateOk = (new RateLimiter(Database::pdo()))->allow('pwchange:' . $userId, 5, $request->ip);
        if (!$rateOk) {
            SecurityEvent::log('password_change_rate_limited', 'high', $request);
            Response::error('RATE_LIMITED', 'Too many attempts. Try again in a few minutes.', 429);
        }
        $user = Database::fetchOne('SELECT id, password_hash FROM `user` WHERE id = ?', [$userId]);
        if ($user === null || !password_verify((string)$request->input('current_password'), $user['password_hash'])) {
            SecurityEvent::log('failed_password_change', 'medium', $request);
            Response::unauthorized('Current password is incorrect');
        }

        $password = (string)$request->input('new_password');
        if (strlen($password) < 12 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password)
            || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
            Response::validationError(['new_password' => 'Password must be 12+ chars with upper, lower, number and special character']);
        }

        Database::run(
            'UPDATE `user` SET password_hash = ? WHERE id = ?',
            [password_hash($password, PASSWORD_ARGON2ID), $userId]
        );
        Audit::log($request, 'CHANGE_PASSWORD', 'user', $userId);
        SecurityEvent::log('password_changed', 'info', $request);
        Response::success(['status' => 'changed']);
    }
}
