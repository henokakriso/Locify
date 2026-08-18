<?php

declare(strict_types=1);

/**
 * Authentication + RBAC enforcement middleware.
 * Order: token validity → user status → role scope → permission → resource scope.
 * A kebele admin cannot access another kebele's data (scope enforced via admin_unit subtree).
 */

final class Auth
{
    /** @var array<string,mixed> current authenticated context */
    public static array $context = [];

    /** Validate Bearer token and load user context. Returns context array or null. */
    public static function authenticate(Request $request): ?array
    {
        $token = $request->bearerToken();
        if ($token === null) {
            return null;
        }
        $claims = Jwt::decode($token);
        if ($claims === null || !isset($claims['sub'])) {
            return null;
        }
        // Logged-out (denylisted) tokens are rejected even if unexpired.
        $denied = Database::fetchOne(
            'SELECT 1 FROM token_denylist WHERE jti = ? AND expires_at > NOW()',
            [$claims['jti'] ?? '']
        );
        if ($denied !== null) {
            return null;
        }

        $user = Database::fetchOne(
            'SELECT id, username_hash, citizen_id, status, mfa_enabled FROM `user` WHERE id = ?',
            [$claims['sub']]
        );
        if ($user === null || $user['status'] !== 'active') {
            return null;
        }

        $roles = Database::fetchAll(
            'SELECT r.id AS role_id, r.name AS role_name, ur.admin_unit_id,
                    au.type AS unit_type, au.name AS unit_name
             FROM user_role ur
             JOIN role r ON r.id = ur.role_id
             JOIN admin_unit au ON au.id = ur.admin_unit_id
             WHERE ur.user_id = ? AND ur.is_active = 1',
            [$user['id']]
        );
        if ($roles === []) {
            return null;
        }

        $permissions = Database::fetchAll(
            'SELECT DISTINCT p.name
             FROM role_permission rp
             JOIN permission p ON p.id = rp.permission_id
             WHERE rp.role_id IN (' . implode(',', array_fill(0, count($roles), '?')) . ')',
            array_column($roles, 'role_id')
        );

        // Scope derives from the highest (ancestor-most) unit among the user's roles,
        // so multi-role users (e.g. a system_admin who is also a kebele officer)
        // keep the widest possible view while single-scope officers stay local.
        $unitDepth = function (string $unitId) use (&$unitDepth): int {
            $row = Database::fetchOne('SELECT parent_id FROM admin_unit WHERE id = ?', [$unitId]);
            if ($row === null || $row['parent_id'] === null) {
                return 0;
            }
            return 1 + $unitDepth($row['parent_id']);
        };
        usort($roles, fn($a, $b) => $unitDepth($a['admin_unit_id']) <=> $unitDepth($b['admin_unit_id']));

        $scopeUnit = $roles[0]['admin_unit_id'];
        $scopeDescendants = self::unitSubtreeIds($scopeUnit);

        self::$context = [
            'user_id'       => $user['id'],
            'citizen_id'    => $user['citizen_id'],
            'roles'         => $roles,
            'permissions'   => array_column($permissions, 'name'),
            'scope_unit'    => $scopeUnit,
            'scope_subtree' => $scopeDescendants,
            'token'         => $token,
            'claims'        => $claims,
        ];
        return self::$context;
    }

    /** IDs of the given admin unit and all of its descendants. */
    public static function unitSubtreeIds(string $unitId): array
    {
        $ids = [$unitId];
        $queue = [$unitId];
        while ($queue !== []) {
            $current = array_shift($queue);
            $children = Database::fetchAll(
                'SELECT id FROM admin_unit WHERE parent_id = ? AND status = ?',
                [$current, 'active']
            );
            foreach ($children as $child) {
                $ids[] = $child['id'];
                $queue[] = $child['id'];
            }
        }
        return $ids;
    }

    /** IDs of the given admin unit and all of its ancestors (unit itself first). */
    public static function unitAncestorIds(string $unitId): array
    {
        $ids = [$unitId];
        $current = $unitId;
        while (true) {
            $row = Database::fetchOne('SELECT parent_id FROM admin_unit WHERE id = ?', [$current]);
            if ($row === null || $row['parent_id'] === null) {
                break;
            }
            $ids[] = $row['parent_id'];
            $current = $row['parent_id'];
        }
        return $ids;
    }

    /** Require authentication; exits with 401 otherwise. */
    public static function require(Request $request): void
    {
        if (self::authenticate($request) === null) {
            SecurityEvent::log('failed_authentication', 'medium', $request);
            Response::unauthorized('Authentication required');
        }
    }

    /** Require a permission; exits with 403 otherwise. */
    public static function requirePermission(Request $request, string $permission): void
    {
        if (self::$context === []) {
            self::require($request);
        }
        if (!in_array($permission, self::$context['permissions'], true)) {
            SecurityEvent::log('permission_denied', 'low', $request);
            Audit::log($request, 'ACCESS_DENIED', 'permission', $permission, result: 'denied');
            Response::forbidden("Missing permission: $permission");
        }
    }

    /** Ensure the resource's admin_unit is within the actor's scope. */
    public static function assertInScope(Request $request, string $resourceUnitId): void
    {
        if (!in_array($resourceUnitId, self::$context['scope_subtree'], true)) {
            Audit::log($request, 'ACCESS_DENIED', 'scope', $resourceUnitId, result: 'denied');
            Response::forbidden('Resource is outside your administrative scope');
        }
    }

    /**
     * Scope check that also serves citizens: a citizen can act on resources
     * owned by their kebele or any ancestor unit (e.g. a woreda-level service).
     */
    public static function assertResourceScope(Request $request, string $resourceUnitId): void
    {
        if (in_array($resourceUnitId, self::$context['scope_subtree'], true)) {
            return;
        }
        $myUnit = Database::fetchOne(
            'SELECT admin_unit_id FROM citizen_address WHERE citizen_id = ? AND is_primary = 1',
            [self::$context['citizen_id'] ?? '']
        );
        if ($myUnit !== null && in_array($resourceUnitId, self::unitAncestorIds($myUnit['admin_unit_id']), true)) {
            return;
        }
        Audit::log($request, 'ACCESS_DENIED', 'scope', $resourceUnitId, result: 'denied');
        Response::forbidden('Resource is outside your administrative scope');
    }

    public static function isCitizen(Request $request): bool
    {
        return in_array('citizen', array_column(self::$context['roles'], 'role_name'), true);
    }
}
