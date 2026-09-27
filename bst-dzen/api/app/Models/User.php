<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'is_admin'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    /** Own-каналы, назначенные пользователю для анализа */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class);
    }

    /**
     * ID каналов, видимых пользователю: назначенные own-каналы + их конкурентные наборы.
     * null — админ, видит все каналы.
     *
     * @return Collection<int, int>|null
     */
    public function visibleChannelIds(): ?Collection
    {
        if ($this->is_admin) {
            return null;
        }

        $ownIds = $this->channels()->pluck('channels.id');

        $competitorIds = DB::table('channel_competitors')
            ->whereIn('own_channel_id', $ownIds)
            ->pluck('competitor_channel_id');

        return $ownIds->merge($competitorIds)->unique()->values();
    }

    public function canAccessChannel(Channel $channel): bool
    {
        if ($this->is_admin) {
            return true;
        }

        $visible = $this->visibleChannelIds();

        return $visible !== null && $visible->contains($channel->id);
    }
}
