<?php

namespace App\Services\Ai;

use App\Models\ActivityLog;
use App\Models\AiMemory;
use App\Models\Category;
use App\Models\Task;
use App\Models\User;
use App\Services\ReportService;
use App\Services\StatsService;
use App\Services\TaskService;

/**
 * The assistant's tools. Which ones are DECLARED depends on the user's role (a member never even sees the
 * admin tools), and every proposal is re-authorised again when it is executed (AiActions::execute).
 *   read tools     -> run now, scoped to what the user may see, result goes back to the model
 *   block tools    -> produce rich UI blocks (table, stats, report, choices, links, remembered)
 *   propose_*      -> become editable confirmation cards; nothing is saved until the human confirms
 */
class AiToolbox
{
    public const READ = ['list_tasks', 'get_task', 'get_summary', 'list_categories', 'list_users', 'get_activity'];
    public const BLOCK = ['show_table', 'show_stats', 'make_report', 'ask_choices', 'open_page', 'remember', 'forget'];

    public function __construct(private TaskService $tasks, private StatsService $stats) {}

    /* ------------------------------------------------------------------ declarations */

    public function names(User $u): array
    {
        return array_column($this->declarations($u), 'name');
    }

    public function declarations(User $u): array
    {
        $str = fn (string $d) => ['type' => 'STRING', 'description' => $d];
        $int = fn (string $d) => ['type' => 'INTEGER', 'description' => $d];
        $enum = fn (array $v, string $d = '') => ['type' => 'STRING', 'enum' => $v, 'description' => $d ?: 'one of: '.implode(', ', $v)];
        $obj = fn (array $props, array $req = []) => ['type' => 'OBJECT', 'properties' => $props] + ($req ? ['required' => $req] : []);

        $taskFields = [
            'title' => $str('Task title'), 'description' => $str('Longer details'),
            'priority' => $enum(['low', 'medium', 'high', 'urgent']), 'status' => $enum(['pending', 'completed']),
            'due_date' => $str('YYYY-MM-DD'), 'category' => $str('Category name'), 'tags' => $str('Comma separated tags'),
        ];
        if ($u->isStaff()) {
            $taskFields['assignee'] = $str('Person to assign: name, email, "me", or "none" to unassign');
        }
        $filters = [
            'status' => $enum(['pending', 'completed']), 'priority' => $enum(['low', 'medium', 'high', 'urgent']),
            'due' => $enum(['overdue', 'today', 'week', 'none']), 'search' => $str('Words in title, description or tag'),
            'category' => $str('Category name'), 'tag' => $str('Tag'),
        ];
        if ($u->isStaff()) {
            $filters['assignee'] = $str('"me", "none" (unassigned) or a person name');
        }
        $datasets = array_values(array_filter(ReportService::DATASETS, fn ($d) => ReportService::allowed($u, $d)));
        $pages = array_keys($this->pages($u));

        $d = [
            ['name' => 'list_tasks', 'description' => 'Find tasks the user can see (data for YOU to reason about; the user does not see it). Use before bulk actions.', 'parameters' => $obj($filters + ['limit' => $int('Max rows, default 15, max 40')])],
            ['name' => 'get_task', 'description' => 'Full detail of one task: checklist items (with ids), comments, history.', 'parameters' => $obj(['id' => $int('Task id')], ['id'])],
            ['name' => 'get_summary', 'description' => 'Totals, overdue, due today and next due tasks for this user.'],
            ['name' => 'list_categories', 'description' => 'All categories with ids and task counts.'],
            ['name' => 'show_table', 'description' => 'SHOW the user a table of records (with CSV/PDF buttons). Use whenever the user asks to see/list tasks or records.', 'parameters' => $obj([
                'dataset' => $enum($datasets), 'title' => $str('Table title'), 'limit' => $int('Rows, max 50'), 'columns' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Optional subset of column names'],
            ] + $filters, ['dataset'])],
            ['name' => 'show_stats', 'description' => 'SHOW the user stat tiles ("today at a glance"): pending, overdue, due today, completed.'],
            ['name' => 'make_report', 'description' => 'Create a downloadable report file. If the user has not said what or which format, call ask_choices first.', 'parameters' => $obj([
                'dataset' => $enum($datasets), 'format' => $enum(ReportService::FORMATS), 'title' => $str('Report title'),
            ] + $filters, ['dataset'])],
            ['name' => 'ask_choices', 'description' => 'Ask ONE clarifying question with tappable options (2-6 short options) instead of guessing.', 'parameters' => $obj([
                'question' => $str('The question'), 'options' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            ], ['question', 'options'])],
            ['name' => 'open_page', 'description' => 'Show tappable links to pages of the app so the user can jump there.', 'parameters' => $obj([
                'pages' => ['type' => 'ARRAY', 'items' => $enum($pages)], 'task_id' => $int('For page=task'),
                'status' => $enum(['pending', 'completed']), 'due' => $enum(['overdue', 'today', 'week', 'none']), 'priority' => $enum(['low', 'medium', 'high', 'urgent']),
            ], ['pages'])],
            ['name' => 'remember', 'description' => 'Silently save a durable fact about THIS user (their preferred name, work style, preferences). Only when they state it or ask you to remember. Never save secrets.', 'parameters' => $obj([
                'category' => $enum(['name', 'preference', 'work', 'fact']), 'content' => $str('Short fact, e.g. "Prefers to be called Dan"'),
            ], ['category', 'content'])],
            ['name' => 'forget', 'description' => 'Delete remembered facts that contain these words (when the user asks you to forget something).', 'parameters' => $obj(['match' => $str('Words to match')], ['match'])],

            ['name' => 'propose_create_task', 'description' => 'Propose creating a task.', 'parameters' => $obj($taskFields, ['title'])],
            ['name' => 'propose_update_task', 'description' => 'Propose editing a task (only the fields that change).'.($u->isStaff() ? ' Use `assignee` to assign/unassign.' : ''), 'parameters' => $obj(['id' => $int('Task id')] + $taskFields, ['id'])],
            ['name' => 'propose_set_status', 'description' => 'Propose marking a task completed or pending.', 'parameters' => $obj(['id' => $int(''), 'status' => $enum(['pending', 'completed'])], ['id', 'status'])],
            ['name' => 'propose_delete_task', 'description' => 'Propose moving a task to the trash.', 'parameters' => $obj(['id' => $int('')], ['id'])],
            ['name' => 'propose_add_subtask', 'description' => 'Propose adding a checklist item to a task.', 'parameters' => $obj(['id' => $int('Task id'), 'title' => $str('Item')], ['id', 'title'])],
            ['name' => 'propose_toggle_subtask', 'description' => 'Propose ticking/unticking a checklist item (ids from get_task).', 'parameters' => $obj(['id' => $int('Task id'), 'subtask_id' => $int('Item id')], ['id', 'subtask_id'])],
            ['name' => 'propose_delete_subtask', 'description' => 'Propose removing a checklist item.', 'parameters' => $obj(['id' => $int('Task id'), 'subtask_id' => $int('Item id')], ['id', 'subtask_id'])],
            ['name' => 'propose_add_comment', 'description' => 'Propose posting a comment on a task.', 'parameters' => $obj(['id' => $int('Task id'), 'body' => $str('Comment')], ['id', 'body'])],
            ['name' => 'propose_update_my_name', 'description' => 'Propose changing the user\'s account display name.', 'parameters' => $obj(['name' => $str('New name')], ['name'])],
            ['name' => 'propose_set_notification_pref', 'description' => 'Propose changing the user\'s OWN notification setting.', 'parameters' => $obj([
                'event' => $enum(array_keys(\App\Services\Notifier::EVENTS)), 'channel' => $enum(array_keys(\App\Services\Notifier::CHANNELS)), 'on' => ['type' => 'BOOLEAN'],
            ], ['event', 'channel', 'on'])],
        ];

        if ($u->isStaff()) {
            $d[] = ['name' => 'list_users', 'description' => 'People in the workspace with role, active flag and pending workload (to assign tasks or answer who is busy).', 'parameters' => $obj(['search' => $str('Name or email part')])];
            $d[] = ['name' => 'get_activity', 'description' => 'Recent audit-log activity.', 'parameters' => $obj(['limit' => $int('Max 30')])];
            $d[] = ['name' => 'propose_restore_task', 'description' => 'Propose restoring a task from the trash (ids from list_tasks with trashed=true is not available: ask the user for the id or use the Trash page link).', 'parameters' => $obj(['id' => $int('Trashed task id')], ['id'])];
            $d[] = ['name' => 'propose_create_category', 'description' => 'Propose creating a category.', 'parameters' => $obj(['name' => $str(''), 'color' => $str('#rrggbb'), 'description' => $str('')], ['name'])];
            $d[] = ['name' => 'propose_update_category', 'description' => 'Propose editing a category.', 'parameters' => $obj(['id' => $int(''), 'name' => $str(''), 'color' => $str('#rrggbb'), 'description' => $str('')], ['id'])];
            $d[] = ['name' => 'propose_create_user', 'description' => 'Propose creating a user account (admin or manager; there is only ONE admin so never offer role admin).', 'parameters' => $obj(['name' => $str(''), 'email' => $str(''), 'password' => $str('min 8 chars, letters and numbers'), 'role' => $enum($u->isAdmin() ? ['manager', 'member'] : ['member'])], ['role'])];
            $d[] = ['name' => 'propose_update_user', 'description' => 'Propose changing a user\'s name, role or active state (admin or manager).', 'parameters' => $obj(['id' => $int('User id'), 'name' => $str(''), 'role' => $enum($u->isAdmin() ? ['manager', 'member'] : ['member']), 'is_active' => ['type' => 'BOOLEAN']], ['id'])];
            $d[] = ['name' => 'propose_delete_category', 'description' => 'Propose deleting a category (its tasks become uncategorised).', 'parameters' => $obj(['id' => $int('')], ['id'])];
        }
        if ($u->isAdmin()) {
            $d[] = ['name' => 'propose_force_delete_task', 'description' => 'Propose PERMANENTLY deleting a trashed task (admin only).', 'parameters' => $obj(['id' => $int('Trashed task id')], ['id'])];
        }

        return $d;
    }

    public static function stepLabel(string $name): string
    {
        return match (true) {
            $name === 'list_tasks' => 'Looking through your tasks…',
            $name === 'get_task' => 'Opening the task details…',
            $name === 'get_summary' => 'Checking your numbers…',
            $name === 'list_categories' => 'Checking categories…',
            $name === 'list_users' => 'Looking at the team and workload…',
            $name === 'get_activity' => 'Reading recent activity…',
            $name === 'show_table' => 'Building a table…',
            $name === 'show_stats' => 'Adding up your stats…',
            $name === 'make_report' => 'Preparing your report files…',
            $name === 'ask_choices' => 'Preparing a quick question…',
            $name === 'open_page' => 'Finding the right pages…',
            $name === 'remember' => 'Saving that to memory…',
            $name === 'forget' => 'Forgetting that…',
            str_starts_with($name, 'propose_') => 'Preparing a confirmation card…',
            default => 'Working…',
        };
    }

    /* ------------------------------------------------------------------ read tools (result goes to the model) */

    public function read(User $u, string $name, array $a): array
    {
        switch ($name) {
            case 'get_summary':
                $s = $this->stats->for($u);

                return ['total' => $s['total'], 'pending' => $s['pending'], 'completed' => $s['completed'], 'overdue' => $s['overdue'], 'due_today' => $s['due_today'], 'completion_rate' => $s['rate'].'%',
                    'next_due' => $s['upcoming']->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'due' => $t->due_date->format('Y-m-d')])->all()];

            case 'list_tasks':
                $limit = max(1, min(40, (int) ($a['limit'] ?? 15)));
                $rows = $this->taskQuery($u, $a)->limit($limit)->get()->map(fn (Task $t) => [
                    'id' => $t->id, 'title' => $t->title, 'status' => $t->status->value, 'priority' => $t->priority->value, 'due' => $t->due_date?->format('Y-m-d'),
                    'category' => $t->category?->name, 'assignee' => $t->assignee?->name, 'owner' => $t->owner?->name,
                ])->all();

                return ['count' => count($rows), 'tasks' => $rows];

            case 'get_task':
                $t = Task::query()->visibleTo($u)->with(['category', 'tags', 'assignee', 'owner', 'subtasks', 'comments.author'])->find((int) ($a['id'] ?? 0));
                if (! $t) return ['error' => 'Task not found or not visible to this user.'];
                $hist = ActivityLog::with('user')->where('task_id', $t->id)->latest('id')->limit(8)->get();

                return [
                    'id' => $t->id, 'title' => $t->title, 'description' => mb_substr((string) $t->description, 0, 800), 'status' => $t->status->value, 'priority' => $t->priority->value,
                    'due' => $t->due_date?->format('Y-m-d'), 'overdue' => $t->is_overdue, 'category' => $t->category?->name, 'tags' => $t->tags->pluck('name')->all(),
                    'owner' => $t->owner?->name, 'assignee' => $t->assignee?->name ?? 'unassigned',
                    'checklist' => $t->subtasks->map(fn ($s) => ['id' => $s->id, 'title' => $s->title, 'done' => $s->is_done])->all(),
                    'comments' => $t->comments->take(6)->map(fn ($c) => ['by' => $c->author?->name, 'text' => mb_substr($c->body, 0, 300), 'when' => $c->created_at->diffForHumans()])->all(),
                    'history' => $hist->map(fn ($h) => $h->description.' — '.($h->user?->name ?? 'System').', '.$h->created_at->diffForHumans())->all(),
                ];

            case 'list_categories':
                return ['categories' => Category::withCount('tasks')->orderBy('name')->get()->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'tasks' => $c->tasks_count])->all()];

            case 'list_users':
                if (! $u->isStaff()) return ['error' => 'Not allowed.'];
                $q = User::query()->where('is_active', true)->withCount(['assignedTasks as pending' => fn ($x) => $x->where('status', 'pending')])->orderBy('name');
                if (! empty($a['search'])) $q->where(fn ($w) => $w->where('name', 'like', '%'.$a['search'].'%')->orWhere('email', 'like', '%'.$a['search'].'%'));

                return ['users' => $q->limit(40)->get()->map(fn ($x) => ['id' => $x->id, 'name' => $x->name, 'email' => $x->email, 'role' => $x->role->value, 'pending_tasks' => $x->pending])->all()];

            case 'get_activity':
                if (! $u->isStaff()) return ['error' => 'Not allowed.'];

                return ['activity' => ActivityLog::with('user')->withoutPersonalTasks()->latest('id')->limit(max(1, min(30, (int) ($a['limit'] ?? 12))))->get()
                    ->map(fn ($l) => $l->description.' — '.($l->user?->name ?? 'System').($l->source === 'ai' ? ' (AI)' : '').', '.$l->created_at->diffForHumans())->all()];
        }

        return ['error' => 'unknown tool'];
    }

    /** Task query from the model's loose filter names (names are resolved to ids, scoped by visibleTo). */
    public function taskQuery(User $u, array $a)
    {
        $f = ['q' => $a['search'] ?? null, 'status' => $a['status'] ?? null, 'priority' => $a['priority'] ?? null, 'due' => $a['due'] ?? null, 'tag' => isset($a['tag']) ? \Illuminate\Support\Str::slug($a['tag']) : null, 'sort' => $a['sort'] ?? 'due'];
        if (! empty($a['category']) && ($c = Category::where('name', 'like', $a['category'])->first())) $f['category_id'] = $c->id;
        if (! empty($a['category_id'])) $f['category_id'] = $a['category_id'];
        $q = $this->tasks->query($u, $f);
        if (! empty($a['assignee'])) {
            $as = strtolower(trim((string) $a['assignee']));
            if ($as === 'me') $q->where('assignee_id', $u->id);
            elseif ($as === 'none' || $as === 'unassigned') $q->whereNull('assignee_id');
            elseif ($u->isStaff() && ($p = $this->findPerson($u, $as))) $q->where('assignee_id', $p->id);
            elseif (ctype_digit($as) && $u->isStaff()) $q->where('assignee_id', (int) $as);
        }

        return $q;
    }

    public function findPerson(User $u, string $who): ?User
    {
        if (! $u->isStaff()) return null;
        $who = trim($who);
        if (strtolower($who) === 'me') return $u;
        if (ctype_digit($who)) return User::where('is_active', true)->find((int) $who);

        return User::where('is_active', true)->where(fn ($w) => $w->where('email', $who)->orWhere('name', 'like', $who)->orWhere('name', 'like', '%'.$who.'%'))->orderByRaw('name = ? desc', [$who])->first();
    }

    /* ------------------------------------------------------------------ block tools (UI blocks) */

    /** @return array{block:?array,result:string} */
    public function block(User $u, string $name, array $a): array
    {
        switch ($name) {
            case 'show_table':
            case 'make_report':
                $ds = (string) ($a['dataset'] ?? 'tasks');
                if (! in_array($ds, ReportService::DATASETS, true)) return ['block' => null, 'result' => 'Unknown dataset.'];
                if (! ReportService::allowed($u, $ds)) return ['block' => null, 'result' => 'Refused: this user role may not access that data. Tell them politely it needs '.($ds === 'users' ? 'an administrator' : 'a manager or admin').'.'];
                $filters = $this->reportFilters($u, $a);
                $data = app(ReportService::class)->build($u, $ds, $filters);

                if ($name === 'make_report') {
                    $fmt = in_array($a['format'] ?? '', ReportService::FORMATS, true) ? $a['format'] : 'pdf';
                    $urls = [];
                    foreach (ReportService::FORMATS as $f) $urls[$f] = ReportService::url($ds, $f, $filters);

                    return ['block' => ['type' => 'report', 'title' => $a['title'] ?? $data['title'], 'subtitle' => $data['subtitle'] ?? '', 'dataset' => $ds, 'format' => $fmt, 'count' => $data['count'], 'url' => $urls[$fmt], 'formats' => $urls],
                        'result' => "Report ready with {$data['count']} rows; download buttons for every format are shown to the user."];
                }
                $limit = max(1, min(50, (int) ($a['limit'] ?? 20)));
                $cols = $data['columns'];
                $rows = array_slice($data['rows'], 0, $limit);
                if (! empty($a['columns']) && is_array($a['columns'])) {
                    $keep = [];
                    foreach ($cols as $i => $c) if (in_array(strtolower($c), array_map('strtolower', $a['columns']), true)) $keep[] = $i;
                    if ($keep) {
                        $cols = array_map(fn ($i) => $data['columns'][$i], $keep);
                        $rows = array_map(fn ($r) => array_map(fn ($i) => $r[$i] ?? '', $keep), $rows);
                    }
                }

                return ['block' => ['type' => 'table', 'title' => $a['title'] ?? $data['title'], 'columns' => $cols, 'rows' => $rows, 'count' => $data['count'], 'shown' => count($rows),
                    'csv_url' => ReportService::url($ds, 'csv', $filters), 'pdf_url' => ReportService::url($ds, 'pdf', $filters)],
                    'result' => "Table shown to the user ({$data['count']} rows total, showing ".count($rows).'). First rows: '.json_encode(array_slice($rows, 0, 3), JSON_UNESCAPED_UNICODE)];

            case 'show_stats':
                $s = $this->stats->for($u);
                $items = [
                    ['label' => 'Pending', 'value' => $s['pending'], 'hint' => 'still to do', 'tone' => 'neutral'],
                    ['label' => 'Overdue', 'value' => $s['overdue'], 'hint' => $s['overdue'] ? 'needs attention' : 'all on time', 'tone' => $s['overdue'] ? 'danger' : 'ok'],
                    ['label' => 'Due today', 'value' => $s['due_today'], 'hint' => 'today', 'tone' => $s['due_today'] ? 'warn' : 'neutral'],
                    ['label' => 'Completed', 'value' => $s['completed'], 'hint' => $s['rate'].'% done', 'tone' => 'ok'],
                ];
                if ($u->isStaff()) {
                    $un = Task::query()->visibleTo($u)->where('status', 'pending')->whereNull('assignee_id')->count();
                    $items[] = ['label' => 'Unassigned', 'value' => $un, 'hint' => 'pending, nobody on it', 'tone' => $un ? 'warn' : 'ok'];
                }

                return ['block' => ['type' => 'stats', 'title' => 'Today at a glance', 'items' => $items], 'result' => 'Stat tiles shown to the user: '.json_encode(array_map(fn ($i) => $i['label'].'='.$i['value'], $items))];

            case 'ask_choices':
                $opts = array_values(array_filter(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 60), (array) ($a['options'] ?? []))));

                return ['block' => ['type' => 'choices', 'question' => mb_substr((string) ($a['question'] ?? 'Which one?'), 0, 200), 'options' => array_slice($opts, 0, 8)],
                    'result' => 'The question with tappable options is shown. Stop and wait for the answer.'];

            case 'open_page':
                $links = [];
                $pages = $this->pages($u, $a);
                foreach ((array) ($a['pages'] ?? []) as $p) {
                    if (isset($pages[$p])) $links[] = $pages[$p];
                }

                return ['block' => $links ? ['type' => 'links', 'links' => $links] : null, 'result' => $links ? 'Links shown.' : 'No such page for this role.'];

            case 'remember':
                $cat = in_array($a['category'] ?? '', AiMemory::CATEGORIES, true) ? $a['category'] : 'fact';
                $text = self::remember($u, $cat, (string) ($a['content'] ?? ''));

                return ['block' => $text ? ['type' => 'remembered', 'text' => $text] : null, 'result' => $text ? 'Saved.' : 'Nothing to save.'];

            case 'forget':
                $n = self::forget($u, (string) ($a['match'] ?? ''));

                return ['block' => ['type' => 'remembered', 'text' => $n ? "Forgot {$n} thing".($n > 1 ? 's' : '') : 'Nothing matched', 'forgot' => true], 'result' => "Forgot {$n}."];
        }

        return ['block' => null, 'result' => 'unknown tool'];
    }

    private function reportFilters(User $u, array $a): array
    {
        $f = array_filter([
            'status' => $a['status'] ?? null, 'priority' => $a['priority'] ?? null, 'due' => $a['due'] ?? null, 'q' => $a['search'] ?? null,
            'tag' => isset($a['tag']) ? \Illuminate\Support\Str::slug($a['tag']) : null,
        ]);
        if (! empty($a['category']) && ($c = Category::where('name', 'like', $a['category'])->first())) $f['category_id'] = $c->id;
        if (! empty($a['assignee'])) {
            $as = strtolower(trim((string) $a['assignee']));
            if ($as === 'me') $f['assignee'] = 'me';
            elseif (($p = $this->findPerson($u, $as))) $f['assignee'] = (string) $p->id;
        }

        return $f;
    }

    /** Pages the role may open. @return array<string,array{label:string,url:string,icon:string}> */
    public function pages(User $u, array $a = []): array
    {
        $p = [
            'dashboard' => ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'fa-chart-pie'],
            'tasks' => ['label' => 'Tasks', 'url' => route('tasks.index', array_filter(['status' => $a['status'] ?? null, 'due' => $a['due'] ?? null, 'priority' => $a['priority'] ?? null])), 'icon' => 'fa-list-check'],
            'assigned_to_me' => ['label' => 'Assigned to me', 'url' => route('tasks.index', ['assignee' => 'me', 'status' => 'pending']), 'icon' => 'fa-user-check'],
            'profile' => ['label' => 'My profile & notifications', 'url' => route('profile.edit'), 'icon' => 'fa-user-gear'],
        ];
        if (! empty($a['task_id']) && ($t = Task::query()->visibleTo($u)->find((int) $a['task_id']))) {
            $p['task'] = ['label' => "#{$t->id} ".mb_substr($t->title, 0, 28), 'url' => route('tasks.show', $t), 'icon' => 'fa-clipboard-check'];
        }
        if ($u->isStaff()) {
            $p['trash'] = ['label' => 'Trash', 'url' => route('tasks.trash'), 'icon' => 'fa-trash-can'];
            $p['categories'] = ['label' => 'Categories', 'url' => route('categories.index'), 'icon' => 'fa-tags'];
            $p['activity'] = ['label' => 'Activity log', 'url' => route('activity.index'), 'icon' => 'fa-clock-rotate-left'];
        }
        if ($u->isAdmin()) {
            $p['users'] = ['label' => 'Users & roles', 'url' => route('users.index'), 'icon' => 'fa-users-gear'];
        }

        return $p;
    }

    /* ------------------------------------------------------------------ memory */

    public static function remember(User $u, string $category, string $content): ?string
    {
        $content = trim(preg_replace('/\s+/', ' ', $content));
        if ($content === '' || preg_match('/password|passcode|\bpin\b|api[ _-]?key|secret|token|card number/i', $content)) {
            return null; // never store secrets
        }
        $content = mb_substr($content, 0, 300);
        if ($category === 'name') {
            AiMemory::where('user_id', $u->id)->where('category', 'name')->delete();
        }
        if (AiMemory::where('user_id', $u->id)->count() >= 40) {
            AiMemory::where('user_id', $u->id)->orderBy('id')->limit(1)->delete();
        }
        AiMemory::firstOrCreate(['user_id' => $u->id, 'content' => $content], ['category' => $category]);

        return $content;
    }

    public static function forget(User $u, string $match): int
    {
        $match = trim($match);
        if ($match === '') return 0;

        return AiMemory::where('user_id', $u->id)->where('content', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $match).'%')->delete();
    }

    /** The name the assistant should use for this user (memory beats account name). */
    public static function displayName(User $u): string
    {
        $m = AiMemory::where('user_id', $u->id)->where('category', 'name')->latest('id')->value('content');
        if ($m && preg_match('/(?:called|name is|call me|prefers?(?: to be called)?)\s+([\p{L}][\p{L}\'\- ]{0,30})/iu', $m, $x)) {
            return trim($x[1]);
        }

        return $u->first_name;
    }
}
