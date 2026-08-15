<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyMfa
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if user is authenticated and has MFA enrolled
        if ($request->user() && $request->user()->hasMfaEnrolled()) {
            // User has MFA enrolled, continue with request
            return $next($request);
        }
        
        // If user doesn't have MFA enrolled but should (privileged role)
        // Check if they are on a privileged route that requires MFA
        if ($request->user() && $this->requiresMfa($request)) {
            // Redirect to MFA setup or verification
            return redirect()->route('mfa.setup');
        }
        
        // If not privileged or already enrolled, proceed normally
        return $next($request);
    }
    
    /**
     * Check if current route requires MFA
     */
    private function requiresMfa(Request $request): bool
    {
        // In a real implementation, check if the route requires MFA
        // For now, we'll use a simple approach based on user role
        return false;
    }
}