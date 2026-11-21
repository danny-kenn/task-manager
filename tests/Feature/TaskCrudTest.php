<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_user_can_create_a_task(): void
    {
        $res = $this->actingAs($this->user)->post('/tasks', [
            'title' => 'Write the README', 'description' => 'Document setup', 'status' => 'pending', 'priority' => 'high', 'tags' => 'docs, laravel',
        ]);
        $task = Task::first();
        $res->assertRedirect(route('tasks.show', $task));
        $this->assertSame('Write the README', $task->title);
        $this->assertSame(TaskStatus::Pending, $task->status);
        $this->assertSame(['docs', 'laravel'], $task->tags->pluck('name')->sort()->values()->all());
        $this->assertDatabaseHas('activity_logs', ['task_id' => $task->id, 'action' => 'created']);
    }

    public function test_description_is_optional(): void
    {
        $this->actingAs($this->user)->post('/tasks', ['title' => 'No description', 'status' => 'completed', 'priority' => 'low'])->assertSessionHasNoErrors();
        $this->assertNull(Task::first()->description);
        $this->assertNotNull(Task::first()->completed_at);
    }

    public function test_required_fields_are_validated_on_create(): void
    {
        $this->actingAs($this->user)->post('/tasks', [])->assertSessionHasErrors(['title', 'status', 'priority']);
        $this->actingAs($this->user)->post('/tasks', ['title' => 'ok title', 'status' => 'archived', 'priority' => 'high'])->assertSessionHasErrors('status');
        $this->assertSame(0, Task::count());
    }

    public function test_validation_messages_are_friendly(): void
    {
        $this->actingAs($this->user)->post('/tasks', ['status' => 'pending', 'priority' => 'low'])
            ->assertSessionHasErrors(['title' => 'Please give the task a title.']);
    }

    public function test_validation_is_applied_when_editing(): void
    {
        $task = Task::factory()->for($this->user, 'owner')->create();
        $this->actingAs($this->user)->put("/tasks/{$task->id}", ['title' => '', 'status' => 'pending', 'priority' => 'low'])->assertSessionHasErrors('title');
        $this->assertNotSame('', $task->fresh()->title);
    }

    public function test_user_can_edit_a_task_and_change_status(): void
    {
        $task = Task::factory()->pending()->for($this->user, 'owner')->create();
        $this->actingAs($this->user)->put("/tasks/{$task->id}", ['title' => 'Renamed task', 'status' => 'completed', 'priority' => 'urgent'])
            ->assertRedirect(route('tasks.show', $task));
        $task->refresh();
        $this->assertSame('Renamed task', $task->title);
        $this->assertTrue($task->is_completed);
        $this->assertNotNull($task->completed_at);
    }

    public function test_index_lists_tasks_with_title_date_and_status(): void
    {
        Task::factory()->completed()->for($this->user, 'owner')->create(['title' => 'Shipped feature']);
        $this->actingAs($this->user)->get('/tasks')->assertOk()
            ->assertSee('Shipped feature')->assertSee('Completed')->assertSee('Created '.now()->format('d M Y'));
    }

    public function test_delete_moves_to_trash_and_is_logged(): void
    {
        $task = Task::factory()->for($this->user, 'owner')->create();
        $this->actingAs($this->user)->delete("/tasks/{$task->id}")->assertRedirect('/tasks');
        $this->assertSoftDeleted($task);
        $this->assertDatabaseHas('activity_logs', ['task_id' => $task->id, 'action' => 'deleted']);
    }

    public function test_toggle_flips_status_and_returns_json(): void
    {
        $task = Task::factory()->pending()->for($this->user, 'owner')->create();
        $this->actingAs($this->user)->patchJson("/tasks/{$task->id}/toggle")->assertOk()->assertJson(['ok' => true, 'status' => 'completed']);
        $this->assertTrue($task->fresh()->is_completed);
        $this->actingAs($this->user)->patchJson("/tasks/{$task->id}/toggle")->assertJson(['status' => 'pending']);
        $this->assertNull($task->fresh()->completed_at);
    }

    public function test_search_filters_by_title_and_status(): void
    {
        Task::factory()->pending()->for($this->user, 'owner')->create(['title' => 'Buy groceries']);
        Task::factory()->completed()->for($this->user, 'owner')->create(['title' => 'Pay rent']);
        $this->actingAs($this->user)->get('/tasks?q=groceries')->assertSee('Buy groceries')->assertDontSee('Pay rent');
        $this->actingAs($this->user)->get('/tasks?status=completed')->assertSee('Pay rent')->assertDontSee('Buy groceries');
        $this->actingAs($this->user)->get('/tasks?q=completed')->assertSee('Pay rent')->assertDontSee('Buy groceries');
        $this->actingAs($this->user)->get('/tasks?q=zzzz')->assertSee('No tasks match your search');
    }

    public function test_pagination_shows_ten_per_page(): void
    {
        Task::factory()->count(25)->for($this->user, 'owner')->create();
        $this->actingAs($this->user)->get('/tasks')->assertOk()->assertSee('Showing 1–10 of 25');
        $this->actingAs($this->user)->get('/tasks?page=3')->assertSee('Showing 21–25 of 25');
    }

    public function test_reorder_persists_new_order(): void
    {
        $a = Task::factory()->for($this->user, 'owner')->create(['position' => 1]);
        $b = Task::factory()->for($this->user, 'owner')->create(['position' => 2]);
        $c = Task::factory()->for($this->user, 'owner')->create(['position' => 3]);
        $this->actingAs($this->user)->postJson('/tasks/reorder', ['ids' => [$c->id, $a->id, $b->id]])->assertOk()->assertJson(['moved' => 3]);
        $order = Task::manualOrder()->pluck('id')->all();
        $this->assertSame([$c->id, $a->id, $b->id], $order);
    }

    public function test_new_tasks_appear_on_top(): void
    {
        $old = Task::factory()->for($this->user, 'owner')->create(['position' => 0]);
        $this->actingAs($this->user)->post('/tasks', ['title' => 'Brand new', 'status' => 'pending', 'priority' => 'low']);
        $this->assertSame('Brand new', Task::manualOrder()->first()->title);
    }

    public function test_subtasks_and_comments(): void
    {
        $task = Task::factory()->for($this->user, 'owner')->create();
        $this->actingAs($this->user)->post("/tasks/{$task->id}/subtasks", ['title' => 'Step one'])->assertRedirect();
        $this->actingAs($this->user)->post("/tasks/{$task->id}/comments", ['body' => 'Looks good'])->assertRedirect();
        $this->assertSame(1, $task->subtasks()->count());
        $this->assertSame(1, $task->comments()->count());
        $this->actingAs($this->user)->post("/tasks/{$task->id}/subtasks", ['title' => ''])->assertSessionHasErrors('title');
        $this->actingAs($this->user)->get("/tasks/{$task->id}")->assertOk()->assertSee('Step one')->assertSee('Looks good');
    }
}
