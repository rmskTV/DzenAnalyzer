<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostSnapshot extends Model
{
    protected $fillable = ['post_id', 'snapshot_date', 'views', 'comments'];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'views' => 'integer',
            'comments' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
