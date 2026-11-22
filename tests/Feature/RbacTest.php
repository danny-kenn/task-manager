<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_only_sees_own_and_assigned_tasks(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        Task::factory()->for($me, 'owner')->create(['title' => 'Mine']);
        Task::factory()->for($other, 'owner')->create(['title' => 'Theirs', 'assignee_id' => $me->id]);
        Task::factory()->for($other, 'owner')->create(['title' => 'Secret']);
        $this->actingAs($me)->get('/tasks')->assertSee('Mine')->assertSee('Theirs')->assertDontSee('Secret');
    }

    public function test_member_cannot_open_or_change_someone_elses_task(): void
    {
        $me = User::factory()->create();
        $task = Task::factory()->for(User::factory()->create(), 'owner')->create();
        $this->actingAs($me)->get("/tasks/{$task->id}")->assertForbidden();
        $this->actingAs($me)->get("/tasks/{$task->id}/edit")->assertForbidden();
        $this->actingAs($me)->put("/tasks/{$task->id}", ['title' => 'Hacked!', 'status' => 'pending', 'priority' => 'low'])->assertForbidden();
        $this->actingAs($me)->patchJson("/tasks/{$task->id}/toggle")->assertForbidden();
        $this->actingAs($me)->delete("/tasks/{$task->id}")->assertForbidden();
    }

    public function test_assignee_can_complete_but_not_delete(): void
    {
        $me = User::factory()->create();
        $task = Task::factory()->pending()->for(User::factory()->manager()->create(), 'owner')->create(['assignee_id' => $me->id]);
        $this->actingAs($me)->patchJson("/tasks/{$task->id}/toggle")->assertOk();
        $this->actingAs($me)->delete("/tasks/{$task->id}")->assertForbidden();
    }

    public function test_member_cannot_assign_tasks_to_others(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($me)->post('/tasks', ['title' => 'Delegated', 'status' => 'pending', 'priority' => 'low', 'assignee_id' => $other->id])->assertSessionHasErrors('assignee_id');
    }

    public function test_manager_sees_everything_and_can_assign(): void
    {
        $mgr = User::factory()->manager()->create();
        $member = User::factory()->create();
        Task::factory()->for($member, 'owner')->create(['title' => 'Member task']);
        $this->actingAs($mgr)->get('/tasks')->assertSee('Member task');
        $this->actingAs($mgr)->post('/tasks', ['title' => 'Delegated', 'status' => 'pending', 'priority' => 'low', 'assignee_id' => $member->id])->assertSessionHasNoErrors();
        $this->assertSame($member->id, Task::where('title', 'Delegated')->first()->assignee_id);
    }

    public function test_only_staff_reach_categories_activity_and_trash(): void
    {
        $member = User::factory()->create();
        foreach (['/categories', '/activity', '/tasks/trash', '/users'] as $url) {
            $this->actingAs($member)->get($url)->assertForbidden();
        }
        $mgr = User::factory()->manager()->create();
        foreach (['/categories', '/activity', '/tasks/trash'] as $url) {
            $this->actingAs($mgr)->get($url)->assertOk();
        }
        $this->actingAs($mgr)->get('/users')->assertForbidden();
    }

    public function test_only_admin_manages_users(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/users')->assertOk();
        $this->actingAs($admin)->post('/users', ['name' => 'New Person', 'email' => 'n@p.test', 'password' => 'secret123', 'role' => 'manager'])->assertRedirect('/users');
        $this->assertDatabaseHas('users', ['email' => 'n@p.test', 'role' => 'manager']);
    }

    public function test_admin_cannot_demote_or_delete_self(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put("/users/{$admin->id}", ['name' => 'Me', 'email' => $admin->email, 'role' => 'member'])->assertRedirect('/users');
        $this->assertTrue($admin->fresh()->isAdmin());
        $this->actingAs($admin)->delete("/users/{$admin->id}")->assertSessionHas('error');
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_deactivated_session_is_logged_out(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u);
        $u->update(['is_active' => false]);
        $this->get('/tasks')->assertRedirect('/login');
    }

    public function test_trash_restore_and_force_delete_permissions(): void
    {
        $task = Task::factory()->for(User::factory()->create(), 'owner')->create();
        $task->delete();
        $mgr = User::factory()->manager()->create();
        $this->actingAs($mgr)->post("/tasks/{$task->id}/restore")->assertRedirect();
        $this->assertNull($task->fresh()->deleted_at);
        $task->delete();
        $this->actingAs($mgr)->delete("/tasks/{$task->id}/force")->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->delete("/tasks/{$task->id}/force")->assertRedirect();
        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    public function test_categories_crud_for_manager(): void
    {
        $mgr = User::factory()->manager()->create();
        $this->actingAs($mgr)->post('/categories', ['name' => 'Ops', 'color' => '#112233'])->assertSessionHasNoErrors();
        $c = Category::first();
        $this->actingAs($mgr)->post('/categories', ['name' => 'Ops', 'color' => '#112233'])->assertSessionHasErrors('name');
        $this->actingAs($mgr)->put("/categories/{$c->id}", ['name' => 'Operations', 'color' => '#445566'])->assertSessionHasNoErrors();
        $this->actingAs($mgr)->delete("/categories/{$c->id}")->assertRedirect();
        $this->assertSame(0, Category::count());
    }

    public function test_assignee_cannot_edit_details_but_can_work(): void
    {
        $me = User::factory()->create();
        $task = Task::factory()->pending()->for(User::factory()->manager()->create(), 'owner')->create(['assignee_id' => $me->id]);
        $this->actingAs($me)->put("/tasks/{$task->id}", ['title' => 'Changed', 'status' => 'pending', 'priority' => 'low'])->assertForbidden();
        $this->actingAs($me)->post("/tasks/{$task->id}/subtasks", ['title' => 'Step one'])->assertRedirect();
    }

    public function test_only_one_admin_and_manager_limits(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create();
        $member = User::factory()->create();
        $this->actingAs($admin)->post('/users', ['name' => 'Second Admin', 'email' => 's@x.test', 'password' => 'password1', 'role' => 'admin'])->assertSessionHasErrors('role');
        $this->actingAs($manager)->post('/users', ['name' => 'New Mgr', 'email' => 'm@x.test', 'password' => 'password1', 'role' => 'manager'])->assertSessionHasErrors('role');
        $this->actingAs($manager)->get("/users/{$admin->id}/edit")->assertForbidden();
        $this->actingAs($manager)->delete("/users/{$member->id}")->assertForbidden();
        $this->actingAs($admin)->delete("/users/{$admin->id}")->assertForbidden();
    }

    public function test_each_person_keeps_their_own_order(): void
    {
        $a = User::factory()->create();
        $tasks = Task::factory()->count(3)->for($a, 'owner')->create();
        $svc = app(\App\Services\TaskService::class);
        $ids = $tasks->pluck('id')->all();
        $svc->reorder($a, array_reverse($ids));
        $first = $svc->query($a, [])->first()->id;
        $this->assertSame(end($ids), $first);
    }

    public function test_members_personal_tasks_are_invisible_to_staff_and_deleted_ones_are_theirs(): void
    {
        $admin = User::factory()->admin()->create();
        $me = User::factory()->create();
        $mine = Task::factory()->for($me, 'owner')->create(['title' => 'My private errand', 'assignee_id' => $me->id]);
        $this->actingAs($admin)->get('/tasks')->assertDontSee('My private errand');
        $this->actingAs($admin)->get("/tasks/{$mine->id}")->assertForbidden();

        $this->actingAs($me)->delete("/tasks/{$mine->id}")->assertRedirect();
        $this->assertSame(0, \App\Models\AppNotification::where('user_id', $admin->id)->count());
        $this->actingAs($admin)->get('/tasks/trash')->assertDontSee('My private errand');
        $this->actingAs($admin)->post("/tasks/{$mine->id}/restore")->assertForbidden();
        $this->actingAs($me)->get('/tasks/trash')->assertSee('My private errand');
        $this->actingAs($me)->post("/tasks/{$mine->id}/restore")->assertRedirect();
        $this->assertNull($mine->fresh()->deleted_at);
    }

    public function test_deleting_a_user_hands_their_work_tasks_to_the_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $mgr = User::factory()->manager()->create();
        $member = User::factory()->create();
        $work = Task::factory()->for($mgr, 'owner')->create(['assignee_id' => $member->id]);
        $this->actingAs($admin)->delete("/users/{$mgr->id}")->assertRedirect();
        $this->assertSame($admin->id, $work->fresh()->user_id);
    }
}
