<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Controllers\Api\MemberMovementController;
use App\Http\Controllers\Api\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user', [AuthController::class, 'user']);
    });
});

Route::middleware('auth:sanctum')->prefix('org')->group(function () {
    Route::apiResource('organizations', OrganizationController::class);

    // Story 1.5: cross-branch identity movement (pending -> approved/rejected,
    // applied on the effective date). Scope enforced server-side per request.
    Route::get('/movements', [MemberMovementController::class, 'index']);
    Route::post('/movements', [MemberMovementController::class, 'store']);
    Route::get('/people', [MemberMovementController::class, 'people']);
    Route::get('/movements/{movement}', [MemberMovementController::class, 'show']);
    Route::post('/movements/{movement}/approve', [MemberMovementController::class, 'approve']);
    Route::post('/movements/{movement}/reject', [MemberMovementController::class, 'reject']);
});

// MFA routes for API
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/mfa/setup', [MfaController::class, 'setup']);
    Route::post('/mfa/verify', [MfaController::class, 'verify']);
});