<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Seeds the demo data and renders every page for every role: catches Blade/SQL errors early. */
class PagesRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_renders_for_each_role(): void
    {
        $this->seed(DatabaseSeeder::class);
        $task = Task::where('title', 'Prepare the weekly status report')->first();
        $trashed = Task::factory()->create(['title' => 'In the bin']);
        $trashed->delete();

        $always = ['/dashboard', '/tasks', '/tasks/create', "/tasks/{$task->id}", "/tasks/{$task->id}/edit", '/profile', '/ai/history',
            '/tasks?sort=due&status=pending&per_page=20', '/tasks?due=overdue&priority=high', '/tasks?assignee=me'];
        $staff = ['/categories', '/activity', '/activity?source=ai&action=created', '/tasks/trash'];

        foreach (['admin@taskmanager.test' => true, 'manager@taskmanager.test' => true, 'member@taskmanager.test' => false] as $email => $isStaff) {
            $user = User::where('email', $email)->first();
            foreach ($always as $url) {
                $this->actingAs($user)->get($url)->assertOk();
            }
            foreach ($staff as $url) {
                $isStaff ? $this->actingAs($user)->get($url)->assertOk() : $this->actingAs($user)->get($url)->assertForbidden();
            }
        }

        $admin = User::where('email', 'admin@taskmanager.test')->first();
        foreach (['/users', '/users/create', '/users/'.User::where('email', 'member@taskmanager.test')->first()->id.'/edit', '/users?role=member&q=a'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
        $this->actingAs($admin)->get('/tasks/trash')->assertSee('In the bin');
    }

    public function test_guest_pages_and_error_pages(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in');
        $this->get('/register')->assertOk();
        $this->get('/nope')->assertNotFound()->assertSee('Page not found');
        $this->getJson('/tasks/999')->assertStatus(401);
    }

    public function test_dashboard_numbers(): void
    {
        $this->seed(DatabaseSeeder::class);
        $member = User::where('email', 'member@taskmanager.test')->first();
        $this->actingAs($member)->get('/dashboard')->assertSee('Completed in the last 14 days')->assertSee('Coming up');
        $this->actingAs($member)->get('/tasks')->assertSee('Finish the Laravel task manager assessment');
    }
}
