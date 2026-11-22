<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

/**
 * Task-level rules.
 *   admin    : everything
 *   manager  : everything except permanent deletion
 *   member   : sees tasks they own or that are assigned to them.
 *              EDIT/DELETE only the tasks they created. On tasks assigned to them by someone else they can
 *              complete/reopen, tick the checklist and comment (the "work" ability), but not change or delete them.
 */
class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Task $task): bool
    {
        return $this->staffSees($user, $task) || $this->involved($user, $task);
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    /** Change the task's details (title, due date, priority, assignee...). */
    public function update(User $user, Task $task): bool
    {
        return $this->staffSees($user, $task) || $task->user_id === $user->id;
    }

    /** Do the work: complete/reopen, checklist, comments. Anyone involved may. */
    public function work(User $user, Task $task): bool
    {
        return $this->staffSees($user, $task) || $this->involved($user, $task);
    }

    public function toggle(User $user, Task $task): bool
    {
        return $this->work($user, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->staffSees($user, $task) || $task->user_id === $user->id;
    }

    /** Your own deleted tasks are yours to restore; staff restore work tasks only. */
    public function restore(User $user, Task $task): bool
    {
        return $task->user_id === $user->id || $this->staffSees($user, $task);
    }

    public function forceDelete(User $user, Task $task): bool
    {
        return $task->user_id === $user->id || ($user->isAdmin() && $this->staffSees($user, $task));
    }

    /** Staff may open/manage work tasks, never another person's personal task. */
    private function staffSees(User $user, Task $task): bool
    {
        return $user->isStaff() && ($task->user_id === $user->id || ! $task->isPersonal());
    }

    private function involved(User $user, Task $task): bool
    {
        return $task->user_id === $user->id || $task->assignee_id === $user->id;
    }
}
