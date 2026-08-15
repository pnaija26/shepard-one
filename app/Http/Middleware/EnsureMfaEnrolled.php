<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMfaEnrolled
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if user is authenticated and has MFA enrolled
        if ($request->user() && $request->user()->has_mfa_enrolled) {
            // User has MFA enrolled, continue with request
            return $next($request);
        }
        
        // If user doesn't have MFA enrolled but should (privileged role)
        // Redirect to MFA enrollment page or deny access based on policy
        if ($request->user() && $this->isPrivilegedRole($request->user())) {
            // For now, redirect to MFA setup - in a real implementation,
            // this would redirect to the MFA enrollment flow
            return redirect()->route('mfa.setup');
        }
        
        // If not privileged or already enrolled, proceed normally
        return $next($request);
    }
    
    /**
     * Check if user has a privileged role that requires MFA
     */
    private function isPrivilegedRole($user): bool
    {
        // In a real implementation, this would check the user's roles/permissions
        // For now, we'll assume any admin role or specific role requires MFA
        $privilegedRoles = ['admin', 'hq_admin', 'system_admin'];
        
        if (isset($user->roles) && is_array($user->roles)) {
            foreach ($user->roles as $role) {
                if (in_array(strtolower($role), $privilegedRoles)) {
                    return true;
                }
            }
        }
        
        return false;
    }
}