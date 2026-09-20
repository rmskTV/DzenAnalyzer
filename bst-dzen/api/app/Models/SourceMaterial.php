<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceMaterial extends Model
{
    protected $fillable = [
        'source', 'external_id', 'url', 'title', 'body',
        'published_at', 'status', 'own_publication_id',
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function ownPublication(): BelongsTo
    {
        return $this->belongsTo(OwnPublication::class);
    }
}
