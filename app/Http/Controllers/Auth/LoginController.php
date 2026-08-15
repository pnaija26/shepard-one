<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class LoginController extends Controller
{
    /**
     * Show the login form.
     *
     * @return \Illuminate\View\View
     */
    public function showLoginForm()
    {
        return view('auth.login');
    }

    /**
     * Handle a login request to the application.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        // Validate the request
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Get the identity provider from config
        $provider = config('identity.providers.default');
        
        // Log the authentication attempt
        Log::info('Authentication attempt', [
            'email' => $request->email,
            'provider' => $provider,
            'ip_address' => $request->ip(),
        ]);

        // Attempt to authenticate using the configured provider
        $credentials = $request->only('email', 'password');
        
        if (Auth::attempt($credentials)) {
            // Authentication successful
            $request->session()->regenerate();
            
            Log::info('Authentication successful', [
                'user_id' => Auth::id(),
                'provider' => $provider,
            ]);
            
            return redirect()->intended('/dashboard');
        }

        // Authentication failed - log the failure and return error
        Log::warning('Authentication failed', [
            'email' => $request->email,
            'provider' => $provider,
            'ip_address' => $request->ip(),
        ]);

        // Return with error message
        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ]);
    }

    /**
     * Redirect to the identity provider for authentication.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function redirectToProvider(Request $request)
    {
        // Get the configured identity provider
        $provider = config('identity.providers.default');
        
        // For OIDC, redirect to the provider's authorization endpoint
        if ($provider === 'oidc') {
            return $this->redirectToOidcProvider($request);
        }
        
        // Default to local authentication
        return redirect()->route('login');
    }

    /**
     * Handle the OAuth callback from identity provider.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function handleProviderCallback(Request $request)
    {
        // Get the configured identity provider
        $provider = config('identity.providers.default');
        
        // Handle OIDC callback
        if ($provider === 'oidc') {
            return $this->handleOidcCallback($request);
        }
        
        // Default to local authentication
        return redirect()->route('login');
    }

    /**
     * Redirect to OIDC provider for authentication.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    private function redirectToOidcProvider(Request $request)
    {
        // This would redirect to the actual OIDC provider
        // Implementation depends on the specific OIDC library used
        
        // For now, redirect back to login form
        return redirect()->route('login');
    }

    /**
     * Handle callback from OIDC provider.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    private function handleOidcCallback(Request $request)
    {
        // This would handle the actual OIDC callback
        // Implementation depends on the specific OIDC library used
        
        // For now, redirect back to login form
        return redirect()->route('login');
    }
}