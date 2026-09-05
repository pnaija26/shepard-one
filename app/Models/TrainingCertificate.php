<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingCertificate extends Model
{
    public const STATUS_ISSUED = 'issued';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'training_enrolment_id',
        'training_offering_id',
        'training_offering_version_id',
        'member_id',
        'branch_id',
        'certificate_reference',
        'course_name',
        'course_version',
        'completion_date',
        'status',
        'issued_by',
        'revoked_by',
        'revoked_at',
        'revocation_reason',
    ];

    protected function casts(): array
    {
        return [
            'completion_date' => 'date:Y-m-d',
            'revoked_at' => 'datetime',
        ];
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(TrainingEnrolment::class, 'training_enrolment_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(TrainingOffering::class, 'training_offering_id');
    }
}
