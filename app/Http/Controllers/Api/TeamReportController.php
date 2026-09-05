<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceTeam;
use App\Models\TeamReport;
use App\Services\TeamReportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 5.6: team report submission and review API.
 */
class TeamReportController extends Controller
{
    public function __construct(
        private TeamReportService $reports,
    ) {
    }

    public function index(Request $request, ServiceTeam $serviceTeam): JsonResponse
    {
        try {
            $items = $this->reports->listReports($request->user(), $serviceTeam, $request->only('status'));

            return response()->json([
                'data' => $items->map(fn (TeamReport $report) => $this->reports->formatReport($report))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function metrics(Request $request, ServiceTeam $serviceTeam): JsonResponse
    {
        try {
            return response()->json(['data' => $this->reports->consolidatedMetrics($request->user(), $serviceTeam)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request, ServiceTeam $serviceTeam): JsonResponse
    {
        try {
            $report = $this->reports->createDraft($request->user(), $serviceTeam, $request->all());

            return response()->json(['data' => $this->reports->formatReport($report)], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, TeamReport $teamReport): JsonResponse
    {
        try {
            $report = $this->reports->showReport($request->user(), $teamReport);

            return response()->json(['data' => $this->reports->formatReport($report)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function update(Request $request, TeamReport $teamReport): JsonResponse
    {
        try {
            $report = $this->reports->saveDraft($request->user(), $teamReport, $request->all());

            return response()->json(['data' => $this->reports->formatReport($report)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function submit(Request $request, TeamReport $teamReport): JsonResponse
    {
        try {
            $report = $this->reports->submitReport($request->user(), $teamReport);

            return response()->json(['data' => $this->reports->formatReport($report)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function review(Request $request, TeamReport $teamReport): JsonResponse
    {
        try {
            $report = $this->reports->reviewReport($request->user(), $teamReport, $request->all());

            return response()->json(['data' => $this->reports->formatReport($report)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
