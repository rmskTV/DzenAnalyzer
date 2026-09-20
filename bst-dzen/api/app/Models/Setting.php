<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    protected $fillable = ['key', 'own_channel_id', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public function ownChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
