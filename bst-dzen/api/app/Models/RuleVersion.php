<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleVersion extends Model
{
    protected $fillable = [
        'own_channel_id', 'layer', 'title', 'content', 'summary',
        'is_active', 'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'activated_at' => 'datetime',
        ];
    }

    public function ownChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
