<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    use HasFactory;

    /** Минимальный возраст поста для участия в охватных метриках, ч */
    public const MATURITY_HOURS = 16;

    protected $fillable = [
        'channel_id', 'dzen_post_id', 'type', 'title', 'lead', 'url',
        'published_at', 'views', 'comments', 'size_sec', 'rubric', 'content_format',
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

    /** Прожил в паблике достаточно, чтобы его просмотры участвовали в охватных метриках */
    public function scopeMatured($query): void
    {
        $query->where('published_at', '<=', now()->subHours(self::MATURITY_HOURS));
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(PostSnapshot::class);
    }
}
