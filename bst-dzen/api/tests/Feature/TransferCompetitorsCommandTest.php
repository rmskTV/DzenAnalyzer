<?php

namespace Tests\Feature;

use App\Models\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferCompetitorsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfers_all_competitors_between_own_channels(): void
    {
        $from = Channel::factory()->own()->create();
        $to = Channel::factory()->own()->create();
        $competitors = Channel::factory()->count(3)->create();

        $from->competitors()->sync($competitors->pluck('id'));

        $this->artisan('dzen:transfer-competitors', ['from' => $from->id, 'to' => $to->id])
            ->expectsOutputToContain('Перенесено 3 конкурентов')
            ->assertSuccessful();

        $this->assertSame(0, $from->competitors()->count());
        $this->assertSame(
            $competitors->pluck('id')->sort()->values()->all(),
            $to->competitors()->pluck('channels.id')->sort()->values()->all(),
        );
    }

    public function test_merges_sets_and_skips_duplicates(): void
    {
        $from = Channel::factory()->own()->create();
        $to = Channel::factory()->own()->create();
        $shared = Channel::factory()->create();
        $onlyFrom = Channel::factory()->create();
        $onlyTo = Channel::factory()->create();

        $from->competitors()->sync([$shared->id, $onlyFrom->id]);
        $to->competitors()->sync([$shared->id, $onlyTo->id]);

        $this->artisan('dzen:transfer-competitors', ['from' => $from->id, 'to' => $to->id])
            ->expectsOutputToContain('Перенесено 1 конкурентов (дублей пропущено: 1)')
            ->assertSuccessful();

        $this->assertSame(0, $from->competitors()->count());
        $this->assertSame(
            collect([$shared->id, $onlyTo->id, $onlyFrom->id])->sort()->values()->all(),
            $to->competitors()->pluck('channels.id')->sort()->values()->all(),
        );
    }

    public function test_transfer_from_channel_without_competitors_is_noop(): void
    {
        $from = Channel::factory()->own()->create();
        $to = Channel::factory()->own()->create();
        $toCompetitor = Channel::factory()->create();
        $to->competitors()->attach($toCompetitor->id);

        $this->artisan('dzen:transfer-competitors', ['from' => $from->id, 'to' => $to->id])
            ->expectsOutputToContain('Перенесено 0 конкурентов')
            ->assertSuccessful();

        $this->assertSame([$toCompetitor->id], $to->competitors()->pluck('channels.id')->all());
    }

    public function test_rejects_non_own_channel(): void
    {
        $from = Channel::factory()->own()->create();
        $notOwn = Channel::factory()->create();

        $this->artisan('dzen:transfer-competitors', ['from' => $from->id, 'to' => $notOwn->id])
            ->expectsOutputToContain('не найден или не является own-каналом')
            ->assertFailed();

        $this->artisan('dzen:transfer-competitors', ['from' => 999, 'to' => $from->id])
            ->expectsOutputToContain('не найден или не является own-каналом')
            ->assertFailed();
    }

    public function test_rejects_equal_ids(): void
    {
        $own = Channel::factory()->own()->create();

        $this->artisan('dzen:transfer-competitors', ['from' => $own->id, 'to' => $own->id])
            ->expectsOutputToContain('должны различаться')
            ->assertFailed();
    }
}
