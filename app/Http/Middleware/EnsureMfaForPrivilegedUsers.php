<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureMfaForPrivilegedUsers
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Check if user is authenticated
        if (Auth::check()) {
            $user = Auth::user();
            
            // If user is privileged and MFA is required but not verified
            if ($user->isPrivileged() && $user->hasMfaEnrolled() && !session('mfa_verified')) {
                // Redirect to MFA verification page if not already there
                if (!$request->routeIs('mfa.verify') && !$request->routeIs('mfa.setup')) {
                    return redirect()->route('mfa.verify');
                }
            }
        }
        
        return $next($request);
    }
}