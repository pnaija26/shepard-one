<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PrayerRequest;
use App\Services\PrayerRequestException;
use App\Services\PrayerRequestService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stories 8.3–8.4: prayer request submission and safe processing.
 */
class PrayerRequestController extends Controller
{
    public function __construct(
        private PrayerRequestService $prayers,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->prayers->listDiscoverable(
                $request->user(),
                $request->only(['category', 'confidentiality']),
            );

            return response()->json([
                'data' => $items->map(
                    fn (PrayerRequest $item) => $this->prayers->formatForActor($item, $request->user())
                )->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function myRequests(Request $request): JsonResponse
    {
        try {
            $items = $this->prayers->listMine($request->user());

            return response()->json([
                'data' => $items->map(
                    fn (PrayerRequest $item) => $this->prayers->formatForActor($item, $request->user())
                )->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $item = $this->prayers->submit($request->user(), $request->all());

            return response()->json([
                'data' => $this->prayers->formatForActor($item, $request->user()),
            ], 201);
        } catch (PrayerRequestException $exception) {
            return $this->prayerError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, PrayerRequest $prayerRequest): JsonResponse
    {
        try {
            $item = $this->prayers->show($request->user(), $prayerRequest);

            return response()->json([
                'data' => $this->prayers->formatForActor($item, $request->user()),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function updateConfidentiality(Request $request, PrayerRequest $prayerRequest): JsonResponse
    {
        try {
            $item = $this->prayers->updateConfidentiality($request->user(), $prayerRequest, $request->all());

            return response()->json([
                'data' => $this->prayers->formatForActor($item, $request->user()),
            ]);
        } catch (PrayerRequestException $exception) {
            return $this->prayerError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function withdraw(Request $request, PrayerRequest $prayerRequest): JsonResponse
    {
        try {
            $item = $this->prayers->withdraw($request->user(), $prayerRequest, $request->all());

            return response()->json([
                'data' => $this->prayers->formatForActor($item, $request->user()),
            ]);
        } catch (PrayerRequestException $exception) {
            return $this->prayerError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function assign(Request $request, PrayerRequest $prayerRequest): JsonResponse
    {
        try {
            $item = $this->prayers->assign($request->user(), $prayerRequest, $request->all());

            return response()->json([
                'data' => $this->prayers->formatForActor($item, $request->user()),
            ]);
        } catch (PrayerRequestException $exception) {
            return $this->prayerError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function acknowledge(Request $request, PrayerRequest $prayerRequest): JsonResponse
    {
        try {
            $item = $this->prayers->acknowledge($request->user(), $prayerRequest, $request->all());

            return response()->json([
                'data' => $this->prayers->formatForActor($item, $request->user()),
            ]);
        } catch (PrayerRequestException $exception) {
            return $this->prayerError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function recordUpdate(Request $request, PrayerRequest $prayerRequest): JsonResponse
    {
        try {
            $item = $this->prayers->recordUpdate($request->user(), $prayerRequest, $request->all());

            return response()->json([
                'data' => $this->prayers->formatForActor($item, $request->user()),
            ]);
        } catch (PrayerRequestException $exception) {
            return $this->prayerError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function escalate(Request $request, PrayerRequest $prayerRequest): JsonResponse
    {
        try {
            $item = $this->prayers->escalate($request->user(), $prayerRequest, $request->all());

            return response()->json([
                'data' => $this->prayers->formatForActor($item, $request->user()),
            ]);
        } catch (PrayerRequestException $exception) {
            return $this->prayerError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function markAnswered(Request $request, PrayerRequest $prayerRequest): JsonResponse
    {
        try {
            $item = $this->prayers->markAnswered($request->user(), $prayerRequest, $request->all());

            return response()->json([
                'data' => $this->prayers->formatForActor($item, $request->user()),
            ]);
        } catch (PrayerRequestException $exception) {
            return $this->prayerError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function close(Request $request, PrayerRequest $prayerRequest): JsonResponse
    {
        try {
            $item = $this->prayers->close($request->user(), $prayerRequest, $request->all());

            return response()->json([
                'data' => $this->prayers->formatForActor($item, $request->user()),
            ]);
        } catch (PrayerRequestException $exception) {
            return $this->prayerError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function publishToGroup(Request $request, PrayerRequest $prayerRequest): JsonResponse
    {
        try {
            $item = $this->prayers->publishToGroup($request->user(), $prayerRequest, $request->all());

            return response()->json([
                'data' => $this->prayers->formatForActor($item, $request->user()),
            ]);
        } catch (PrayerRequestException $exception) {
            return $this->prayerError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function prayerError(PrayerRequestException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey(),
            'details' => $exception->details(),
        ], $exception->httpStatus());
    }
}
