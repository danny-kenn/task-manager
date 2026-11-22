<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Console\Command;

/** Daily: tell people about their overdue tasks and give managers/admins a team digest. Scheduled in routes/console.php. */
class NotifyOverdue extends Command
{
    protected $signature = 'tasks:notify-overdue';
    protected $description = 'Send overdue-task reminders (members) and a team digest (managers/admins)';

    public function handle(Notifier $n): int
    {
        $overdue = Task::query()->with('assignee', 'owner')->where('status', 'pending')->whereDate('due_date', '<', today())->get();
        if ($overdue->isEmpty()) return self::SUCCESS;

        foreach ($overdue->groupBy(fn ($t) => $t->assignee_id ?: $t->user_id) as $uid => $tasks) {
            if ($u = User::find($uid)) {
                $n->send($u, 'task_overdue', $tasks->count().' overdue task'.($tasks->count() > 1 ? 's' : ''), $tasks->take(3)->pluck('title')->implode(' · '), route('tasks.index', ['due' => 'overdue']));
            }
        }
        $overdue = $overdue->reject(fn ($t) => $t->isPersonal());
        if ($overdue->isEmpty()) return self::SUCCESS;
        $top = $overdue->groupBy(fn ($t) => $t->assignee?->name ?? 'Unassigned')->map->count()->sortDesc()->take(3)->map(fn ($c, $k) => "$k ($c)")->implode(', ');
        $n->send($n->staff(), 'team_overdue', $overdue->count().' overdue tasks across the team', 'Most overdue: '.$top, route('tasks.index', ['due' => 'overdue']));
        $this->info("Notified about {$overdue->count()} overdue tasks.");

        return self::SUCCESS;
    }
}
