<?php

namespace App\Policies;

use App\Models\Channel;
use App\Models\User;

class ChannelPolicy
{
    /** Аналитика канала: назначенные own-каналы и их конкуренты (админу — любые) */
    public function viewAnalysis(User $user, Channel $channel): bool
    {
        return $user->canAccessChannel($channel);
    }
}
