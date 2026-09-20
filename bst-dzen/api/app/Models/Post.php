<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    protected $fillable = [
        'channel_id', 'dzen_post_id', 'type', 'title', 'lead', 'url',
        'published_at', 'views', 'comments', 'size_sec',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'views' => 'integer',
            'comments' => 'integer',
            'size_sec' => 'integer',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(PostSnapshot::class);
    }
}
