<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Создание/обновление пользователя.
 * Примеры:
 *   php artisan dzen:user editor@example.com --password=secret --channels=1,3
 *   php artisan dzen:user admin@example.com --admin --password=secret
 */
class CreateUserCommand extends Command
{
    protected $signature = 'dzen:user
        {email : email пользователя}
        {--name= : отображаемое имя (по умолчанию часть email)}
        {--password= : пароль (без опции — генерируется и печатается)}
        {--admin : признак администратора}
        {--channels= : ID own-каналов через запятую, доступных для анализа}';

    protected $description = 'Создать/обновить пользователя (доступ к каналам, признак админа)';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        $channelIds = collect(explode(',', (string) $this->option('channels')))
            ->filter()
            ->map(fn (string $id) => (int) trim($id))
            ->unique()
            ->all();

        if ($channelIds !== []) {
            $found = Channel::whereKey($channelIds)->pluck('id');
            $missing = array_diff($channelIds, $found->all());
            if ($missing !== []) {
                $this->error('Каналы не найдены: '.implode(', ', $missing));

                return self::FAILURE;
            }
        }

        $generatedPassword = null;
        $password = (string) $this->option('password');
        if ($password === '') {
            $password = $generatedPassword = Str::password(16, symbols: false);
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $this->option('name') ?: str($email)->before('@')->toString(),
                'password' => Hash::make($password),
                'is_admin' => (bool) $this->option('admin'),
            ],
        );

        $user->channels()->sync($channelIds);

        $this->info(sprintf(
            '%s %s (email: %s, админ: %s, каналы: %s)',
            $user->wasRecentlyCreated ? 'Создан пользователь' : 'Обновлён пользователь',
            $user->name,
            $user->email,
            $user->is_admin ? 'да' : 'нет',
            $channelIds === [] ? '—' : implode(', ', $channelIds),
        ));

        if ($generatedPassword !== null) {
            $this->info("Сгенерированный пароль: {$generatedPassword}");
        }

        return self::SUCCESS;
    }
}
