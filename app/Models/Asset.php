<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use HasFactory;

    protected $casts = [
        'size_bytes' => 'integer',
        'is_image' => 'boolean',
        'last_modified_at' => 'datetime',
    ];

    protected $appends = [
        'preview_url',
    ];

    public function getPreviewUrlAttribute(): string
    {
        return route('assets.preview', $this);
    }
}
