<?php

namespace App\Services;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Story 15.3: executable API contract documentation and drift detection.
 */
class ApiContractService
{
    /**
     * @return array<string, mixed>
     */
    public function contract(): array
    {
        return [
            'version' => config('api_platform.version', '1'),
            'generated_at' => now()->toIso8601String(),
            'deprecation_policy' => config('api_platform.deprecation_policy', []),
            'auth_methods' => config('api_platform.auth_methods', []),
            'rate_limits' => config('api_platform.rate_limits', []),
            'pagination' => config('api_platform.pagination', []),
            'error_codes' => config('api_platform.error_codes', []),
            'schemas' => config('api_platform.schemas', []),
            'endpoints' => config('api_platform.endpoints', []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validateExecutableContract(): array
    {
        $issues = [];

        foreach (config('api_platform.endpoints', []) as $endpoint) {
            $name = (string) ($endpoint['name'] ?? '');
            if ($name === '') {
                $issues[] = ['type' => 'missing_name', 'endpoint' => $endpoint];
                continue;
            }

            $route = RouteFacade::getRoutes()->getByName($name);
            if ($route === null) {
                $issues[] = ['type' => 'missing_route', 'name' => $name];

                continue;
            }

            if (! $this->routeMatchesContract($route, $endpoint)) {
                $issues[] = [
                    'type' => 'route_mismatch',
                    'name' => $name,
                    'expected_method' => $endpoint['method'] ?? null,
                    'actual_methods' => $route->methods(),
                    'expected_path' => $endpoint['path'] ?? null,
                    'actual_uri' => '/' . ltrim($route->uri(), '/'),
                ];
            }
        }

        return [
            'valid' => $issues === [],
            'issue_count' => count($issues),
            'issues' => $issues,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function endpointForRoute(?string $routeName): ?array
    {
        if ($routeName === null) {
            return null;
        }

        foreach (config('api_platform.endpoints', []) as $endpoint) {
            if (($endpoint['name'] ?? null) === $routeName) {
                return $endpoint;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $endpoint
     */
    private function routeMatchesContract(Route $route, array $endpoint): bool
    {
        $expectedMethod = strtoupper((string) ($endpoint['method'] ?? ''));
        $expectedPath = '/' . ltrim((string) ($endpoint['path'] ?? ''), '/');
        $actualPath = '/' . ltrim($route->uri(), '/');

        return in_array($expectedMethod, $route->methods(), true)
            && $expectedPath === $actualPath;
    }
}
