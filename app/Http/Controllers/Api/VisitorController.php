<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Visitor;
use App\Services\VisitorDuplicateException;
use App\Services\VisitorService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Story 3.1: visitor capture and follow-up history API.
 */
class VisitorController extends Controller
{
    public function __construct(
        private VisitorService $visitors,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $records = $this->visitors->listFor($request->user(), $request->only(['search']));

            return response()->json([
                'data' => $records->map(
                    fn (Visitor $visitor) => $this->visitors->formatForList($visitor, $request->user()),
                )->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $visitor = $this->visitors->capture(
                $request->user(),
                $request->all(),
                $request->boolean('force'),
            );

            return response()->json([
                'data' => $this->visitors->formatForViewer($visitor, $request->user()),
            ], 201);
        } catch (VisitorDuplicateException $e) {
            return response()->json(
                $this->visitors->formatDuplicateResponse($e->matches, $e->preservedInput),
                422,
            );
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, Visitor $visitor): JsonResponse
    {
        try {
            $visitor = $this->visitors->findFor($request->user(), $visitor->id);

            return response()->json([
                'data' => $this->visitors->formatForViewer($visitor, $request->user()),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function recordVisit(Request $request, Visitor $visitor): JsonResponse
    {
        try {
            $visitor = $this->visitors->recordReturningVisit($request->user(), $visitor, $request->all());

            return response()->json([
                'data' => $this->visitors->formatForViewer($visitor, $request->user()),
            ], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        try {
            $export = $this->visitors->export($request->user(), $request->only(['search']));

            return response()->streamDownload(
                static function () use ($export): void {
                    echo $export['content'];
                },
                $export['filename'],
                ['Content-Type' => 'text/csv'],
            );
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
