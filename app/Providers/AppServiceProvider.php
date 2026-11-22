<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // The assessment requires Bootstrap 4 markup for pagination.
        Paginator::useBootstrapFour();

        // Coarse-grained, role-based abilities (task-level rules live in App\Policies\TaskPolicy).
        Gate::define('manage-users', fn (User $u) => $u->isStaff());                       // open the Users page
        Gate::define('manage-user', fn (User $u, User $target) => $u->canManageUser($target)); // act on one person
        Gate::define('delete-user', fn (User $u, User $target) => $u->isAdmin() && $u->canManageUser($target));
        Gate::define('manage-categories', fn (User $u) => $u->isStaff());
        Gate::define('view-activity', fn (User $u) => $u->isStaff());
        Gate::define('assign-tasks', fn (User $u) => $u->isStaff());
        Gate::define('view-trash', fn (User $u) => $u->is_active);
        Gate::define('use-ai', fn (User $u) => $u->is_active);
    }
}
