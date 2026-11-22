<?php

namespace App\Services\Ai;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Http\Requests\TaskRequest;
use App\Models\Category;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Notifier;
use App\Services\TaskService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Everything the AI may PROPOSE. Nothing runs until the human taps Confirm, and on Confirm every action
 * is re-validated and re-authorised for the signed-in user, then goes through the SAME services as the web UI
 * (same rules, same timestamps, same audit trail, flagged source = "ai").
 */
class AiActions
{
    public const ACTIONS = [
        'create_task', 'update_task', 'set_status', 'delete_task', 'restore_task', 'force_delete_task',
        'add_subtask', 'toggle_subtask', 'delete_subtask', 'add_comment',
        'create_category', 'update_category', 'delete_category',
        'create_user', 'update_user',
        'update_my_name', 'set_notification_pref',
    ];

    /** Which actions a role may even be offered. Execution re-checks with Gate/Policy as well. */
    public static function allowedFor(User $u): array
    {
        $deny = [];
        if (! $u->isStaff()) $deny = array_merge($deny, ['restore_task', 'create_category', 'update_category', 'delete_category']);
        if (! $u->isAdmin()) $deny = array_merge($deny, ['force_delete_task']);
        if (! $u->isStaff()) $deny = array_merge($deny, ['create_user', 'update_user']);

        return array_values(array_diff(self::ACTIONS, $deny));
    }

    /* ------------------------------------------------------------------ cards (what the user sees) */

    public static function card(User $user, string $action, array $p): array
    {
        $trashed = in_array($action, ['restore_task', 'force_delete_task'], true);
        $task = null;
        if (isset($p['id']) && in_array($action, ['update_task', 'set_status', 'delete_task', 'restore_task', 'force_delete_task', 'add_subtask', 'toggle_subtask', 'delete_subtask', 'add_comment'], true)) {
            $task = $trashed
                ? Task::onlyTrashed()->find((int) $p['id'])
                : Task::query()->visibleTo($user)->find((int) $p['id']);
        }
        $opt = fn (array $v) => array_map(fn ($x) => ['value' => $x, 'label' => ucfirst($x)], $v);
        $cats = array_merge([['value' => '', 'label' => 'No category']], Category::orderBy('name')->get()->map(fn ($c) => ['value' => (string) $c->id, 'label' => $c->name])->all());
        $people = $user->isStaff()
            ? array_merge([['value' => '', 'label' => 'Unassigned']], User::where('is_active', true)->orderBy('name')->get()->map(fn ($x) => ['value' => (string) $x->id, 'label' => $x->name])->all())
            : [];

        $f = fn (string $key, string $label, string $type, $value = '', array $options = []) => compact('key', 'label', 'type', 'value', 'options');
        $cur = fn (string $k, $default = '') => $p[$k] ?? ($task ? self::currentValue($task, $k) : $default);
        $taskFields = fn (bool $update) => array_values(array_filter([
            $update ? $f('id', 'Task', 'hidden', $p['id'] ?? '') : null,
            $f('title', 'Title', 'text', $update ? $cur('title') : ($p['title'] ?? '')),
            $f('description', 'Description', 'textarea', $update ? $cur('description') : ($p['description'] ?? '')),
            $f('priority', 'Priority', 'select', $update ? $cur('priority', 'medium') : ($p['priority'] ?? 'medium'), $opt(TaskPriority::values())),
            $f('status', 'Status', 'select', $update ? $cur('status', 'pending') : ($p['status'] ?? 'pending'), $opt(TaskStatus::values())),
            $f('due_date', 'Due date', 'date', $update ? $cur('due_date') : ($p['due_date'] ?? '')),
            $f('category_id', 'Category', 'select', (string) ($update ? $cur('category_id') : ($p['category_id'] ?? '')), $cats),
            $people ? $f('assignee_id', 'Assign to', 'select', (string) ($update ? $cur('assignee_id') : ($p['assignee_id'] ?? '')), $people) : null,
            $f('tags', 'Tags', 'text', $update ? $cur('tags') : ($p['tags'] ?? '')),
        ]));

        $sub = isset($p['subtask_id']) ? Subtask::find((int) $p['subtask_id']) : null;
        $cat = isset($p['id']) && str_contains($action, 'category') ? Category::find((int) $p['id']) : null;
        $usr = isset($p['id']) && $action === 'update_user' ? User::find((int) $p['id']) : null;
        $roles = array_map(fn ($r) => ['value' => $r->value, 'label' => $r->label()], $user->assignableRoles() ?: [UserRole::Member]);
        $yn = [['value' => '1', 'label' => 'Yes'], ['value' => '0', 'label' => 'No']];

        $fields = match ($action) {
            'create_task' => $taskFields(false),
            'update_task' => $taskFields(true),
            'set_status' => [$f('id', 'Task', 'hidden', $p['id'] ?? ''), $f('status', 'New status', 'select', $p['status'] ?? 'completed', $opt(TaskStatus::values()))],
            'delete_task', 'restore_task', 'force_delete_task' => [$f('id', 'Task', 'hidden', $p['id'] ?? '')],
            'add_subtask' => [$f('id', 'Task', 'hidden', $p['id'] ?? ''), $f('title', 'Checklist item', 'text', $p['title'] ?? '')],
            'toggle_subtask' => [$f('id', 'Task', 'hidden', $p['id'] ?? ''), $f('subtask_id', 'Item', 'hidden', $p['subtask_id'] ?? '')],
            'delete_subtask' => [$f('id', 'Task', 'hidden', $p['id'] ?? ''), $f('subtask_id', 'Item', 'hidden', $p['subtask_id'] ?? '')],
            'add_comment' => [$f('id', 'Task', 'hidden', $p['id'] ?? ''), $f('body', 'Comment', 'textarea', $p['body'] ?? '')],
            'create_category' => [$f('name', 'Name', 'text', $p['name'] ?? ''), $f('color', 'Colour', 'color', $p['color'] ?? '#2c7a7b'), $f('description', 'Description', 'text', $p['description'] ?? '')],
            'update_category' => [$f('id', 'Category', 'hidden', $p['id'] ?? ''), $f('name', 'Name', 'text', $p['name'] ?? $cat?->name ?? ''), $f('color', 'Colour', 'color', $p['color'] ?? $cat?->color ?? '#2c7a7b'), $f('description', 'Description', 'text', $p['description'] ?? $cat?->description ?? '')],
            'delete_category' => [$f('id', 'Category', 'hidden', $p['id'] ?? '')],
            'create_user' => [$f('name', 'Name', 'text', $p['name'] ?? ''), $f('email', 'Email', 'text', $p['email'] ?? ''), $f('password', 'Password (blank = auto-generate)', 'text', $p['password'] ?? ''), $f('role', 'Role', 'select', $p['role'] ?? 'member', $roles), $f('is_active', 'Active', 'select', (string) ($p['is_active'] ?? '1'), $yn)],
            'update_user' => [$f('id', 'User', 'hidden', $p['id'] ?? ''), $f('name', 'Name', 'text', $p['name'] ?? $usr?->name ?? ''), $f('role', 'Role', 'select', $p['role'] ?? $usr?->role?->value ?? 'member', $roles), $f('is_active', 'Active', 'select', (string) ($p['is_active'] ?? ($usr ? (int) $usr->is_active : 1)), $yn)],
            'update_my_name' => [$f('name', 'Your name', 'text', $p['name'] ?? $user->name)],
            'set_notification_pref' => [
                $f('event', 'When', 'select', $p['event'] ?? 'task_assigned', array_map(fn ($k) => ['value' => $k, 'label' => Notifier::EVENTS[$k]['label']], array_keys(Notifier::EVENTS))),
                $f('channel', 'Channel', 'select', $p['channel'] ?? 'email', array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(Notifier::CHANNELS), Notifier::CHANNELS)),
                $f('on', 'Send it?', 'select', (string) ($p['on'] ?? '1'), $yn),
            ],
        };

        $meta = [
            'create_task' => ['Create a task', 'fa-plus'], 'update_task' => ['Update a task', 'fa-pen'], 'set_status' => ['Change status', 'fa-check'],
            'delete_task' => ['Delete a task', 'fa-trash'], 'restore_task' => ['Restore a task', 'fa-rotate-left'], 'force_delete_task' => ['Delete permanently', 'fa-skull-crossbones'],
            'add_subtask' => ['Add checklist item', 'fa-list-check'], 'toggle_subtask' => ['Tick / untick item', 'fa-square-check'], 'delete_subtask' => ['Remove checklist item', 'fa-xmark'],
            'add_comment' => ['Post a comment', 'fa-comment'], 'create_category' => ['Create a category', 'fa-tag'], 'update_category' => ['Update a category', 'fa-tag'],
            'delete_category' => ['Delete a category', 'fa-trash'], 'create_user' => ['Create a user', 'fa-user-plus'], 'update_user' => ['Update a user', 'fa-user-pen'],
            'update_my_name' => ['Change my name', 'fa-id-badge'], 'set_notification_pref' => ['Notification setting', 'fa-bell'],
        ][$action];

        $target = match (true) {
            $task !== null => "#{$task->id} {$task->title}".($sub ? ' › '.$sub->title : ''),
            $cat !== null => $cat->name,
            $usr !== null => $usr->name,
            default => null,
        };

        return [
            'id' => bin2hex(random_bytes(4)), 'action' => $action, 'label' => $meta[0], 'icon' => $meta[1],
            'danger' => in_array($action, ['delete_task', 'force_delete_task', 'delete_subtask', 'delete_category'], true),
            'target' => $target, 'fields' => $fields, 'done' => false,
            'readonly' => in_array($action, ['delete_task', 'restore_task', 'force_delete_task', 'toggle_subtask', 'delete_subtask', 'delete_category'], true),
        ];
    }

    private static function currentValue(Task $t, string $k): mixed
    {
        return match ($k) {
            'status', 'priority' => $t->{$k}->value,
            'due_date' => $t->due_date?->format('Y-m-d') ?? '',
            'tags' => $t->tags->pluck('name')->implode(', '),
            'assignee_id', 'category_id' => (string) ($t->{$k} ?? ''),
            default => $t->{$k} ?? '',
        };
    }

    /* ------------------------------------------------------------------ execution (after Confirm) */

    public static function execute(User $user, string $action, array $params): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            return ['ok' => false, 'message' => 'Unknown action.'];
        }
        if (! in_array($action, self::allowedFor($user), true)) {
            AiLog::warn('ai.execute.forbidden', ['action' => $action, 'role' => $user->role->value]);

            return ['ok' => false, 'message' => 'That needs a '.(str_contains($action, 'user') || $action === 'force_delete_task' ? 'administrator' : 'manager or admin').'. Your role ('.$user->role->label().') does not allow it.'];
        }

        $params = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $params);
        foreach (['due_date', 'category_id', 'description', 'tags', 'assignee_id'] as $k) {
            if (array_key_exists($k, $params) && $params[$k] === '') {
                $params[$k] = null;
            }
        }

        $svc = app(TaskService::class);
        $url = fn (Task $t) => route('tasks.show', $t);

        try {
            switch ($action) {
                case 'create_task':
                    Gate::forUser($user)->authorize('create', Task::class);
                    $data = self::validate($params, TaskRequest::baseRules());
                    self::memberAssignCheck($user, $data);
                    $task = $svc->create($user, $data, 'ai');

                    return ['ok' => true, 'message' => "Created “{$task->title}”.", 'task_id' => $task->id, 'url' => $url($task)];

                case 'update_task':
                    $task = self::findTask($user, $params);
                    Gate::forUser($user)->authorize('update', $task);
                    $rules = collect(TaskRequest::baseRules())->map(fn ($r) => array_merge(['sometimes'], $r))->all();
                    $data = self::validate(collect($params)->except('id')->all(), $rules);
                    self::memberAssignCheck($user, $data);
                    $svc->update($user, $task, $data, 'ai');

                    return ['ok' => true, 'message' => "Updated “{$task->title}”.", 'task_id' => $task->id, 'url' => $url($task)];

                case 'set_status':
                    $task = self::findTask($user, $params);
                    Gate::forUser($user)->authorize('toggle', $task);
                    $data = self::validate($params, ['status' => ['required', Rule::in(TaskStatus::values())]]);
                    $svc->setStatus($user, $task, TaskStatus::from($data['status']), 'ai');

                    return ['ok' => true, 'message' => "“{$task->title}” is now {$data['status']}.", 'task_id' => $task->id, 'url' => $url($task)];

                case 'delete_task':
                    $task = self::findTask($user, $params);
                    Gate::forUser($user)->authorize('delete', $task);
                    $svc->delete($user, $task, 'ai');

                    return ['ok' => true, 'message' => "Moved “{$task->title}” to the trash."];

                case 'restore_task':
                    $task = Task::onlyTrashed()->findOrFail((int) ($params['id'] ?? 0));
                    Gate::forUser($user)->authorize('restore', $task);
                    $svc->restore($user, $task);

                    return ['ok' => true, 'message' => "Restored “{$task->title}”.", 'task_id' => $task->id, 'url' => $url($task)];

                case 'force_delete_task':
                    $task = Task::onlyTrashed()->findOrFail((int) ($params['id'] ?? 0));
                    Gate::forUser($user)->authorize('forceDelete', $task);
                    $svc->forceDelete($user, $task);

                    return ['ok' => true, 'message' => "Permanently deleted “{$task->title}”."];

                case 'add_subtask':
                    $task = self::findTask($user, $params);
                    Gate::forUser($user)->authorize('work', $task);
                    $data = self::validate($params, ['title' => ['required', 'string', 'min:2', 'max:150']]);
                    $task->subtasks()->create($data + ['position' => (int) $task->subtasks()->max('position') + 1]);
                    ActivityLogger::record($user, $task->id, 'updated', "Added checklist item to “{$task->title}”", [], 'ai');

                    return ['ok' => true, 'message' => "Added “{$data['title']}” to the checklist.", 'task_id' => $task->id, 'url' => $url($task)];

                case 'toggle_subtask':
                case 'delete_subtask':
                    $task = self::findTask($user, $params);
                    Gate::forUser($user)->authorize('work', $task);
                    $st = $task->subtasks()->findOrFail((int) ($params['subtask_id'] ?? 0));
                    if ($action === 'delete_subtask') {
                        $st->delete();

                        return ['ok' => true, 'message' => "Removed “{$st->title}” from the checklist.", 'task_id' => $task->id, 'url' => $url($task)];
                    }
                    $st->update(['is_done' => ! $st->is_done]);

                    return ['ok' => true, 'message' => "“{$st->title}” is now ".($st->is_done ? 'done' : 'not done').'.', 'task_id' => $task->id, 'url' => $url($task)];

                case 'add_comment':
                    $task = self::findTask($user, $params);
                    Gate::forUser($user)->authorize('view', $task);
                    $data = self::validate($params, ['body' => ['required', 'string', 'min:2', 'max:2000']], ['body.required' => 'Write the comment first.']);
                    $task->comments()->create($data + ['user_id' => $user->id]);
                    ActivityLogger::record($user, $task->id, 'commented', "Commented on “{$task->title}”", [], 'ai');
                    app(Notifier::class)->send(collect([$task->owner, $task->assignee]), 'task_commented', "{$user->name} commented on “{$task->title}”", mb_substr($data['body'], 0, 140), $url($task), $user);

                    return ['ok' => true, 'message' => 'Comment posted.', 'task_id' => $task->id, 'url' => $url($task).'#comments'];

                case 'create_category':
                case 'update_category':
                    Gate::forUser($user)->authorize('manage-categories');
                    $cat = $action === 'update_category' ? Category::findOrFail((int) ($params['id'] ?? 0)) : null;
                    $data = self::validate($params, [
                        'name' => ['required', 'string', 'max:60', Rule::unique('categories', 'name')->ignore($cat?->id)],
                        'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'], 'description' => ['nullable', 'string', 'max:255'],
                    ], ['color.regex' => 'Pick a valid colour like #2c7a7b.']);
                    $cat ? $cat->update($data) : $cat = Category::create($data + ['created_by' => $user->id]);

                    return ['ok' => true, 'message' => ($action === 'create_category' ? 'Created' : 'Updated')." category “{$cat->name}”.", 'url' => route('categories.index')];

                case 'delete_category':
                    Gate::forUser($user)->authorize('manage-categories');
                    $cat = Category::findOrFail((int) ($params['id'] ?? 0));
                    $cat->delete();

                    return ['ok' => true, 'message' => "Deleted category “{$cat->name}”. Its tasks are now uncategorised."];

                case 'create_user':
                    Gate::forUser($user)->authorize('manage-users');
                    if (trim((string) ($params['password'] ?? '')) === '') {
                        $params['password'] = \Illuminate\Support\Str::password(10, true, true, false).'a1';
                        $generated = $params['password'];
                    }
                    $data = self::validate($params, [
                        'name' => ['required', 'string', 'min:2', 'max:100'], 'email' => ['required', 'email', 'max:150', 'unique:users,email'],
                        'password' => ['required', Password::min(8)->letters()->numbers()],
                        'role' => ['required', Rule::in(array_map(fn ($r) => $r->value, $user->assignableRoles()))],
                    ]);
                    $new = User::create($data + ['is_active' => ! array_key_exists('is_active', $params) || (string) $params['is_active'] === '1', 'approved_at' => now()]);
                    ActivityLogger::record($user, null, 'user_created', "Created user {$new->name} ({$new->role->label()})", [], 'ai');

                    return ['ok' => true, 'message' => "Created user {$new->name} ({$new->role->label()}).".(isset($generated) ? " Temporary password: {$generated} (ask them to change it)." : ''), 'url' => route('users.index')];

                case 'update_user':
                    $target = User::findOrFail((int) ($params['id'] ?? 0));
                    Gate::forUser($user)->authorize('manage-user', $target);
                    $data = self::validate($params, ['name' => ['required', 'string', 'min:2', 'max:100'], 'role' => ['required', Rule::in(array_map(fn ($r) => $r->value, $user->assignableRoles()))]]);
                    $data['is_active'] = (string) ($params['is_active'] ?? '1') === '1';
                    if ($target->id === $user->id) { // never lock the system out
                        $data['role'] = $target->role->value;
                        $data['is_active'] = true;
                    }
                    $roleChanged = $target->role->value !== $data['role'];
                    $target->update($data);
                    ActivityLogger::record($user, null, 'user_updated', "Updated user {$target->name} ({$target->role->label()}, ".($target->is_active ? 'active' : 'deactivated').')', [], 'ai');
                    if ($roleChanged) {
                        app(Notifier::class)->send($target, 'account', "Your role is now {$target->role->label()}", null, route('profile.edit'), $user);
                    }

                    return ['ok' => true, 'message' => "Updated {$target->name}".($target->id === $user->id ? ' (you cannot change your own role or deactivate yourself).' : '.'), 'url' => route('users.index')];

                case 'update_my_name':
                    $data = self::validate($params, ['name' => ['required', 'string', 'min:2', 'max:100']]);
                    $user->update($data);

                    return ['ok' => true, 'message' => "Your name is now {$user->name}."];

                case 'set_notification_pref':
                    $data = self::validate($params, [
                        'event' => ['required', Rule::in(array_keys(Notifier::EVENTS))], 'channel' => ['required', Rule::in(array_keys(Notifier::CHANNELS))], 'on' => ['required', 'in:0,1'],
                    ]);
                    $prefs = (array) $user->notify_prefs;
                    $prefs[$data['event']][$data['channel']] = $data['on'] === '1';
                    $user->update(['notify_prefs' => $prefs]);

                    return ['ok' => true, 'message' => Notifier::CHANNELS[$data['channel']].' notifications for “'.Notifier::EVENTS[$data['event']]['label'].'” are now '.($data['on'] === '1' ? 'on' : 'off').'.'];
            }
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            AiLog::warn('ai.execute.forbidden', ['action' => $action, 'role' => $user->role->value]);

            return ['ok' => false, 'message' => 'Your role does not allow that action.'];
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['ok' => false, 'message' => (string) collect($e->errors())->flatten()->first()];
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ['ok' => false, 'message' => 'That item could not be found (it may have been deleted).'];
        }

        return ['ok' => false, 'message' => 'Nothing was done.'];
    }

    private static function memberAssignCheck(User $user, array $data): void
    {
        if (! empty($data['assignee_id']) && ! $user->isStaff() && (int) $data['assignee_id'] !== $user->id) {
            throw \Illuminate\Validation\ValidationException::withMessages(['assignee_id' => 'Members can only assign tasks to themselves.']);
        }
    }

    private static function findTask(User $user, array $params): Task
    {
        return Task::query()->visibleTo($user)->findOrFail((int) ($params['id'] ?? 0));
    }

    private static function validate(array $data, array $rules, array $messages = []): array
    {
        return Validator::make($data, $rules, $messages + ['title.required' => 'The task needs a title.'])->validate();
    }
}
