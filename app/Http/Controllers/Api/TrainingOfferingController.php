<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrainingEnrolment;
use App\Models\TrainingOffering;
use App\Services\TrainingOfferingException;
use App\Services\TrainingOfferingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 6.3: training and discipleship offerings API.
 */
class TrainingOfferingController extends Controller
{
    public function __construct(
        private TrainingOfferingService $training,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->training->listOfferings($request->user(), $request->only('status', 'course_type'));

            return response()->json([
                'data' => $items->map(fn (TrainingOffering $offering) => $this->training->formatOffering($offering, $request->user()))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $offering = $this->training->createOffering($request->user(), $request->all());

            return response()->json([
                'data' => $this->training->formatOffering($offering, $request->user()),
            ], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, TrainingOffering $trainingOffering): JsonResponse
    {
        try {
            $offering = $this->training->showOffering($request->user(), $trainingOffering);

            return response()->json([
                'data' => $this->training->formatOffering($offering, $request->user()),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function update(Request $request, TrainingOffering $trainingOffering): JsonResponse
    {
        try {
            $offering = $this->training->updateDraft($request->user(), $trainingOffering, $request->all());

            return response()->json([
                'data' => $this->training->formatOffering($offering, $request->user()),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function publish(Request $request, TrainingOffering $trainingOffering): JsonResponse
    {
        try {
            $offering = $this->training->publishOffering($request->user(), $trainingOffering);

            return response()->json([
                'data' => $this->training->formatOffering($offering, $request->user()),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function enrol(Request $request, TrainingOffering $trainingOffering): JsonResponse
    {
        try {
            $enrolment = $this->training->requestEnrolment($request->user(), $trainingOffering, $request->all());

            return response()->json([
                'data' => $this->training->formatEnrolment($enrolment, $request->user()),
            ], 201);
        } catch (TrainingOfferingException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->codeKey(),
                'details' => $exception->details(),
            ], $exception->httpStatus());
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function enrolments(Request $request, TrainingOffering $trainingOffering): JsonResponse
    {
        try {
            $items = $this->training->listEnrolments($request->user(), $trainingOffering);

            return response()->json([
                'data' => $items->map(fn (TrainingEnrolment $enrolment) => $this->training->formatEnrolment($enrolment, $request->user()))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function showEnrolment(Request $request, TrainingEnrolment $trainingEnrolment): JsonResponse
    {
        try {
            $enrolment = $this->training->showEnrolment($request->user(), $trainingEnrolment);

            return response()->json([
                'data' => $this->training->formatEnrolment($enrolment, $request->user()),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
