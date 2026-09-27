<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompetitiveRubricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_rubric_matrix_and_white_spots_are_exposed(): void
    {
        $own = Channel::factory()->own()->create(['title' => 'Мы']);
        $competitor = Channel::factory()->create(['title' => 'Конкурент']);
        $own->competitors()->attach($competitor->id);

        // наша стабильная рубрика с медианой 1000
        Post::factory()->count(4)->for($own)->create(['rubric' => 'ЖКХ/город', 'views' => 1000]);
        // рубрика конкурента, где нас нет: 10 статей по 5000 — белое пятно
        Post::factory()->count(10)->for($competitor)->create(['rubric' => 'Спорт', 'views' => 5000]);
        // свежий (несозревший) пост конкурента: в медиану не попадает, в n — попадает
        Post::factory()->fresh()->for($competitor)->create(['rubric' => 'Спорт', 'views' => 99999]);

        $response = $this->getJson("/api/competitive?own={$own->id}&days=21");

        $response->assertOk();

        $rubrics = $response->json('rubrics');
        $this->assertContains('Спорт', $rubrics['names']);
        $this->assertContains('Без рубрики', $rubrics['names']);

        $competitorRow = collect($rubrics[(string) $competitor->id])->firstWhere('rubric', 'Спорт');
        $this->assertSame(11, $competitorRow['n']);
        $this->assertEqualsWithDelta(5000.0, $competitorRow['views_median'], 0.001);

        $ownRow = collect($rubrics[(string) $own->id])->firstWhere('rubric', 'ЖКХ/город');
        $this->assertSame(4, $ownRow['n']);
        $this->assertEqualsWithDelta(1000.0, $ownRow['views_median'], 0.001);

        $spots = collect($response->json('white_spots'));
        $this->assertSame(['Спорт'], $spots->pluck('rubric')->all());

        $spot = $spots->first();
        $this->assertSame(0, $spot['own_n']);
        $this->assertSame(11, $spot['competitors_n']);
        $this->assertEqualsWithDelta(5000.0, $spot['competitors_median'], 0.001);
        $this->assertSame($competitor->id, $spot['best_channel']['id']);
        $this->assertEqualsWithDelta(5000.0, $spot['best_channel']['views_median'], 0.001);
    }

    public function test_rubric_covered_by_own_is_not_a_white_spot(): void
    {
        $own = Channel::factory()->own()->create();
        $competitor = Channel::factory()->create();
        $own->competitors()->attach($competitor->id);

        Post::factory()->count(3)->for($own)->create(['rubric' => 'ЖКХ/город', 'views' => 1000]);
        Post::factory()->count(10)->for($competitor)->create(['rubric' => 'ЖКХ/город', 'views' => 5000]);

        $response = $this->getJson("/api/competitive?own={$own->id}&days=21");

        $response->assertOk()
            ->assertJsonPath('white_spots', []);
    }
}
