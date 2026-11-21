<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Services\Ai\AiActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.keys' => '']); // force offline mode: no network in tests
    }

    public function test_offline_assistant_proposes_a_task_card_and_saves_nothing(): void
    {
        $u = User::factory()->create();
        $r = $this->actingAs($u)->postJson('/ai/chat', ['message' => 'add task Call the bank due tomorrow priority high'])->assertOk();
        $r->assertJsonPath('offline', true)->assertJsonPath('proposals.0.action', 'create_task');
        $fields = collect($r->json('proposals.0.fields'))->pluck('value', 'key');
        $this->assertSame('Call the bank', $fields['title']);
        $this->assertSame('high', $fields['priority']);
        $this->assertSame(now()->addDay()->toDateString(), $fields['due_date']);
        $this->assertSame(0, Task::count());
        $this->assertDatabaseHas('ai_messages', ['user_id' => $u->id, 'role' => 'assistant']);
    }

    public function test_confirming_creates_task_with_ai_source_in_audit_log(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->postJson('/ai/execute', ['action' => 'create_task', 'params' => ['title' => 'From chat', 'status' => 'pending', 'priority' => 'medium', 'due_date' => '']])
            ->assertOk()->assertJson(['ok' => true]);
        $task = Task::first();
        $this->assertSame('From chat', $task->title);
        $this->assertDatabaseHas('activity_logs', ['task_id' => $task->id, 'action' => 'created', 'source' => 'ai']);
    }

    public function test_ai_execution_is_validated_like_the_form(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->postJson('/ai/execute', ['action' => 'create_task', 'params' => ['title' => '', 'status' => 'pending', 'priority' => 'low']])->assertStatus(422)->assertJson(['ok' => false]);
        $this->actingAs($u)->postJson('/ai/execute', ['action' => 'drop_database', 'params' => []])->assertStatus(422);
    }

    public function test_ai_cannot_bypass_permissions(): void
    {
        $member = User::factory()->create();
        $foreign = Task::factory()->for(User::factory()->create(), 'owner')->create();
        $this->actingAs($member)->postJson('/ai/execute', ['action' => 'delete_task', 'params' => ['id' => $foreign->id]])->assertStatus(422);
        $this->assertNull($foreign->fresh()->deleted_at);

        $assigned = Task::factory()->for(User::factory()->manager()->create(), 'owner')->create(['assignee_id' => $member->id]);
        $res = AiActions::execute($member, 'delete_task', ['id' => $assigned->id]);
        $this->assertFalse($res['ok']);
        $this->assertNull($assigned->fresh()->deleted_at);
    }

    public function test_offline_complete_and_list_and_summary(): void
    {
        $u = User::factory()->create();
        $t = Task::factory()->pending()->for($u, 'owner')->create(['title' => 'Water plants']);
        $this->actingAs($u)->postJson('/ai/chat', ['message' => "complete #{$t->id}"])->assertJsonPath('proposals.0.action', 'set_status');
        $this->actingAs($u)->postJson('/ai/chat', ['message' => 'show pending'])->assertSee('Water plants');
        $this->actingAs($u)->postJson('/ai/chat', ['message' => 'summary'])->assertSee('1 tasks');
    }

    public function test_history_and_validation(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->getJson('/ai/history')->assertOk()->assertJson(['ok' => true, 'ai' => false]);
        $this->actingAs($u)->postJson('/ai/chat', ['message' => ''])->assertStatus(422);
        $this->postJson('/logout');
        $this->getJson('/ai/history')->assertStatus(401);
    }
}
