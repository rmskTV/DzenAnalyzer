<?php

namespace Tests\Feature;

use App\Jobs\CrawlChannel;
use App\Models\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CollectDzenCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_collects_all_active_channels_without_filter(): void
    {
        Queue::fake();

        $own = Channel::factory()->own()->create();
        $competitor = Channel::factory()->create();
        Channel::factory()->create(['is_active' => false]);

        $this->artisan('dzen:collect')
            ->expectsOutputToContain('Каналов к сбору: 2')
            ->assertSuccessful();

        Queue::assertPushed(CrawlChannel::class, 2);
        Queue::assertPushed(CrawlChannel::class, fn (CrawlChannel $job) => $job->channelId === $own->id);
        Queue::assertPushed(CrawlChannel::class, fn (CrawlChannel $job) => $job->channelId === $competitor->id);
    }

    public function test_collects_own_channel_with_active_competitors(): void
    {
        Queue::fake();

        $own = Channel::factory()->own()->create();
        $active = Channel::factory()->create();
        $inactive = Channel::factory()->create(['is_active' => false]);
        $unrelated = Channel::factory()->create();

        $own->competitors()->sync([$active->id, $inactive->id]);

        $this->artisan('dzen:collect', ['--channel' => (string) $own->id])
            ->expectsOutputToContain('Каналов к сбору: 2')
            ->assertSuccessful();

        Queue::assertPushed(CrawlChannel::class, 2);
        Queue::assertPushed(CrawlChannel::class, fn (CrawlChannel $job) => $job->channelId === $own->id);
        Queue::assertPushed(CrawlChannel::class, fn (CrawlChannel $job) => $job->channelId === $active->id);
        Queue::assertNotPushed(CrawlChannel::class, fn (CrawlChannel $job) => $job->channelId === $inactive->id);
        Queue::assertNotPushed(CrawlChannel::class, fn (CrawlChannel $job) => $job->channelId === $unrelated->id);
    }

    public function test_collects_single_channel_by_dzen_key_without_competitor_sets(): void
    {
        Queue::fake();

        $own = Channel::factory()->own()->create();
        $channel = Channel::factory()->create(['dzen_key' => 'niann']);
        $ownCompetitor = Channel::factory()->create();

        // канал состоит в конкурентном наборе — обратная связь не тянет own-канал
        $own->competitors()->attach($channel->id);

        $this->artisan('dzen:collect', ['--channel' => 'niann', '--days' => 7])
            ->expectsOutputToContain('Каналов к сбору: 1')
            ->assertSuccessful();

        Queue::assertPushed(CrawlChannel::class, 1);
        Queue::assertPushed(
            CrawlChannel::class,
            fn (CrawlChannel $job) => $job->channelId === $channel->id && $job->days === 7,
        );
    }

    public function test_collects_inactive_channel_with_warning_when_explicitly_requested(): void
    {
        Queue::fake();

        $channel = Channel::factory()->create(['is_active' => false]);

        $this->artisan('dzen:collect', ['--channel' => (string) $channel->id])
            ->expectsOutputToContain('неактивен — собираю по явному запросу')
            ->assertSuccessful();

        Queue::assertPushed(CrawlChannel::class, fn (CrawlChannel $job) => $job->channelId === $channel->id);
    }

    public function test_fails_when_channel_not_found(): void
    {
        Queue::fake();

        $this->artisan('dzen:collect', ['--channel' => 'no-such-channel'])
            ->expectsOutputToContain('Канал не найден: no-such-channel')
            ->assertFailed();

        Queue::assertNothingPushed();
    }
}
