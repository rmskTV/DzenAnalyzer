<?php

namespace Tests\Feature;

use App\Models\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddChannelCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_channel_by_name_key(): void
    {
        $this->artisan('dzen:channel', ['key' => 'irk.ru'])
            ->expectsOutputToContain('Добавлен канал')
            ->assertSuccessful();

        $channel = Channel::where('dzen_key', 'irk.ru')->first();

        $this->assertNotNull($channel);
        $this->assertSame('name', $channel->dzen_mode);
        $this->assertSame('irk.ru', $channel->title);
        $this->assertSame('Asia/Irkutsk', $channel->timezone);
        $this->assertFalse($channel->is_own);
        $this->assertTrue($channel->is_active);
    }

    public function test_auto_detects_hex_id_key(): void
    {
        $this->artisan('dzen:channel', ['key' => '631af459b1da0113c4a58ff8'])
            ->assertSuccessful();

        $channel = Channel::where('dzen_key', '631af459b1da0113c4a58ff8')->first();

        $this->assertNotNull($channel);
        $this->assertSame('id', $channel->dzen_mode);
    }

    public function test_own_flag_and_competitor_set_attachment(): void
    {
        $own = Channel::factory()->own()->create();

        $this->artisan('dzen:channel', [
            'key' => 'new-rival',
            '--own' => true,
            '--title' => 'Новый конкурент',
            '--tz' => 'Asia/Krasnoyarsk',
            '--competitors-of' => $own->id,
        ])->assertSuccessful();

        $channel = Channel::where('dzen_key', 'new-rival')->firstOrFail();

        $this->assertTrue($channel->is_own);
        $this->assertSame('Новый конкурент', $channel->title);
        $this->assertSame('Asia/Krasnoyarsk', $channel->timezone);
        $this->assertTrue($own->competitors()->whereKey($channel->id)->exists());
    }

    public function test_competitors_of_rejects_missing_own_channel(): void
    {
        $this->artisan('dzen:channel', ['key' => 'rival', '--competitors-of' => 999])
            ->expectsOutputToContain('не найден')
            ->assertFailed();
    }

    public function test_idempotent_update_preserves_crawled_data(): void
    {
        $channel = Channel::factory()->create([
            'dzen_key' => 'irk.ru',
            'dzen_mode' => 'name',
            'title' => 'Ирк.ру',
            'subscribers' => 7305,
        ]);

        $this->artisan('dzen:channel', ['key' => 'irk.ru', '--tz' => 'Asia/Krasnoyarsk'])
            ->expectsOutputToContain('Обновлён канал')
            ->assertSuccessful();

        $channel->refresh();

        $this->assertSame('Ирк.ру', $channel->title);
        $this->assertSame(7305, $channel->subscribers);
        $this->assertSame('Asia/Krasnoyarsk', $channel->timezone);
        $this->assertSame(1, Channel::where('dzen_key', 'irk.ru')->count());
    }

    public function test_rejects_unknown_timezone(): void
    {
        $this->artisan('dzen:channel', ['key' => 'irk.ru', '--tz' => 'Mars/Olympus'])
            ->expectsOutputToContain('Неизвестный часовой пояс')
            ->assertFailed();

        $this->assertSame(0, Channel::count());
    }
}
