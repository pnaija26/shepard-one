<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
     * Story 2.2: linked church member profile for self-service updates.
     */
    public function memberProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Member::class);
    }

    /**
     * The branch this user is assigned to (Story 1.4).
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    /**
     * Story 1.6: roles via the role_assignments pivot (explicit table name —
     * `roles` is also a legacy JSON column on this model).
     */
    public function assignedRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_assignments')
            ->withPivot(['granted_by', 'expires_at'])
            ->withTimestamps();
    }

    /** Roles still in force right now (AC3: expired assignments are inactive). */
    public function activeRoles(): BelongsToMany
    {
        return $this->assignedRoles()
            ->where(function ($query) {
                $query->whereNull('role_assignments.expires_at')
                    ->orWhere('role_assignments.expires_at', '>', now());
            });
    }

    /** True when the user holds an active super-admin role (AC4 tier). */
    public function isSuperAdmin(): bool
    {
        return $this->activeRoles()
            ->where('roles.is_super_admin', true)
            ->exists();
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
     * Check if user has a privileged role.
     *
     * Story 1.6: consults BOTH the legacy JSON `roles` column (pre-1.6 data)
     * and the new role_assignments table, so existing users keep working while
     * authorization migrates to scoped roles.
     */
    public function isPrivileged(): bool
    {
        $privilegedRoles = config('identity.security.mfa.privileged_roles', ['admin', 'hq_admin', 'system_admin']);

        if (isset($this->roles) && is_array($this->roles)) {
            foreach ($this->roles as $role) {
                if (in_array(strtolower($role), $privilegedRoles)) {
                    return true;
                }
            }
        }

        // New model: any active assignment to a privileged-named role.
        return $this->activeRoles()
            ->whereIn('roles.name', $privilegedRoles)
            ->exists();
    }
}