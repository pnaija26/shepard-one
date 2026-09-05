<?php

use App\Services\AuditImmutabilityException;
use App\Services\HouseholdConflictException;
use App\Services\MemberLifecycleTransitionException;
use App\Services\ConfigurationLockedException;
use App\Services\ConfigurationReferencedException;
use App\Services\LastSuperAdminException;
use App\Services\MovementConflictException;
use App\Services\ScopeGrantDeniedException;
use App\Services\TeamDashboardConflictException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust reverse proxies (Docker nginx / VPS TLS terminator) so HTTPS
        // and client IPs resolve correctly behind X-Forwarded-* headers.
        // Hard-coded for config:cache safety; Docker traffic always enters via nginx.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        $middleware->alias([
            'mfa.enrolled' => \App\Http\Middleware\EnsureMfaEnrolled::class,
            'mfa.verified' => \App\Http\Middleware\EnsureMfaForPrivilegedUsers::class,
            'can' => \App\Http\Middleware\EnsureAuthorized::class,
            'auth.api_principal' => \App\Http\Middleware\AuthenticateApiPrincipal::class,
            'api.platform' => \App\Http\Middleware\EnforceApiPlatform::class,
        ]);

        // Story 1.2: enforce MFA enrollment + verification on authenticated web routes.
        $middleware->web(append: [
            \App\Http\Middleware\EnsureMfaEnrolled::class,
            \App\Http\Middleware\EnsureMfaForPrivilegedUsers::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Story 1.5: a movement request that conflicts with existing state
        // (duplicate open movement, already decided) is a 409 Conflict — the
        // active branch association remains unchanged and the reason is audited.
        // Laravel 13 render() takes one callable; the first parameter type
        // selects which exception it handles.
        $exceptions->render(function (MovementConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        });

        $exceptions->render(function (LastSuperAdminException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        });

        $exceptions->render(function (ScopeGrantDeniedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        });

        $exceptions->render(function (ConfigurationLockedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        });

        $exceptions->render(function (ConfigurationReferencedException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        });

        $exceptions->render(function (AuditImmutabilityException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        });

        $exceptions->render(function (MemberDuplicateException $e) {
            return response()->json(
                app(\App\Services\MemberService::class)->formatDuplicateResponse($e->matches, $e->preservedInput),
                422,
            );
        });

        $exceptions->render(function (HouseholdConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        });

        $exceptions->render(function (HouseholdContactOverwriteException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'conflicts' => $e->conflicts,
                'confirm_overwrite_required' => true,
            ], 409);
        });

        $exceptions->render(function (MemberLifecycleTransitionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'missing' => $e->missing,
                'requires_approval' => $e->requiresApproval,
            ], 422);
        });
        $exceptions->render(function (TeamDashboardConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->codeKey,
                'current_version' => $e->currentVersion,
            ], 409);
        });
    })->create();
