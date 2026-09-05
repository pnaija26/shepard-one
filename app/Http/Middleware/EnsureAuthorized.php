<?php

namespace App\Http\Middleware;

use App\Services\AuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Story 1.6 AC2: route-level enforcement via AuthorizationService.
 */
class EnsureAuthorized
{
    public function __construct(
        private AuthorizationService $authorization,
    ) {
    }

    public function handle(Request $request, Closure $next, string $action, ?string $orgKey = null): Response
    {
        $user = $request->user();
        $orgId = $orgKey !== null ? (int) $request->route($orgKey, $request->input($orgKey)) : null;

        if (! $this->authorization->allows($user, $action, $orgId ?: null)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
