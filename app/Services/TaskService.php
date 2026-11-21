<?php

namespace App\Services;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * All task business logic lives here so controllers, the AI assistant and the seeder share ONE code path
 * (and therefore the same rules, timestamps and audit trail).
 */
class TaskService
{
    private const TRACKED = ['title', 'description', 'status', 'priority', 'due_date', 'category_id', 'assignee_id'];

    /* ------------------------------------------------------------------ querying */

    /** Build the filtered, sorted list query used by the task index page and the AI "list" tool. */
    public function query(User $user, array $f = []): Builder
    {
        $q = Task::query()->visibleTo($user)->with(['category', 'tags', 'assignee', 'owner'])->withCount([
            'subtasks', 'subtasks as subtasks_done_count' => fn ($s) => $s->where('is_done', true), 'comments',
        ]);

        $q->search($f['q'] ?? null);

        if (! empty($f['status']) && in_array($f['status'], TaskStatus::values(), true)) {
            $q->where('status', $f['status']);
        }
        if (! empty($f['priority']) && in_array($f['priority'], TaskPriority::values(), true)) {
            $q->where('priority', $f['priority']);
        }
        if (! empty($f['category_id'])) {
            $q->where('category_id', (int) $f['category_id']);
        }
        if (! empty($f['tag'])) {
            $q->whereHas('tags', fn ($t) => $t->where('slug', $f['tag']));
        }
        if (! empty($f['assignee'])) {
            $f['assignee'] === 'me'
                ? $q->where('assignee_id', $user->id)
                : $q->where('assignee_id', (int) $f['assignee']);
        }
        match ($f['due'] ?? null) {
            'overdue' => $q->overdue(),
            'today' => $q->whereDate('due_date', today()),
            'week' => $q->whereBetween('due_date', [today(), today()->addDays(7)]),
            'none' => $q->whereNull('due_date'),
            default => null,
        };

        match ($f['sort'] ?? 'manual') {
            'newest' => $q->latest('created_at')->orderByDesc('id'),
            'oldest' => $q->oldest('created_at')->orderBy('id'),
            'due' => $q->orderByRaw('due_date is null')->orderBy('due_date'),
            'priority' => $q->orderByRaw("case priority when 'urgent' then 4 when 'high' then 3 when 'medium' then 2 else 1 end desc")->orderByDesc('id'),
            'title' => $q->orderBy('title'),
            default => $q->manualOrder(),
        };

        return $q;
    }

    /* ------------------------------------------------------------------ commands */

    public function create(User $actor, array $data, string $source = 'web'): Task
    {
        return DB::transaction(function () use ($actor, $data, $source) {
            $status = TaskStatus::from($data['status'] ?? 'pending');

            $task = Task::create([
                'user_id' => $actor->id,
                'assignee_id' => $this->resolveAssignee($actor, $data['assignee_id'] ?? null),
                'category_id' => $data['category_id'] ?? null,
                'title' => trim($data['title']),
                'description' => $this->clean($data['description'] ?? null),
                'status' => $status,
                'priority' => TaskPriority::from($data['priority'] ?? 'medium'),
                'due_date' => $data['due_date'] ?? null,
                'completed_at' => $status === TaskStatus::Completed ? now() : null,
                'position' => (int) (Task::withTrashed()->min('position') ?? 0) - 1, // new tasks go to the top
            ]);

            if (array_key_exists('tags', $data)) {
                $task->tags()->sync(Tag::fromNames($data['tags']));
            }

            ActivityLogger::record($actor, $task->id, 'created', "Created task “{$task->title}”", ['status' => $status->value, 'priority' => $task->priority->value], $source);

            return $task->load(['category', 'tags', 'assignee', 'owner']);
        });
    }

    public function update(User $actor, Task $task, array $data, string $source = 'web'): Task
    {
        return DB::transaction(function () use ($actor, $task, $data, $source) {
            $before = $task->only(self::TRACKED);
            $oldStatus = $task->status;

            $fill = [];
            foreach (['title', 'description', 'priority', 'due_date', 'category_id'] as $k) {
                if (array_key_exists($k, $data)) {
                    $fill[$k] = match ($k) {
                        'title' => trim($data[$k]),
                        'description' => $this->clean($data[$k]),
                        'priority' => TaskPriority::from($data[$k]),
                        default => $data[$k] ?: null,
                    };
                }
            }
            if (array_key_exists('assignee_id', $data) && $actor->isStaff()) {
                $fill['assignee_id'] = $data['assignee_id'] ?: null;
            }
            if (array_key_exists('status', $data)) {
                $new = TaskStatus::from($data['status']);
                $fill['status'] = $new;
                if ($new !== $oldStatus) {
                    $fill['completed_at'] = $new === TaskStatus::Completed ? now() : null;
                }
            }
            $task->fill($fill)->save();

            $tagsChanged = false;
            if (array_key_exists('tags', $data)) {
                $changes = $task->tags()->sync(Tag::fromNames($data['tags']));
                $tagsChanged = (bool) ($changes['attached'] || $changes['detached']);
            }

            $after = $task->fresh()->only(self::TRACKED);
            $diff = $this->diff($before, $after);
            if ($tagsChanged) {
                $diff['tags'] = ['from' => null, 'to' => $task->tags()->pluck('name')->implode(', ')];
            }

            if ($diff) {
                $statusOnly = array_keys($diff) === ['status'];
                ActivityLogger::record(
                    $actor, $task->id, $statusOnly ? 'status_changed' : 'updated',
                    $statusOnly ? "Marked “{$task->title}” as {$task->status->label()}" : "Updated “{$task->title}” (".implode(', ', array_keys($diff)).')',
                    $diff, $source
                );
            }

            return $task->load(['category', 'tags', 'assignee', 'owner']);
        });
    }

    /** Flip Pending <-> Completed (or force a status). */
    public function setStatus(User $actor, Task $task, ?TaskStatus $status = null, string $source = 'web'): Task
    {
        $status ??= $task->status->opposite();

        return $this->update($actor, $task, ['status' => $status->value], $source);
    }

    public function delete(User $actor, Task $task, string $source = 'web'): void
    {
        $title = $task->title;
        $task->delete(); // soft delete: recoverable from the trash
        ActivityLogger::record($actor, $task->id, 'deleted', "Moved “{$title}” to the trash", [], $source);
    }

    public function restore(User $actor, Task $task): Task
    {
        $task->restore();
        ActivityLogger::record($actor, $task->id, 'restored', "Restored “{$task->title}” from the trash");

        return $task;
    }

    public function forceDelete(User $actor, Task $task): void
    {
        $title = $task->title;
        $id = $task->id;
        $task->forceDelete();
        ActivityLogger::record($actor, $id, 'deleted', "Permanently deleted “{$title}”", ['permanent' => true]);
    }

    /**
     * Persist a drag-and-drop order. $ids is the NEW order of (a subset of) the visible tasks.
     * The existing position values of those rows are re-used so tasks on other pages keep their place.
     */
    public function reorder(User $actor, array $ids): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $tasks = Task::query()->visibleTo($actor)->whereIn('id', $ids)->get()->keyBy('id');
        $ordered = array_values(array_filter($ids, fn ($id) => $tasks->has($id)));
        if (count($ordered) < 2) {
            return 0;
        }

        $base = (int) $tasks->min('position');
        DB::transaction(function () use ($ordered, $base) {
            foreach ($ordered as $i => $id) {
                Task::whereKey($id)->update(['position' => $base + $i]);
            }
        });
        ActivityLogger::record($actor, null, 'reordered', 'Reordered '.count($ordered).' tasks', ['ids' => $ordered]);

        return count($ordered);
    }

    /* ------------------------------------------------------------------ helpers */

    /** Members can only keep a task for themselves; staff may assign to any active user. */
    private function resolveAssignee(User $actor, mixed $assigneeId): ?int
    {
        if ($actor->isStaff()) {
            return $assigneeId ? (int) $assigneeId : null;
        }

        return $actor->id;
    }

    private function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }

    private function diff(array $before, array $after): array
    {
        $out = [];
        foreach ($after as $k => $v) {
            $a = $before[$k] instanceof \BackedEnum ? $before[$k]->value : ($before[$k] instanceof \DateTimeInterface ? $before[$k]->format('Y-m-d') : $before[$k]);
            $b = $v instanceof \BackedEnum ? $v->value : ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v);
            if ((string) $a !== (string) $b) {
                $out[$k] = ['from' => $k === 'description' ? ($a ? '…' : null) : $a, 'to' => $k === 'description' ? ($b ? '…' : null) : $b];
            }
        }

        return $out;
    }
}
