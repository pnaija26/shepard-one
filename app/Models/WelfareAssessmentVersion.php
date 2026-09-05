<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WelfareAssessmentVersion extends Model
{
    protected $fillable = [
        'welfare_request_id',
        'version',
        'assessor_id',
        'assessment_notes',
        'verified_documents',
        'priority',
        'recommendation',
        'proposed_assistance_type',
        'proposed_value',
        'currency',
        'follow_up_needs',
        'complete',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'verified_documents' => 'array',
            'proposed_value' => 'decimal:2',
            'complete' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(WelfareRequest::class, 'welfare_request_id');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessor_id');
    }
}
