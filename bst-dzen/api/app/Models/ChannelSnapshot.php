<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelSnapshot extends Model
{
    protected $fillable = ['channel_id', 'snapshot_date', 'subscribers'];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'subscribers' => 'integer',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
