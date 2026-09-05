<?php

namespace App\Providers;

use App\Services\AuthorizationService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Story 1.6 AC2: every Gate check funnels through AuthorizationService.
        Gate::before(function ($user, string $ability, ...$arguments) {
            if (! is_string($ability) || ! str_contains($ability, '.')) {
                return null;
            }

            if (! array_key_exists($ability, config('authz.actions', []))) {
                return null;
            }

            $orgId = isset($arguments[0]) ? (int) $arguments[0] : null;
            $context = is_array($arguments[1] ?? null) ? $arguments[1] : [];

            return app(AuthorizationService::class)->allows($user, $ability, $orgId, $context);
        });
    }
}
