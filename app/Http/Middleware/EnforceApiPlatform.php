<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use App\Services\ApiContractService;
use App\Services\ApiPlatformException;
use App\Services\ApiPlatformService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Story 15.3: correlation IDs, rate limits, scope checks, and response envelopes.
 */
class EnforceApiPlatform
{
    public function __construct(
        private ApiPlatformService $platform,
        private ApiContractService $contract,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $this->platform->correlationId($request);
        $request->attributes->set('correlation_id', $correlationId);

        /** @var ApiClient|null $client */
        $client = $request->attributes->get('api_client');
        $user = $request->user();

        try {
            $this->platform->enforceRateLimit($request, $client, $user);

            $endpoint = $this->contract->endpointForRoute($request->route()?->getName());
            if ($endpoint !== null) {
                $this->platform->assertScopes(
                    $user,
                    $client,
                    $endpoint['scopes'] ?? [],
                    $client?->branch_id ?? $user?->branch_id,
                );
            }

            $response = $next($request);
        } catch (ApiPlatformException $exception) {
            $response = response()->json(
                $this->platform->errorResponse($exception->codeKey, $correlationId, $exception->getCode()),
                $exception->getCode(),
            );
        } catch (AuthorizationException) {
            $this->platform->logOutcome($request, $correlationId, 403, 'denied', $client, $user, 'forbidden');
            $response = response()->json($this->platform->errorResponse('forbidden', $correlationId, 403), 403);
        }

        $errorCode = null;
        if (! $response->isSuccessful() && method_exists($response, 'getData')) {
            $payload = $response->getData(true);
            $errorCode = is_array($payload) ? ($payload['code'] ?? null) : null;
        }

        $this->platform->logOutcome(
            request: $request,
            correlationId: $correlationId,
            statusCode: $response->getStatusCode(),
            outcome: $response->isSuccessful() ? 'allowed' : 'denied',
            client: $client,
            user: $user,
            errorCode: $errorCode,
        );

        return $response->header(config('api_platform.correlation_header', 'X-Correlation-Id'), $correlationId)
            ->header('X-Api-Version', (string) config('api_platform.version', '1'));
    }
}
