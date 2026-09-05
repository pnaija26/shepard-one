<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettingReference extends Model
{
    protected $fillable = [
        'setting_id',
        'reference_type',
        'reference_id',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }
}
