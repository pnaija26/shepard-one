<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'roles',
        'branch_id',
        'has_mfa_enrolled',
        'mfa_secret',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'mfa_secret'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'roles' => 'array',
        'has_mfa_enrolled' => 'boolean',
    ];

    /**
     * The branch this user is assigned to (Story 1.4).
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    /**
     * Church-wide scope: the user has no branch assignment and may see
     * consolidated data across all branches (HQ view).
     */
    public function isChurchWide(): bool
    {
        return $this->branch_id === null;
    }

    /**
     * Branch-scoped access: the user is assigned to exactly one branch.
     */
    public function hasBranchScope(): bool
    {
        return $this->branch_id !== null;
    }
    
    /**
     * Check if user has MFA enrolled
     */
    public function hasMfaEnrolled(): bool
    {
        return $this->has_mfa_enrolled ?? false;
    }
    
    /**
     * Check if user has a privileged role
     */
    public function isPrivileged(): bool
    {
        $privilegedRoles = ['admin', 'hq_admin', 'system_admin'];
        
        if (isset($this->roles) && is_array($this->roles)) {
            foreach ($this->roles as $role) {
                if (in_array(strtolower($role), $privilegedRoles)) {
                    return true;
                }
            }
        }
        
        return false;
    }
}