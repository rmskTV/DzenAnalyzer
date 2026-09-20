<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OwnPublication extends Model
{
    protected $fillable = [
        'own_channel_id', 'published_post_id', 'source_type', 'source_url',
        'title', 'title_variant', 'headline_pattern', 'body', 'rubric',
        'status', 'scheduled_at', 'published_at', 'experiment_tags',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'experiment_tags' => 'array',
        ];
    }

    public function ownChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'own_channel_id');
    }

    public function publishedPost(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'published_post_id');
    }
}
