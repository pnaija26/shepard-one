<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Services\MessageTemplateException;
use App\Services\MessageTemplateService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 10.3: versioned reusable message templates.
 */
class MessageTemplateController extends Controller
{
    public function __construct(
        private MessageTemplateService $templates,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->templates->list($request->user());

            return response()->json([
                'data' => $items->map(fn (MessageTemplate $item) => $this->templates->format($item))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $item = $this->templates->create($request->user(), $request->all());

            return response()->json([
                'data' => $this->templates->format($item),
            ], 201);
        } catch (MessageTemplateException $exception) {
            return $this->templateError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, MessageTemplate $messageTemplate): JsonResponse
    {
        try {
            $item = $this->templates->show($request->user(), $messageTemplate);

            return response()->json([
                'data' => $this->templates->format($item),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function updateDraft(Request $request, MessageTemplate $messageTemplate): JsonResponse
    {
        try {
            $item = $this->templates->updateDraft($request->user(), $messageTemplate, $request->all());

            return response()->json([
                'data' => $this->templates->format($item),
            ]);
        } catch (MessageTemplateException $exception) {
            return $this->templateError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function validateDefinition(Request $request, MessageTemplate $messageTemplate): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->templates->validate($request->user(), $messageTemplate),
            ]);
        } catch (MessageTemplateException $exception) {
            return $this->templateError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function preview(Request $request, MessageTemplate $messageTemplate): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->templates->preview($request->user(), $messageTemplate, $request->all()),
            ]);
        } catch (MessageTemplateException $exception) {
            return $this->templateError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function publish(Request $request, MessageTemplate $messageTemplate): JsonResponse
    {
        try {
            $item = $this->templates->publish($request->user(), $messageTemplate, $request->all());

            return response()->json([
                'data' => $this->templates->format($item),
            ]);
        } catch (MessageTemplateException $exception) {
            return $this->templateError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function retire(Request $request, MessageTemplate $messageTemplate): JsonResponse
    {
        try {
            $item = $this->templates->retire($request->user(), $messageTemplate);

            return response()->json([
                'data' => $this->templates->format($item),
            ]);
        } catch (MessageTemplateException $exception) {
            return $this->templateError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function templateError(MessageTemplateException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey(),
            'details' => $exception->details(),
        ], $exception->httpStatus());
    }
}
