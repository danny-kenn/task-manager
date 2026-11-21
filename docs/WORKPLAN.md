# Work plan (5 days)

| Day | Focus | Deliverable | Status |
|---|---|---|---|
| 1 | Environment (XAMPP, Laravel 12), requirements analysis, database design | Migrations, ERD, enums, models, factories, seeder | ✅ |
| 2 | Authentication, RBAC, service layer, form requests | Login/register, `RoleMiddleware`, `TaskPolicy`, `TaskService` | ✅ |
| 3 | Core features: create, list, edit, delete (modal), status toggle; Bootstrap 4 UI | Task CRUD views, validation, toast + AJAX toggle | ✅ |
| 4 | Bonus + extras: search/filter, pagination, drag-and-drop, dashboard, categories, tags, checklist, comments, trash, audit log, user admin | All bonus features | ✅ |
| 5 | AI assistant, test suite, documentation, final review | Gemini + offline assistant, 40 tests, README + docs | ✅ |

## Test plan (automated, `php artisan test`)
Authentication and throttling · task create/edit validation (required fields, friendly messages) · status toggle · search, pagination · reordering · role boundaries for every route · policy edge cases (assignee can complete but not delete) · trash/restore/force delete · AI propose → confirm → audit · AI cannot bypass permissions · every page renders for every role.

## Possible next steps
MySQL FULLTEXT search, email reminders for due dates (queue is already configured), file attachments, REST API with Sanctum tokens, streaming AI replies.
