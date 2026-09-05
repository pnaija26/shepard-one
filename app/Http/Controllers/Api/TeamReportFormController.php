<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceTeam;
use App\Models\TeamReportForm;
use App\Services\TeamReportFormException;
use App\Services\TeamReportFormService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 5.7: configurable team report forms API.
 */
class TeamReportFormController extends Controller
{
    public function __construct(
        private TeamReportFormService $forms,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->forms->listForms($request->user());

            return response()->json([
                'data' => $items->map(fn (TeamReportForm $form) => $this->forms->formatForm($form))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $form = $this->forms->createForm($request->user(), $request->all());

            return response()->json(['data' => $this->forms->formatForm($form)], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        } catch (ValidationException $e) {
            throw $e;
        }
    }

    public function show(Request $request, TeamReportForm $teamReportForm): JsonResponse
    {
        try {
            $form = $this->forms->showForm($request->user(), $teamReportForm);

            return response()->json(['data' => $this->forms->formatForm($form)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function updateDraft(Request $request, TeamReportForm $teamReportForm): JsonResponse
    {
        try {
            $form = $this->forms->updateDraft($request->user(), $teamReportForm, $request->all());

            return response()->json(['data' => $this->forms->formatForm($form)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        } catch (TeamReportFormException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
                'details' => $e->details,
            ], $e->status);
        }
    }

    public function preview(Request $request, TeamReportForm $teamReportForm): JsonResponse
    {
        try {
            return response()->json(['data' => $this->forms->previewForm($request->user(), $teamReportForm)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function publish(Request $request, TeamReportForm $teamReportForm): JsonResponse
    {
        try {
            $form = $this->forms->publishForm($request->user(), $teamReportForm, $request->all());

            return response()->json(['data' => $this->forms->formatForm($form)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        } catch (ValidationException $e) {
            throw $e;
        }
    }

    public function teamForm(Request $request, ServiceTeam $serviceTeam): JsonResponse
    {
        try {
            $version = $this->forms->activeFormForTeamScoped($request->user(), $serviceTeam);

            return response()->json([
                'data' => $version === null ? null : [
                    'form_id' => $version->team_report_form_id,
                    'version' => $version->version,
                    'fields' => $version->fields ?? [],
                ],
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
