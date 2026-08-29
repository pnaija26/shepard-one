<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConfigurationCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'key_prefix',
        'is_system'
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function settings()
    {
        return $this->hasMany(Setting::class, 'category', 'name');
    }
}