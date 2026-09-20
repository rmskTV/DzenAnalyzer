<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    protected $fillable = [
        'dzen_key', 'dzen_mode', 'title', 'subscribers',
        'timezone', 'is_active', 'is_own', 'last_crawled_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_own' => 'boolean',
            'subscribers' => 'integer',
            'last_crawled_at' => 'datetime',
        ];
    }

    /** Конкурентный набор (для own-каналов) */
    public function competitors(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'channel_competitors', 'own_channel_id', 'competitor_channel_id')
            ->withTimestamps();
    }

    /** В каких конкурентных наборах состоит сам */
    public function competitorOf(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'channel_competitors', 'competitor_channel_id', 'own_channel_id')
            ->withTimestamps();
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function scopeOwn($query)
    {
        return $query->where('is_own', true);
    }
}
