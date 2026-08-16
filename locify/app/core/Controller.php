<?php

declare(strict_types=1);

/** Base controller: common request helpers. */

abstract class Controller
{
    /** Current authenticated context (from Auth middleware). */
    protected function context(): array
    {
        return Auth::$context;
    }

    /** Require authentication only (no specific permission). */
    protected function requireAuth(Request $request): void
    {
        Auth::require($request);
    }

    /** Require auth + a permission; then log a denied audit automatically. */
    protected function requirePermission(Request $request, string $permission): void
    {
        Auth::requirePermission($request, $permission);
    }

    protected function audit(Request $request, string $action, ?string $resourceType = null, ?string $resourceId = null, mixed $previous = null, mixed $new = null): void
    {
        Audit::log($request, $action, $resourceType, $resourceId, $previous, $new);
    }
}
