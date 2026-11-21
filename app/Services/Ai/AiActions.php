<?php

namespace App\Services\Ai;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Http\Requests\TaskRequest;
use App\Models\Category;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Everything the AI may PROPOSE. Nothing runs until the human taps Confirm, and on Confirm every action
 * is re-validated and re-authorised for the signed-in user, then goes through the SAME TaskService as the web UI
 * (same rules, same timestamps, same audit trail, flagged source = "ai").
 */
class AiActions
{
    public const ACTIONS = ['create_task', 'update_task', 'set_status', 'delete_task'];

    /* ------------------------------------------------------------------ cards (what the user sees) */

    public static function card(User $user, string $action, array $p): array
    {
        $task = isset($p['id']) ? Task::query()->visibleTo($user)->find((int) $p['id']) : null;
        $opt = fn (array $v) => array_map(fn ($x) => ['value' => $x, 'label' => ucfirst($x)], $v);
        $cats = array_merge([['value' => '', 'label' => 'No category']], Category::orderBy('name')->get()->map(fn ($c) => ['value' => (string) $c->id, 'label' => $c->name])->all());

        $f = fn (string $key, string $label, string $type, $value = '', array $options = []) => compact('key', 'label', 'type', 'value', 'options');
        $cur = fn (string $k, $default = '') => $p[$k] ?? ($task ? self::currentValue($task, $k) : $default);

        $fields = match ($action) {
            'create_task' => [
                $f('title', 'Title', 'text', $p['title'] ?? ''),
                $f('description', 'Description', 'textarea', $p['description'] ?? ''),
                $f('priority', 'Priority', 'select', $p['priority'] ?? 'medium', $opt(TaskPriority::values())),
                $f('status', 'Status', 'select', $p['status'] ?? 'pending', $opt(TaskStatus::values())),
                $f('due_date', 'Due date', 'date', $p['due_date'] ?? ''),
                $f('category_id', 'Category', 'select', (string) ($p['category_id'] ?? ''), $cats),
                $f('tags', 'Tags', 'text', $p['tags'] ?? ''),
            ],
            'update_task' => [
                $f('id', 'Task', 'hidden', $p['id'] ?? ''),
                $f('title', 'Title', 'text', $cur('title')),
                $f('description', 'Description', 'textarea', $cur('description')),
                $f('priority', 'Priority', 'select', $cur('priority', 'medium'), $opt(TaskPriority::values())),
                $f('status', 'Status', 'select', $cur('status', 'pending'), $opt(TaskStatus::values())),
                $f('due_date', 'Due date', 'date', $cur('due_date')),
                $f('category_id', 'Category', 'select', (string) $cur('category_id'), $cats),
                $f('tags', 'Tags', 'text', $cur('tags')),
            ],
            'set_status' => [
                $f('id', 'Task', 'hidden', $p['id'] ?? ''),
                $f('status', 'New status', 'select', $p['status'] ?? 'completed', $opt(TaskStatus::values())),
            ],
            'delete_task' => [$f('id', 'Task', 'hidden', $p['id'] ?? '')],
        };

        return [
            'id' => bin2hex(random_bytes(4)),
            'action' => $action,
            'label' => match ($action) {
                'create_task' => 'Create a task', 'update_task' => 'Update a task',
                'set_status' => 'Change status', 'delete_task' => 'Delete a task',
            },
            'icon' => match ($action) {
                'create_task' => 'fa-plus', 'update_task' => 'fa-pen', 'set_status' => 'fa-check', 'delete_task' => 'fa-trash',
            },
            'danger' => $action === 'delete_task',
            'target' => $task ? "#{$task->id} {$task->title}" : null,
            'fields' => $fields,
            'done' => false,
        ];
    }

    private static function currentValue(Task $t, string $k): mixed
    {
        return match ($k) {
            'status', 'priority' => $t->{$k}->value,
            'due_date' => $t->due_date?->format('Y-m-d') ?? '',
            'tags' => $t->tags->pluck('name')->implode(', '),
            default => $t->{$k} ?? '',
        };
    }

    /* ------------------------------------------------------------------ execution (after Confirm) */

    public static function execute(User $user, string $action, array $params): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            return ['ok' => false, 'message' => 'Unknown action.'];
        }

        $params = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $params);
        foreach (['due_date', 'category_id', 'description', 'tags'] as $k) {
            if (array_key_exists($k, $params) && $params[$k] === '') {
                $params[$k] = null;
            }
        }

        $svc = app(TaskService::class);

        try {
            switch ($action) {
                case 'create_task':
                    Gate::forUser($user)->authorize('create', Task::class);
                    $data = self::validate($params, TaskRequest::baseRules());
                    $task = $svc->create($user, $data, 'ai');

                    return ['ok' => true, 'message' => "Created “{$task->title}”.", 'task_id' => $task->id, 'url' => route('tasks.show', $task)];

                case 'update_task':
                    $task = self::findTask($user, $params);
                    Gate::forUser($user)->authorize('update', $task);
                    $rules = collect(TaskRequest::baseRules())->map(fn ($r) => array_merge(['sometimes'], $r))->all();
                    $data = self::validate(collect($params)->except('id')->all(), $rules);
                    $svc->update($user, $task, $data, 'ai');

                    return ['ok' => true, 'message' => "Updated “{$task->title}”.", 'task_id' => $task->id, 'url' => route('tasks.show', $task)];

                case 'set_status':
                    $task = self::findTask($user, $params);
                    Gate::forUser($user)->authorize('toggle', $task);
                    $data = self::validate($params, ['status' => ['required', Rule::in(TaskStatus::values())]]);
                    $svc->setStatus($user, $task, TaskStatus::from($data['status']), 'ai');

                    return ['ok' => true, 'message' => "“{$task->title}” is now {$data['status']}.", 'task_id' => $task->id, 'url' => route('tasks.show', $task)];

                case 'delete_task':
                    $task = self::findTask($user, $params);
                    Gate::forUser($user)->authorize('delete', $task);
                    $svc->delete($user, $task, 'ai');

                    return ['ok' => true, 'message' => "Moved “{$task->title}” to the trash."];
            }
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            AiLog::warn('ai.execute.forbidden', ['action' => $action, 'role' => $user->role->value]);

            return ['ok' => false, 'message' => 'Your role does not allow that action.'];
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['ok' => false, 'message' => (string) collect($e->errors())->flatten()->first()];
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ['ok' => false, 'message' => 'That task could not be found (it may have been deleted).'];
        }

        return ['ok' => false, 'message' => 'Nothing was done.'];
    }

    private static function findTask(User $user, array $params): Task
    {
        return Task::query()->visibleTo($user)->findOrFail((int) ($params['id'] ?? 0));
    }

    private static function validate(array $data, array $rules): array
    {
        return Validator::make($data, $rules, [
            'title.required' => 'The task needs a title.',
        ])->validate();
    }
}
