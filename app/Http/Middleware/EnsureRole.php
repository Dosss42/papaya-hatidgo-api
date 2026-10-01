<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard by role: `->middleware('role:driver')` or `role:admin` (several: `role:driver,admin`).
 * Runs AFTER auth:sanctum, so the user is always known here. This is the real security check;
 * the mobile app's role guard only decides which screens to show.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $role = $request->user()?->role;

        if (! $role instanceof UserRole || ! in_array($role->value, $roles, true)) {
            throw new ApiException(
                __('api.forbidden_role'),
                'FORBIDDEN_ROLE',
                403,
            );
        }

        return $next($request);
    }
}
