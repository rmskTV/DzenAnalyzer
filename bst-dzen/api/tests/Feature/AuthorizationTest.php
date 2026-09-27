<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $editor;

    private Channel $ownAllowed;

    private Channel $ownForbidden;

    private Channel $competitor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->editor = User::factory()->create();

        $this->ownAllowed = Channel::factory()->own()->create(['title' => 'Свой (доступен)']);
        $this->ownForbidden = Channel::factory()->own()->create(['title' => 'Свой (чужой)']);
        $this->competitor = Channel::factory()->create(['title' => 'Конкурент']);

        $this->ownAllowed->competitors()->attach($this->competitor->id);
        $this->editor->channels()->attach($this->ownAllowed->id);

        Post::factory()->count(2)->for($this->competitor)->create();
    }

    public function test_channels_list_is_scoped_for_regular_user(): void
    {
        Sanctum::actingAs($this->editor);

        $response = $this->getJson('/api/channels')->assertOk();

        $visibleIds = collect($response->json())->pluck('id');

        $this->assertContains($this->ownAllowed->id, $visibleIds);
        $this->assertContains($this->competitor->id, $visibleIds);
        $this->assertNotContains($this->ownForbidden->id, $visibleIds);
    }

    public function test_admin_sees_all_channels(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/channels')
            ->assertOk()
            ->assertJsonCount(3);
    }

    public function test_user_can_view_competitive_for_allowed_own_channel(): void
    {
        Sanctum::actingAs($this->editor);

        $this->getJson("/api/competitive?own={$this->ownAllowed->id}&days=21")->assertOk();
        $this->getJson("/api/events?own={$this->ownAllowed->id}")->assertOk();
    }

    public function test_user_cannot_view_competitive_for_foreign_own_channel(): void
    {
        Sanctum::actingAs($this->editor);

        $this->getJson("/api/competitive?own={$this->ownForbidden->id}")->assertForbidden();
        $this->getJson("/api/events?own={$this->ownForbidden->id}")->assertForbidden();
    }

    public function test_user_can_read_posts_of_competitor_of_allowed_channel(): void
    {
        Sanctum::actingAs($this->editor);

        $this->getJson("/api/channels/{$this->competitor->id}/posts?days=21")->assertOk();
        $this->getJson("/api/channels/{$this->competitor->id}/posts/filters?days=21")->assertOk();
        $this->getJson("/api/channels/{$this->competitor->id}/stats")->assertOk();
    }

    public function test_user_cannot_read_posts_of_inaccessible_channel(): void
    {
        $stranger = Channel::factory()->create(['title' => 'Посторонний']);
        Post::factory()->for($stranger)->create();

        Sanctum::actingAs($this->editor);

        $this->getJson("/api/channels/{$stranger->id}/posts")->assertForbidden();
        $this->getJson("/api/channels/{$stranger->id}/posts/filters")->assertForbidden();
        $this->getJson("/api/channels/{$stranger->id}/stats")->assertForbidden();
    }

    public function test_user_without_channels_gets_empty_scope(): void
    {
        $lonely = User::factory()->create();
        Sanctum::actingAs($lonely);

        $this->getJson('/api/channels')->assertOk()->assertJsonCount(0);
    }

    public function test_admin_only_endpoints_reject_regular_user(): void
    {
        Sanctum::actingAs($this->editor);

        $this->getJson('/api/system-status')->assertForbidden();
        $this->putJson("/api/channels/{$this->ownAllowed->id}", ['is_active' => false])->assertForbidden();
        $this->getJson('/api/drafts')->assertForbidden();
        $this->getJson('/api/rules')->assertForbidden();
        $this->getJson('/api/settings')->assertForbidden();
        $this->putJson('/api/settings', [])->assertForbidden();
    }

    public function test_admin_can_access_admin_endpoints(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/system-status')->assertOk();
        $this->getJson('/api/drafts')->assertOk();
        $this->getJson('/api/rules')->assertOk();
        $this->getJson('/api/settings')->assertOk();
        $this->putJson("/api/channels/{$this->ownAllowed->id}", ['is_active' => false])->assertOk();
    }
}
