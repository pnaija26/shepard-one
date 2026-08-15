<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class MfaController extends Controller
{
    /**
     * Show the MFA setup page.
     *
     * @return \Illuminate\View\View
     */
    public function showSetup()
    {
        // Check if user is authenticated
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        $user = Auth::user();
        
        // If user already has MFA enabled, redirect to dashboard
        if ($user->hasMfaEnrolled()) {
            return redirect()->route('dashboard');
        }
        
        // Generate QR code and secret for setup
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();
        $qrCodeUrl = $google2fa->getQRCodeImageAsDataUrl(
            'ShepardOne',
            $user->email,
            $secret
        );
        
        return view('auth.mfa-setup', [
            'secret' => $secret,
            'qrCodeUrl' => $qrCodeUrl
        ]);
    }

    /**
     * Setup MFA for the user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function setup(Request $request)
    {
        // Validate request
        $request->validate([
            'otp' => 'required|string|size:6',
            'secret' => 'required|string'
        ]);

        $user = Auth::user();
        $google2fa = new Google2FA();
        
        // Verify the OTP code
        $valid = $google2fa->verifyKey($request->secret, $request->otp);
        
        if (!$valid) {
            throw ValidationException::withMessages([
                'otp' => ['The provided OTP code is invalid.'],
            ]);
        }
        
        // Enable MFA for the user
        $user->update([
            'mfa_secret' => $request->secret,
            'has_mfa_enrolled' => true
        ]);
        
        return redirect()->route('dashboard')->with('message', 'MFA has been enabled successfully.');
    }

    /**
     * Show the MFA verification page.
     *
     * @return \Illuminate\View\View
     */
    public function showVerify()
    {
        // Check if user is authenticated
        if (!Auth::check()) {
            return redirect()->route('login');
        }
        
        $user = Auth::user();
        
        // If user doesn't have MFA enabled, redirect to dashboard
        if (!$user->hasMfaEnrolled()) {
            return redirect()->route('dashboard');
        }
        
        return view('auth.mfa-verify');
    }

    /**
     * Verify the MFA code.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function verify(Request $request)
    {
        // Validate request
        $request->validate([
            'otp' => 'required|string|size:6'
        ]);

        $user = Auth::user();
        $google2fa = new Google2FA();
        
        // Verify the OTP code
        $valid = $google2fa->verifyKey($user->mfa_secret, $request->otp);
        
        if (!$valid) {
            throw ValidationException::withMessages([
                'otp' => ['The provided OTP code is invalid.'],
            ]);
        }
        
        // Store MFA verification in session
        session(['mfa_verified' => true]);
        
        return redirect()->route('dashboard');
    }
}