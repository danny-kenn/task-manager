# Architecture

```
Browser (Bootstrap 4, jQuery, SortableJS, Chart.js)
   │  forms (POST/PUT/DELETE)           AJAX (toggle, reorder, AI)
   ▼
Routes (routes/web.php)  ── middleware: auth, active, role:…
   ▼
Controllers  ── authorise (Policy/Gate) ── validate (FormRequest)
   ▼
Services     TaskService · StatsService · ActivityLogger · Ai/*
   ▼
Eloquent models (scopes: visibleTo, search, overdue, manualOrder)
   ▼
MySQL
```

## Layers
- **Controllers** are thin: authorise, validate, call a service, return a view or JSON.
- **`TaskService`** owns every business rule: `query()` (filters + sorting), `create`, `update` (with diff logging), `setStatus`, `delete`, `restore`, `forceDelete`, `reorder`. The web UI, the AI assistant and the seeder all call it, so a task created by chat gets the same timestamps, validation and audit entry as one created in the form.
- **`TaskRequest`** is the single definition of the validation rules, used for create, edit and AI execution.
- **Enums** (`TaskStatus`, `TaskPriority`, `UserRole`) carry labels, badge colours and icons, so views never hard-code strings.
- **`ActivityLogger`** is the one place that writes the audit trail and never throws.

## Request examples
**Mark completed (AJAX):** click circle → `PATCH /tasks/{id}/toggle` → `TaskPolicy::toggle` → `TaskService::setStatus` → `completed_at` set + `activity_logs` row → JSON → badge and row classes update, toast shown.

**Drag-and-drop:** SortableJS `onEnd` → `POST /tasks/reorder {ids:[…]}` → `TaskService::reorder` reuses the existing `position` values of just those rows, so tasks on other pages are not disturbed.

**Delete:** button → shared Bootstrap modal → `DELETE /tasks/{id}` → soft delete → trash.

## Error handling
- Validation errors: inline per field + a summary box, with custom messages.
- `bootstrap/app.php` renders friendly 403/404/419/429/500 pages for browsers and `{ok:false,message}` JSON for AJAX calls; the front end turns that into a toast.
- AI failures are explained in plain English with a short reference id that matches a line in `storage/logs/ai-*.log`.

## Front end
No build step. `public/css/app.css` is a theme layer over Bootstrap 4.6 (dark/light via CSS variables); `public/js/app.js` handles theme, modal, AJAX toggle and drag-and-drop; `public/js/ai.js` is the assistant widget.
