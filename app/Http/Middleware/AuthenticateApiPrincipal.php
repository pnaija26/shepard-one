<?php

namespace App\Http\Middleware;

use App\Services\ApiPlatformException;
use App\Services\ApiPlatformService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Story 15.3: authenticate session or machine principal for versioned APIs.
 */
class AuthenticateApiPrincipal
{
    public function __construct(
        private ApiPlatformService $platform,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $credential = $this->extractCredential($request);

        if ($credential !== null) {
            try {
                $client = $this->platform->authenticateClient($credential, $request);
                $request->attributes->set('api_client', $client);
                Auth::setUser($client->user);
                $request->setUserResolver(fn () => $client->user);

                return $next($request);
            } catch (ApiPlatformException $exception) {
                $correlationId = $this->platform->correlationId($request);

                return response()
                    ->json($this->platform->errorResponse($exception->codeKey, $correlationId, $exception->getCode()), $exception->getCode())
                    ->header(config('api_platform.correlation_header', 'X-Correlation-Id'), $correlationId);
            }
        }

        if (! $request->user('sanctum')) {
            $token = $request->bearerToken();
            if (is_string($token) && $token !== '' && ! str_starts_with($token, 'cli_')) {
                $accessToken = PersonalAccessToken::findToken($token);
                if ($accessToken !== null) {
                    Auth::setUser($accessToken->tokenable);
                    $request->setUserResolver(fn () => $accessToken->tokenable);
                }
            }
        }

        if (! $request->user()) {
            $correlationId = $this->platform->correlationId($request);
            $this->platform->logOutcome($request, $correlationId, 401, 'denied', errorCode: 'unauthenticated');

            return response()
                ->json($this->platform->errorResponse('unauthenticated', $correlationId, 401), 401)
                ->header(config('api_platform.correlation_header', 'X-Correlation-Id'), $correlationId);
        }

        return $next($request);
    }

    private function extractCredential(Request $request): ?string
    {
        $authorization = (string) $request->header('Authorization', '');
        if (str_starts_with($authorization, 'Bearer ')) {
            $token = trim(substr($authorization, 7));
            if (str_starts_with($token, 'cli_')) {
                return $token;
            }
        }

        $apiKey = $request->header('X-Api-Key');
        if (is_string($apiKey) && $apiKey !== '') {
            return $apiKey;
        }

        return null;
    }
}
