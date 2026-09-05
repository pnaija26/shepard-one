<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Organization extends Model
{
    protected $fillable = [
        'name',
        'type',
        'identifier',
        'parent_id',
        'branch_id',
        'description',
        'location',
        'primary_contact',
        'attributes',
        'is_active'
    ];

    protected $casts = [
        'location' => 'array',
        'primary_contact' => 'array',
        'attributes' => 'array',
        'is_active' => 'boolean'
    ];

    /**
     * Get the parent organization.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'parent_id');
    }

    /**
     * Get the child organizations.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Organization::class, 'parent_id');
    }

    /**
     * Get the branch this organization belongs to.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    /**
     * Get the organizations that belong to this branch.
     */
    public function branchOrganizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'branch_id');
    }
}