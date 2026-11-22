<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiMemory;
use App\Models\Task;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class V2FeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_pwa_manifest_and_sw(): void
    {
        $this->get('/manifest.webmanifest')->assertOk()->assertJsonStructure(['name', 'icons', 'start_url']);
        $this->assertFileExists(public_path('sw.js'));
    }

    public function test_reports_all_formats_for_member(): void
    {
        $u = User::factory()->create();
        Task::factory()->for($u, 'owner')->create(['title' => 'Alpha']);
        foreach (ReportService::FORMATS as $f) {
            $r = $this->actingAs($u)->get("/reports/download?dataset=tasks&format=$f");
            $r->assertOk();
            $this->assertNotEmpty($r->baseResponse->getContent() ?: $r->streamedContent(), $f);
        }
    }

    public function test_member_cannot_export_users(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->get('/reports/download?dataset=users&format=csv')->assertForbidden();
    }

    public function test_notification_prefs_and_poll(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->getJson('/notifications/poll')->assertOk();
        $this->actingAs($u)->get('/profile')->assertOk();
    }

    public function test_avatar_upload(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('a.png', 300, 300)])->assertSessionHasNoErrors();
        $this->assertNotNull($u->fresh()->avatar_path);
        @unlink(public_path($u->fresh()->avatar_path));
    }

    public function test_conversations_and_memory_are_isolated(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->actingAs($a)->postJson('/ai/chat', ['message' => 'remember that I like tea'])->assertOk();
        $cid = AiConversation::where('user_id', $a->id)->value('id');
        $this->assertNotNull($cid);
        $this->actingAs($b)->getJson("/ai/conversations/$cid")->assertStatus(404);
        $this->assertSame(0, AiMemory::where('user_id', $b->id)->count());
    }

    public function test_chat_returns_new_shape(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->postJson('/ai/chat', ['message' => 'hello'])->assertOk()->assertJsonStructure(['reply', 'conversation_id', 'followups']);
    }

    public function test_managers_and_admins_hear_about_team_activity_members_do_not(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $mgr = User::factory()->create(['role' => 'manager']);
        $m1 = User::factory()->create(['role' => 'member']);
        $m2 = User::factory()->create(['role' => 'member']);
        $this->actingAs($m1)->post('/tasks', ['title' => 'Team task', 'priority' => 'high', 'status' => 'pending'])->assertSessionHasNoErrors();
        $this->assertSame(1, \App\Models\AppNotification::where('user_id', $mgr->id)->where('event', 'team_created')->count());
        $this->assertSame(1, \App\Models\AppNotification::where('user_id', $admin->id)->where('event', 'team_created')->count());
        $this->assertSame(0, \App\Models\AppNotification::where('user_id', $m2->id)->count());
        $this->assertArrayHasKey('team_created', \App\Services\Notifier::eventsFor($mgr));
        $this->assertArrayNotHasKey('team_created', \App\Services\Notifier::eventsFor($m1));
        $this->assertArrayHasKey('user_joined', \App\Services\Notifier::eventsFor($admin));
        $this->assertArrayNotHasKey('user_joined', \App\Services\Notifier::eventsFor($mgr));
    }

    public function test_instant_lane_answers_from_real_data_without_ai(): void
    {
        $u = User::factory()->create();
        Task::factory()->pending()->for($u, 'owner')->create(['title' => 'Real overdue thing', 'due_date' => now()->subDays(3)]);
        $r = $this->actingAs($u)->postJson('/ai/chat', ['message' => 'show my overdue tasks'])->assertOk();
        $this->assertSame('instant', $r->json('model'));
        $this->assertStringContainsString('Real overdue thing', json_encode($r->json('blocks')));
    }

    public function test_welcome_tour_flag(): void
    {
        $u = User::factory()->create();
        $this->assertNull($u->onboarded_at);
        $this->actingAs($u)->get('/dashboard')->assertOk()->assertSee('tour: true', false);
        $this->actingAs($u)->postJson('/onboarding/done')->assertOk();
        $this->assertNotNull($u->fresh()->onboarded_at);
        $this->actingAs($u)->get('/dashboard')->assertSee('tour: false', false);
        $this->actingAs($u)->postJson('/onboarding/reset')->assertOk();
        $this->assertNull($u->fresh()->onboarded_at);
    }
}
