<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChurchDocument;
use App\Services\ChurchDocumentException;
use App\Services\ChurchDocumentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stories 14.1–14.2: protected church document API.
 */
class ChurchDocumentController extends Controller
{
    public function __construct(
        private ChurchDocumentService $documents,
    ) {
    }

    public function catalog(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->documents->catalog($request->user())]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->documents->list($request->user(), $request->only([
                'record_type',
                'record_id',
                'branch_id',
            ]));

            return response()->json([
                'data' => $items->map(fn (ChurchDocument $item) => $this->documents->format($item, includeJobs: false))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $item = $this->documents->upload($request->user(), $request->all());

            return response()->json(['data' => $this->documents->format($item)], 201);
        } catch (ChurchDocumentException $exception) {
            return $this->documentError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, ChurchDocument $churchDocument): JsonResponse
    {
        try {
            $item = $this->documents->show($request->user(), $churchDocument);

            return response()->json(['data' => $this->documents->format($item)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function showByReference(Request $request, string $reference): JsonResponse
    {
        try {
            $item = $this->documents->resolveByReference($request->user(), $reference);

            return response()->json(['data' => $this->documents->format($item)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function versions(Request $request, ChurchDocument $churchDocument): JsonResponse
    {
        try {
            $items = $this->documents->listVersions($request->user(), $churchDocument);

            return response()->json([
                'data' => $items->map(fn ($version) => $this->documents->formatVersion($version))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function replaceVersion(Request $request, ChurchDocument $churchDocument): JsonResponse
    {
        try {
            $item = $this->documents->replaceVersion($request->user(), $churchDocument, $request->all());

            return response()->json(['data' => $this->documents->format($item)]);
        } catch (ChurchDocumentException $exception) {
            return $this->documentError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function issueAccess(Request $request, ChurchDocument $churchDocument): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->documents->issueAccess($request->user(), $churchDocument, $request->all()),
            ]);
        } catch (ChurchDocumentException $exception) {
            return $this->documentError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function download(Request $request, ChurchDocument $churchDocument): StreamedResponse|JsonResponse
    {
        try {
            $token = (string) $request->query('token', '');
            $versionNumber = $request->query('version_number');
            $file = $this->documents->deliver(
                $request->user(),
                $churchDocument,
                $token,
                $versionNumber !== null ? (int) $versionNumber : null,
            );

            return response()->streamDownload(
                static function () use ($file): void {
                    echo $file['content'];
                },
                $file['filename'],
                [
                    'Content-Type' => $file['mime'],
                    'Content-Disposition' => $file['disposition'] . '; filename="' . $file['filename'] . '"',
                    'X-Content-Type-Options' => 'nosniff',
                ],
                $file['disposition'] === 'inline' ? 'inline' : 'attachment',
            );
        } catch (ChurchDocumentException $exception) {
            return $this->documentError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function requestArchive(Request $request, ChurchDocument $churchDocument): JsonResponse
    {
        try {
            $item = $this->documents->requestArchive($request->user(), $churchDocument);

            return response()->json(['data' => $this->documents->format($item, includeJobs: false)]);
        } catch (ChurchDocumentException $exception) {
            return $this->documentError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function destroy(Request $request, ChurchDocument $churchDocument): JsonResponse
    {
        try {
            $this->documents->attemptDelete($request->user(), $churchDocument);

            return response()->json(['message' => 'Deleted.']);
        } catch (ChurchDocumentException $exception) {
            return $this->documentError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function documentError(ChurchDocumentException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey,
            'details' => $exception->details,
        ], $exception->status);
    }
}
