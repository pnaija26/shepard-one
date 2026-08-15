<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\CallbackController;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Controllers\Auth\AuthController;

// Public routes
Route::get('/', function () {
    return view('welcome');
})->name('home');

// Authentication routes
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LogoutController::class, 'logout'])->name('logout');

// OAuth callback routes
Route::get('/auth/callback', [CallbackController::class, 'handle'])->name('auth.callback');

// Redirect to identity provider for authentication
Route::get('/auth/redirect', [LoginController::class, 'redirectToProvider'])->name('auth.redirect');

// MFA routes
Route::get('/mfa/setup', [MfaController::class, 'showSetup'])->name('mfa.setup');
Route::post('/mfa/setup', [MfaController::class, 'setup']);
Route::get('/mfa/verify', [MfaController::class, 'showVerify'])->name('mfa.verify');
Route::post('/mfa/verify', [MfaController::class, 'verify']);

// API routes for authentication
Route::prefix('api')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/user', [AuthController::class, 'user'])->middleware('auth:sanctum');
});

// Protected routes - require valid authentication
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');
});