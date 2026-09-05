<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportExportException;
use App\Services\ReportExportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Story 13.4: authorized report export API.
 */
class ReportExportController extends Controller
{
    public function __construct(
        private ReportExportService $exports,
    ) {
    }

    public function catalog(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->exports->catalog($request->user())]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $result = $this->exports->request($request->user(), $request->all());

            return response()->json([
                'data' => $result,
            ], ($result['async'] ?? false) ? 202 : 200);
        } catch (ReportExportException $exception) {
            return $this->exportError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function status(Request $request, string $reference): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->exports->status($request->user(), $reference),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function download(Request $request, string $reference): StreamedResponse|JsonResponse
    {
        try {
            $token = (string) $request->query('token', '');
            $file = $this->exports->download($request->user(), $reference, $token);

            return response()->streamDownload(
                static function () use ($file): void {
                    echo $file['content'];
                },
                $file['filename'],
                ['Content-Type' => $file['mime']],
            );
        } catch (ReportExportException $exception) {
            return $this->exportError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function exportError(ReportExportException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey,
            'details' => $exception->details,
        ], $exception->status);
    }
}
