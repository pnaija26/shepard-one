<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiAccessEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'correlation_id',
        'api_client_id',
        'user_id',
        'route_name',
        'method',
        'path',
        'status_code',
        'outcome',
        'error_code',
        'ip_address',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
