# Role-based access control

Roles live in `users.role` and are cast to the `App\Enums\UserRole` enum.

| Capability | Admin | Manager | Member |
|---|:-:|:-:|:-:|
| See own / assigned tasks | ✅ | ✅ | ✅ |
| See **all** tasks | ✅ | ✅ | ❌ |
| Create tasks | ✅ | ✅ | ✅ |
| Edit / complete a task | any | any | own or assigned |
| Delete a task (to trash) | any | any | **own only** |
| Assign a task to someone else | ✅ | ✅ | ❌ (self only) |
| Trash: view and restore | ✅ | ✅ | ❌ |
| Permanently delete | ✅ | ❌ | ❌ |
| Manage categories | ✅ | ✅ | ❌ |
| View activity log | ✅ | ✅ | ❌ |
| Manage users and roles | ✅ | ❌ | ❌ |
| Dashboard | whole workspace | whole workspace | own scope |
| AI assistant | ✅ | ✅ | ✅ (own scope) |

## Enforced in four layers
1. **Route middleware**: `role:admin,manager` and `role:admin` groups in `routes/web.php` (`RoleMiddleware` returns 403).
2. **Policy**: `App\Policies\TaskPolicy` (`view`, `update`, `toggle`, `delete`, `restore`, `forceDelete`), called with `authorize()` in controllers and `@can` in Blade.
3. **Gates** in `AppServiceProvider`: `manage-users`, `manage-categories`, `view-activity`, `view-trash`, `assign-tasks`, `use-ai`.
4. **Row-level scoping**: `Task::scopeVisibleTo($user)` is applied to every query (list, search, dashboard, reorder, AI), so a member can never even *count* tasks they should not see.

Extra rules: a deactivated user is logged out on their next request (`EnsureUserIsActive`); an admin cannot demote, deactivate or delete themselves; members trying to assign a task to someone else get a validation error.

All of this is covered by `tests/Feature/RbacTest.php`.
