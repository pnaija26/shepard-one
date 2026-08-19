<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\CallbackController;
use App\Http\Controllers\Auth\MfaController;

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

// Vue history route; API data remains protected by Sanctum.
Route::get('/dashboard', function () {
    return view('dashboard');
})->name('dashboard');

// SPA fallback: serve the Vue app shell for any other GET path (history mode),
// so deep links like /organizations work on direct visit and refresh.
// API routes live under the /api prefix and are registered first, so they win.
Route::get('/{any}', function () {
    return view('dashboard');
})->where('any', '.*')->name('spa.fallback');