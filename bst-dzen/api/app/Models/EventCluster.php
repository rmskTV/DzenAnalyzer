<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class EventCluster extends Model
{
    protected $fillable = ['scope_channel_id', 'first_published_at', 'n_channels', 'title'];

    protected function casts(): array
    {
        return [
            'first_published_at' => 'datetime',
            'n_channels' => 'integer',
        ];
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'scope_channel_id');
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'event_members')
            ->withPivot('delay_min')
            ->withTimestamps();
    }
}
