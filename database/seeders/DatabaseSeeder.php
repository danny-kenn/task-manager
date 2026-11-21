<?php

namespace Database\Seeders;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Subtask;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo data. Every account uses the password "password".
 *   admin@taskmanager.test    (Admin)
 *   manager@taskmanager.test  (Manager)
 *   member@taskmanager.test   (Member)
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(['email' => 'admin@taskmanager.test'], [
            'name' => 'Ada Admin', 'password' => 'password', 'role' => UserRole::Admin, 'avatar_color' => '#2c7a7b',
        ]);
        $manager = User::updateOrCreate(['email' => 'manager@taskmanager.test'], [
            'name' => 'Mark Manager', 'password' => 'password', 'role' => UserRole::Manager, 'avatar_color' => '#d4a017',
        ]);
        $member = User::updateOrCreate(['email' => 'member@taskmanager.test'], [
            'name' => 'Mary Member', 'password' => 'password', 'role' => UserRole::Member, 'avatar_color' => '#6f42c1',
        ]);
        $member2 = User::updateOrCreate(['email' => 'john@taskmanager.test'], [
            'name' => 'John Mwangi', 'password' => 'password', 'role' => UserRole::Member, 'avatar_color' => '#e83e8c',
        ]);

        $categories = collect([
            ['Work', '#2c7a7b', 'Day-to-day job tasks'],
            ['Personal', '#d4a017', 'Errands and life admin'],
            ['Learning', '#6f42c1', 'Courses, reading and practice'],
            ['Health', '#e83e8c', 'Exercise, sleep, appointments'],
            ['Finance', '#17a2b8', 'Bills, budgets, invoices'],
        ])->map(fn ($c) => Category::firstOrCreate(['name' => $c[0]], ['color' => $c[1], 'description' => $c[2], 'created_by' => $admin->id]));

        $samples = [
            ['Finish the Laravel task manager assessment', 'Build CRUD, validation, Bootstrap 4 UI and write the README.', 'urgent', 'pending', 2, 'Work', ['laravel', 'assessment']],
            ['Push the project to GitHub', 'Commit frequently with meaningful messages and share the repository link.', 'high', 'pending', 3, 'Work', ['git']],
            ['Prepare interview questions', 'Review Eloquent relationships, validation and middleware.', 'high', 'pending', 4, 'Learning', ['interview']],
            ['Read the Laravel policies documentation', null, 'medium', 'completed', -2, 'Learning', ['laravel']],
            ['Pay electricity bill', 'Use the paybill number on the last invoice.', 'medium', 'pending', -1, 'Finance', ['bills']],
            ['Book dentist appointment', null, 'low', 'pending', 9, 'Health', []],
            ['Go for a 5km run', 'Easy pace, no headphones.', 'low', 'completed', -1, 'Health', ['fitness']],
            ['Renew domain name', 'Check auto-renewal and update the card.', 'high', 'pending', 6, 'Work', ['domains']],
            ['Plan weekend trip to Naivasha', null, 'low', 'pending', 12, 'Personal', ['travel']],
            ['Back up the laptop', 'External drive plus cloud copy.', 'medium', 'completed', -3, 'Personal', ['backup']],
            ['Write unit tests for TaskService', 'Cover create, update, toggle and reorder.', 'high', 'pending', 5, 'Work', ['testing', 'laravel']],
            ['Update CV and LinkedIn', 'Add the new internship project.', 'medium', 'pending', 7, 'Personal', ['career']],
        ];

        $pos = -count($samples);
        foreach ($samples as [$title, $desc, $prio, $status, $dueIn, $cat, $tags]) {
            $task = Task::create([
                'user_id' => $member->id,
                'assignee_id' => $member->id,
                'category_id' => $categories->firstWhere('name', $cat)->id,
                'title' => $title,
                'description' => $desc,
                'priority' => TaskPriority::from($prio),
                'status' => TaskStatus::from($status),
                'due_date' => now()->addDays($dueIn)->toDateString(),
                'completed_at' => $status === 'completed' ? now()->subDays(abs($dueIn)) : null,
                'position' => $pos++,
            ]);
            $task->tags()->sync(Tag::fromNames($tags));
        }

        // A task owned by the manager and assigned to the member, with a checklist and a comment.
        $assigned = Task::create([
            'user_id' => $manager->id, 'assignee_id' => $member->id, 'category_id' => $categories[0]->id,
            'title' => 'Prepare the weekly status report', 'description' => 'Summarise what shipped, what is blocked and what is next.',
            'priority' => TaskPriority::High, 'status' => TaskStatus::Pending, 'due_date' => now()->addDays(1)->toDateString(), 'position' => -100,
        ]);
        foreach (['Collect numbers', 'Draft summary', 'Send to the team'] as $i => $t) {
            Subtask::create(['task_id' => $assigned->id, 'title' => $t, 'is_done' => $i === 0, 'position' => $i]);
        }
        TaskComment::create(['task_id' => $assigned->id, 'user_id' => $manager->id, 'body' => 'Please keep it to one page.']);

        // Some work for another member and a few random tasks so the manager/admin views look alive.
        Task::factory()->count(8)->create(['user_id' => $member2->id, 'category_id' => $categories->random()->id]);
        Task::factory()->count(5)->create(['user_id' => $manager->id, 'assignee_id' => $member2->id, 'category_id' => $categories->random()->id]);
    }
}
