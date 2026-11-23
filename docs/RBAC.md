# Role-based access control

Roles live in `users.role` and are cast to the `App\Enums\UserRole` enum.

## Design principles
1. **Members are peers and isolated.** A member sees only their own tasks and tasks staff assigned to them. They cannot see other members' work, the user list or the activity log.
2. **Only staff assign work.** A member can never assign a task to another member (or to anyone but themselves).
3. **A member's own tasks are private.** A *personal task* (created by a member, assigned to nobody else) is invisible to managers and the admin: not in lists, search, dashboard, reports, activity log, AI, notifications or the trash.
4. **There is exactly one admin.** Nobody can create, promote, demote, deactivate or delete the admin.
5. **Managers oversee, the admin owns.** A manager manages members; only the admin manages managers.
6. **Sign-up needs approval.** People can request an account; a manager or admin approves it before they can sign in.
7. **Completion is trust plus a trail.** A member completing a task notifies the assigner and is logged (who, when); staff can reopen it and comment. There is no review step.

## Capability matrix
| Capability | Admin | Manager | Member |
|---|:-:|:-:|:-:|
| See tasks | all work tasks | all work tasks | own + assigned to them |
| See a member's *personal* tasks | ❌ | ❌ | own only |
| Create tasks | ✅ | ✅ | ✅ (for themselves) |
| Assign a task to someone else | ✅ | ✅ | ❌ |
| Edit task details | any work task | any work task | only tasks they created |
| Complete / reopen, checklist, comment | any work task | any work task | tasks they own or are assigned |
| Delete a task (to trash) | any work task | any work task | only tasks they created |
| Trash: view and restore | work tasks | work tasks | **their own** deleted tasks |
| Permanently delete | work tasks | own tasks only | own tasks only |
| Trash auto-purge | after 30 days | after 30 days | after 30 days |
| Manage categories | ✅ | ✅ | ❌ |
| View activity log | ✅ (no personal tasks) | ✅ (no personal tasks) | ❌ |
| Approve sign-ups | ✅ | ✅ | ❌ |
| Create / edit / deactivate members | ✅ | ✅ | ❌ |
| Create / edit / deactivate managers | ✅ | ❌ | ❌ |
| Change roles | manager <-> member | ❌ | ❌ |
| Delete users | managers and members | ❌ | ❌ |
| Create another admin | ❌ | ❌ | ❌ |
| Per-person task order (drag-and-drop) | own order | own order | own order |
| Dashboard | whole workspace | whole workspace | own scope |
| AI assistant | ✅ | ✅ | ✅ (own scope) |

Deleting a user hands their **work tasks** to the admin (anything assigned to someone else, or everything for a manager). Only a member's personal tasks are removed with them; tasks assigned *to* them become unassigned.

## Enforced in layers
1. **Route middleware:** `role:admin,manager` group in `routes/web.php` (`RoleMiddleware` returns 403). The users routes are in that group; the controller then decides *whom* you may touch.
2. **Policy:** `TaskPolicy` with abilities `view`, `update` (details), `work` (complete, checklist, comments), `delete`, `restore`, `forceDelete`. Staff are denied another member's personal task (`staffSees`).
3. **Gates** in `AppServiceProvider`: `manage-users` (open the page), `manage-user` (act on one person, uses `User::canManageUser`), `delete-user`, `manage-categories`, `view-activity`, `view-trash`, `assign-tasks`, `use-ai`.
4. **Row-level scoping:** `Task::scopeVisibleTo($user)` is applied to every query (list, search, dashboard, reorder, AI, reports). `scopeNotPersonal` removes personal tasks from staff views; `ActivityLog::scopeWithoutPersonalTasks` does the same for the audit trail.
5. **Notifications:** `TaskService::notifyTeam` sends nothing to staff for a personal task.

Extra rules: a deactivated user is signed out on the next request (`EnsureUserIsActive`); nobody can change their own role through the users page; members who try to assign to someone else get a validation error. The AI assistant uses the same policies, gates and services, so it can never do more than the signed-in user.

Covered by `tests/Feature/RbacTest.php`.
