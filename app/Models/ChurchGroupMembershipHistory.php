<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChurchGroupMembershipHistory extends Model
{
    public $timestamps = false;

    protected $table = 'church_group_membership_history';

    protected $fillable = [
        'church_group_id',
        'member_id',
        'change_type',
        'role',
        'metadata',
        'actor_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ChurchGroup::class, 'church_group_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
