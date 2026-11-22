<?php

namespace App\Services;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;

/** Numbers for the dashboard, scoped to what the user is allowed to see. */
class StatsService
{
    public function for(User $user): array
    {
        $base = fn () => Task::query()->visibleTo($user);

        $total = $base()->count();
        $completed = $base()->where('status', TaskStatus::Completed->value)->count();
        $pending = $total - $completed;
        $overdue = $base()->overdue()->count();
        $dueToday = $base()->where('status', 'pending')->whereDate('due_date', today())->count();

        // Completed per day for the last 14 days (grouped in PHP so it is identical on MySQL and SQLite).
        $from = today()->subDays(13);
        $byDay = $base()->where('status', 'completed')->where('completed_at', '>=', $from)->get(['completed_at'])
            ->groupBy(fn ($t) => $t->completed_at->toDateString())->map->count();
        $trend = collect(range(0, 13))->map(function ($i) use ($from, $byDay) {
            $d = Carbon::parse($from)->addDays($i);

            return ['label' => $d->format('D j'), 'value' => (int) ($byDay[$d->toDateString()] ?? 0)];
        });

        $priority = collect(TaskPriority::cases())->map(fn ($p) => [
            'label' => $p->label(), 'value' => $base()->where('status', 'pending')->where('priority', $p->value)->count(),
        ]);

        $categories = Category::query()->get()->map(fn ($c) => [
            'label' => $c->name, 'color' => $c->color, 'value' => $base()->where('category_id', $c->id)->count(),
        ])->filter(fn ($c) => $c['value'] > 0)->values();

        $upcoming = $base()->with('category')->where('status', 'pending')->whereNotNull('due_date')
            ->orderBy('due_date')->limit(6)->get();

        $recent = ActivityLog::query()->with('user')
            ->when($user->isStaff(), fn ($q) => $q->withoutPersonalTasks(), fn ($q) => $q->where('user_id', $user->id))
            ->latest('id')->limit(8)->get();

        $workload = $user->isStaff()
            ? User::query()->where('is_active', true)->withCount([
                'assignedTasks as pending_count' => fn ($q) => $q->notPersonal()->where('status', 'pending'),
                'assignedTasks as done_count' => fn ($q) => $q->notPersonal()->where('status', 'completed'),
            ])->orderByDesc('pending_count')->limit(6)->get()
            : collect();

        return [
            'total' => $total, 'pending' => $pending, 'completed' => $completed, 'overdue' => $overdue, 'due_today' => $dueToday,
            'rate' => $total ? (int) round($completed / $total * 100) : 0,
            'trend' => $trend, 'priority' => $priority, 'categories' => $categories,
            'upcoming' => $upcoming, 'recent' => $recent, 'workload' => $workload,
        ];
    }
}
