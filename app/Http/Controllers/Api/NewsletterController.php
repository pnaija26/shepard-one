<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Newsletter;
use App\Services\NewsletterException;
use App\Services\NewsletterService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 10.5: build, approve, schedule, and measure newsletters.
 */
class NewsletterController extends Controller
{
    public function __construct(
        private NewsletterService $newsletters,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->newsletters->list($request->user());

            return response()->json([
                'data' => $items->map(fn (Newsletter $n) => $this->newsletters->format($n))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $item = $this->newsletters->create($request->user(), $request->all());

            return response()->json(['data' => $this->newsletters->format($item)], 201);
        } catch (NewsletterException $exception) {
            return $this->nlError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, Newsletter $newsletter): JsonResponse
    {
        try {
            $item = $this->newsletters->show($request->user(), $newsletter);

            return response()->json(['data' => $this->newsletters->format($item)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function updateDraft(Request $request, Newsletter $newsletter): JsonResponse
    {
        try {
            $item = $this->newsletters->updateDraft($request->user(), $newsletter, $request->all());

            return response()->json(['data' => $this->newsletters->format($item)]);
        } catch (NewsletterException $exception) {
            return $this->nlError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function validateDefinition(Request $request, Newsletter $newsletter): JsonResponse
    {
        try {
            return response()->json(['data' => $this->newsletters->validate($request->user(), $newsletter)]);
        } catch (NewsletterException $exception) {
            return $this->nlError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function preview(Request $request, Newsletter $newsletter): JsonResponse
    {
        try {
            return response()->json(['data' => $this->newsletters->preview($request->user(), $newsletter, $request->all())]);
        } catch (NewsletterException $exception) {
            return $this->nlError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function sendTest(Request $request, Newsletter $newsletter): JsonResponse
    {
        try {
            return response()->json(['data' => $this->newsletters->sendTest($request->user(), $newsletter, $request->all())]);
        } catch (NewsletterException $exception) {
            return $this->nlError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function submit(Request $request, Newsletter $newsletter): JsonResponse
    {
        try {
            $item = $this->newsletters->submitForApproval($request->user(), $newsletter);

            return response()->json(['data' => $this->newsletters->format($item)]);
        } catch (NewsletterException $exception) {
            return $this->nlError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function approve(Request $request, Newsletter $newsletter): JsonResponse
    {
        try {
            $item = $this->newsletters->approve($request->user(), $newsletter, $request->all());

            return response()->json(['data' => $this->newsletters->format($item)]);
        } catch (NewsletterException $exception) {
            return $this->nlError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function processDue(Request $request): JsonResponse
    {
        try {
            $branchId = $request->query('branch_id');

            return response()->json([
                'data' => $this->newsletters->processDue(
                    $request->user(),
                    $branchId !== null ? (int) $branchId : null,
                ),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function recordEvent(Request $request, Newsletter $newsletter): JsonResponse
    {
        try {
            $event = $this->newsletters->recordProviderEvent($request->user(), $newsletter, $request->all());

            return response()->json([
                'data' => [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'provider' => $event->provider,
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                ],
            ], 201);
        } catch (NewsletterException $exception) {
            return $this->nlError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function analytics(Request $request, Newsletter $newsletter): JsonResponse
    {
        try {
            return response()->json(['data' => $this->newsletters->analytics($request->user(), $newsletter)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function nlError(NewsletterException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey(),
            'details' => $exception->details(),
        ], $exception->httpStatus());
    }
}
