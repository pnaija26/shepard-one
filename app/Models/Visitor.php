<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Visitor extends Model
{
    protected $fillable = [
        'member_id',
        'branch_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'date_of_birth',
        'original_source',
        'inviter_name',
        'inviter_member_id',
        'first_visit_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'date_of_birth' => 'date:Y-m-d',
        'first_visit_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function inviterMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'inviter_member_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(VisitorVisit::class)->orderByDesc('visit_date');
    }

    public function fullName(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }
}
