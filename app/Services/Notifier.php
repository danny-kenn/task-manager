<?php

namespace App\Services;

use App\Mail\EventMail;
use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * One place that tells people about things: the in-app bell (always instant), e-mail and Web Push.
 * Each user chooses, per event and per channel, what reaches them (Profile → Notifications).
 * E-mail and push run AFTER the response is sent (defer), so a slow mail server never slows a click.
 */
class Notifier
{
    /** event => label, help text, icon and the default for each channel */
    public const EVENTS = [
        'task_assigned' => ['label' => 'A task is assigned to me', 'icon' => 'fa-user-check', 'defaults' => ['in_app' => true, 'email' => true, 'push' => true]],
        'task_completed' => ['label' => 'A task I created or assigned is completed', 'icon' => 'fa-circle-check', 'defaults' => ['in_app' => true, 'email' => false, 'push' => true]],
        'task_updated' => ['label' => 'A task I am involved in is changed', 'icon' => 'fa-pen', 'defaults' => ['in_app' => true, 'email' => false, 'push' => false]],
        'task_commented' => ['label' => 'Someone comments on my task', 'icon' => 'fa-comment', 'defaults' => ['in_app' => true, 'email' => true, 'push' => true]],
        'task_deleted' => ['label' => 'A task I am involved in is deleted', 'icon' => 'fa-trash', 'defaults' => ['in_app' => true, 'email' => false, 'push' => false]],
        'task_overdue' => ['label' => 'A task of mine is overdue', 'icon' => 'fa-triangle-exclamation', 'defaults' => ['in_app' => true, 'email' => true, 'push' => true]],
        'account' => ['label' => 'Changes to my account or role', 'icon' => 'fa-user-shield', 'defaults' => ['in_app' => true, 'email' => true, 'push' => true]],

        // ---- oversight: managers and admins hear about the whole team, not only their own tasks
        'team_created' => ['for' => 'staff', 'label' => 'Team: a new task is created', 'icon' => 'fa-square-plus', 'defaults' => ['in_app' => true, 'email' => false, 'push' => true]],
        'team_assigned' => ['for' => 'staff', 'label' => 'Team: a task is assigned or reassigned', 'icon' => 'fa-people-arrows', 'defaults' => ['in_app' => true, 'email' => false, 'push' => true]],
        'team_completed' => ['for' => 'staff', 'label' => 'Team: a task is completed', 'icon' => 'fa-flag-checkered', 'defaults' => ['in_app' => true, 'email' => false, 'push' => true]],
        'team_updated' => ['for' => 'staff', 'label' => 'Team: a task is edited', 'icon' => 'fa-pen-ruler', 'defaults' => ['in_app' => true, 'email' => false, 'push' => false]],
        'team_commented' => ['for' => 'staff', 'label' => 'Team: someone comments on a task', 'icon' => 'fa-comments', 'defaults' => ['in_app' => true, 'email' => false, 'push' => false]],
        'team_deleted' => ['for' => 'staff', 'label' => 'Team: a task is deleted', 'icon' => 'fa-trash-can', 'defaults' => ['in_app' => true, 'email' => true, 'push' => true]],
        'team_overdue' => ['for' => 'staff', 'label' => 'Team: tasks are overdue (daily digest)', 'icon' => 'fa-bell', 'defaults' => ['in_app' => true, 'email' => true, 'push' => true]],
        'user_joined' => ['for' => 'admin', 'label' => 'Admin: a new person signs up', 'icon' => 'fa-user-plus', 'defaults' => ['in_app' => true, 'email' => true, 'push' => true]],
        'user_changed' => ['for' => 'admin', 'label' => 'Admin: a user, role or status is changed', 'icon' => 'fa-users-gear', 'defaults' => ['in_app' => true, 'email' => false, 'push' => true]],
    ];

    /** Which events does this user's role get to choose from? (members: their own tasks only; staff: the whole team; admin: + people) */
    public static function eventsFor(User $user): array
    {
        return array_filter(self::EVENTS, fn ($e) => match ($e['for'] ?? 'all') {
            'admin' => $user->isAdmin(),
            'staff' => $user->isStaff(),
            default => true,
        });
    }

    /** Everyone who oversees the work: active managers and admins (minus $exclude ids). */
    public function staff(array $exclude = [], bool $adminOnly = false): Collection
    {
        return User::where('is_active', true)->whereIn('role', $adminOnly ? ['admin'] : ['admin', 'manager'])->whereNotIn('id', $exclude)->get();
    }

    /** Tell managers/admins what happened to a task, skipping the people who already got the personal notice. */
    public function team(string $event, \App\Models\Task $task, string $title, ?string $body, ?User $actor, array $already = []): int
    {
        $skip = array_merge(array_map(fn ($u) => $u instanceof User ? $u->id : (int) $u, $already), $actor ? [$actor->id] : []);

        return $this->send($this->staff($skip), $event, $title, $body, route('tasks.show', $task->id), $actor);
    }

    public const CHANNELS = ['in_app' => 'In-app', 'email' => 'Email', 'push' => 'Push'];

    /**
     * @param  User|Collection|array  $users  recipients (the actor is removed automatically)
     */
    public function send(User|Collection|array $users, string $event, string $title, ?string $body = null, ?string $url = null, ?User $actor = null): int
    {
        $users = $users instanceof User ? collect([$users]) : collect($users);
        $users = $users->filter()->unique('id')->reject(fn (User $u) => $actor && $u->id === $actor->id)->filter(fn (User $u) => $u->is_active && isset(self::eventsFor($u)[$event]));
        $icon = self::EVENTS[$event]['icon'] ?? 'fa-bell';
        $sent = 0;

        foreach ($users as $user) {
            try {
                if ($user->wantsNotification($event, 'in_app')) {
                    AppNotification::create(['user_id' => $user->id, 'event' => $event, 'title' => $title, 'body' => $body, 'url' => $url, 'icon' => $icon, 'actor_id' => $actor?->id]);
                    $sent++;
                }
                if ($user->wantsNotification($event, 'email') && $user->email) {
                    $this->later(fn () => Mail::to($user->email)->send(new EventMail($user, $title, $body, $url)));
                }
                if ($user->wantsNotification($event, 'push')) {
                    $this->later(fn () => app(WebPush::class)->notifyUser($user));
                }
            } catch (\Throwable $e) {
                report($e); // a notification problem must never break the action that caused it
            }
        }

        return $sent;
    }

    private function later(callable $fn): void
    {
        $safe = function () use ($fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                report($e);
            }
        };
        app()->runningUnitTests() ? $safe() : defer($safe);
    }
}
