<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HouseholdRelationshipHistory extends Model
{
    protected $table = 'household_relationship_history';

    public $timestamps = false;

    protected $fillable = [
        'household_id',
        'member_id',
        'action',
        'relationship_type',
        'previous_relationship_type',
        'actor_id',
        'detail',
        'created_at',
    ];

    protected $casts = [
        'detail' => 'array',
        'created_at' => 'datetime',
    ];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
