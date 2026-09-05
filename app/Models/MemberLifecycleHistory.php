<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberLifecycleHistory extends Model
{
    protected $table = 'member_lifecycle_history';

    public $timestamps = false;

    protected $fillable = [
        'member_id',
        'stage',
        'status',
        'previous_stage',
        'previous_status',
        'effective_date',
        'reason',
        'milestone',
        'evidence',
        'policy_applied',
        'actor_id',
        'created_at',
    ];

    protected $casts = [
        'effective_date' => 'date:Y-m-d',
        'milestone' => 'array',
        'evidence' => 'array',
        'policy_applied' => 'array',
        'created_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
