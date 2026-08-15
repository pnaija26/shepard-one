<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class AuthService
{
    /**
     * Authenticate a user with the specified credentials.
     *
     * @param  array  $credentials
     * @return bool
     */
    public function authenticate(array $credentials): bool
    {
        // Validate credentials before attempting authentication
        if (empty($credentials['email']) || empty($credentials['password'])) {
            Log::warning('Authentication attempt with missing credentials', [
                'ip_address' => request()->ip(),
            ]);
            
            return false;
        }

        // Attempt to authenticate using Laravel's built-in auth system
        $authenticated = Auth::attempt($credentials);
        
        if ($authenticated) {
            // Regenerate session ID to prevent session fixation attacks
            request()->session()->regenerate();
            
            Log::info('User authenticated successfully', [
                'user_id' => Auth::id(),
                'ip_address' => request()->ip(),
            ]);
            
            return true;
        }

        Log::warning('Authentication failed', [
            'email' => $credentials['email'],
            'ip_address' => request()->ip(),
        ]);
        
        return false;
    }

    /**
     * Get the authenticated user.
     *
     * @return \App\Models\User|null
     */
    public function getUser()
    {
        return Auth::user();
    }

    /**
     * Check if a user is authenticated.
     *
     * @return bool
     */
    public function check(): bool
    {
        return Auth::check();
    }

    /**
     * Logout the current user.
     *
     * @return void
     */
    public function logout(): void
    {
        $user = Auth::user();
        
        if ($user) {
            Log::info('User logged out', [
                'user_id' => $user->id,
                'ip_address' => request()->ip(),
            ]);
        }
        
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
    }

    /**
     * Get the minimum identity context for the authenticated user.
     *
     * @return array
     */
    public function getIdentityContext(): array
    {
        if (!$this->check()) {
            return [];
        }

        $user = $this->getUser();
        
        // Define the minimum required identity context
        $requiredFields = config('identity.contracts.minimum_identity_context', [
            'user_id',
            'name',
            'email',
            'roles',
            'permissions'
        ]);
        
        $context = [];
        
        foreach ($requiredFields as $field) {
            if (isset($user->{$field})) {
                $context[$field] = $user->{$field};
            }
        }
        
        // Add session context
        $context['session_id'] = request()->session()->getId();
        $context['expires_at'] = now()->addMinutes(config('session.lifetime'));
        $context['device_info'] = $this->getDeviceInfo();
        $context['ip_address'] = request()->ip();
        
        return $context;
    }

    /**
     * Get device information.
     *
     * @return array
     */
    private function getDeviceInfo(): array
    {
        return [
            'user_agent' => request()->userAgent(),
            'platform' => $this->getPlatform(),
            'browser' => $this->getBrowser(),
        ];
    }

    /**
     * Get platform information.
     *
     * @return string
     */
    private function getPlatform(): string
    {
        $userAgent = request()->userAgent();
        
        if (strpos($userAgent, 'Windows') !== false) {
            return 'Windows';
        } elseif (strpos($userAgent, 'Mac') !== false) {
            return 'macOS';
        } elseif (strpos($userAgent, 'Linux') !== false) {
            return 'Linux';
        } elseif (strpos($userAgent, 'Android') !== false) {
            return 'Android';
        } elseif (strpos($userAgent, 'iPhone') !== false || strpos($userAgent, 'iPad') !== false) {
            return 'iOS';
        }
        
        return 'Unknown';
    }

    /**
     * Get browser information.
     *
     * @return string
     */
    private function getBrowser(): string
    {
        $userAgent = request()->userAgent();
        
        if (strpos($userAgent, 'Chrome') !== false) {
            return 'Chrome';
        } elseif (strpos($userAgent, 'Firefox') !== false) {
            return 'Firefox';
        } elseif (strpos($userAgent, 'Safari') !== false) {
            return 'Safari';
        } elseif (strpos($userAgent, 'Edge') !== false) {
            return 'Edge';
        }
        
        return 'Unknown';
    }

    /**
     * Check if the user has the required permissions.
     *
     * @param  array|string  $permissions
     * @return bool
     */
    public function can($permissions): bool
    {
        if (!$this->check()) {
            return false;
        }

        $user = $this->getUser();
        
        // If user has admin role, they can do everything
        if (isset($user->roles) && in_array('admin', $user->roles)) {
            return true;
        }
        
        // Check if user has the required permissions
        $userPermissions = $user->permissions ?? [];
        
        if (is_array($permissions)) {
            foreach ($permissions as $permission) {
                if (!in_array($permission, $userPermissions)) {
                    return false;
                }
            }
            return true;
        } else {
            return in_array($permissions, $userPermissions);
        }
    }
}