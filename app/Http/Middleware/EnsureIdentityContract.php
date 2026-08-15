<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdentityContract
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if user is authenticated
        if (!$request->user()) {
            return redirect()->route('login');
        }
        
        // Check if user has a privileged role that requires MFA
        if ($request->user()->isPrivileged() && !$request->user()->hasMfaEnrolled()) {
            // Redirect to MFA setup page for privileged users without MFA
            return redirect()->route('mfa.setup');
        }
        
        // Continue with the request normally
        return $next($request);
    }
}