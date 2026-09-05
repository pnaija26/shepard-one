<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowUpActivity extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'follow_up_id',
        'activity_type',
        'outcome',
        'notes',
        'next_action',
        'next_due_date',
        'actor_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'next_due_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(FollowUp::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
