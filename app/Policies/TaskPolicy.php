<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

/**
 * Task-level rules.
 *   admin    : everything
 *   manager  : everything except permanent deletion
 *   member   : tasks they own or that are assigned to them (delete only their own)
 */
class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Task $task): bool
    {
        return $user->isStaff() || $this->involved($user, $task);
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, Task $task): bool
    {
        return $user->isStaff() || $this->involved($user, $task);
    }

    /** Completing / reopening follows the same rule as editing. */
    public function toggle(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->isStaff() || $task->user_id === $user->id;
    }

    public function restore(User $user, Task $task): bool
    {
        return $user->isStaff();
    }

    public function forceDelete(User $user, Task $task): bool
    {
        return $user->isAdmin();
    }

    private function involved(User $user, Task $task): bool
    {
        return $task->user_id === $user->id || $task->assignee_id === $user->id;
    }
}
