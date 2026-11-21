<?php

namespace App\Services\Ai;

use App\Models\AiMessage;
use App\Models\Category;
use App\Models\Task;
use App\Models\User;
use App\Services\StatsService;
use App\Services\TaskService;

/**
 * The assistant brain: builds the prompt, runs the Gemini tool-calling loop, and falls back to the
 * offline command parser when no key is configured or Google cannot be reached.
 */
class AssistantService
{
    private const MAX_ROUNDS = 3;
    private const MAX_PROPOSALS = 6;

    public function __construct(private GeminiClient $gemini, private TaskService $tasks, private StatsService $stats, private OfflineAssistant $offline) {}

    /** @return array{reply:string,proposals:array,model:?string,offline:bool,note:?string} */
    public function chat(User $user, string $message): array
    {
        $history = AiMessage::where('user_id', $user->id)->latest('id')->limit(8)->get()->reverse()
            ->map(fn ($m) => ['role' => $m->role === 'user' ? 'user' : 'model', 'parts' => [['text' => $m->content]]])->values()->all();
        $contents = array_merge($history, [['role' => 'user', 'parts' => [['text' => $message]]]]);

        $proposals = [];
        $reply = '';
        $ok = false;

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $res = $this->gemini->generate($contents, $this->system($user), $this->declarations());
            if (! $res) break;
            $ok = true;

            if (! $res['calls']) {
                $reply = $res['text'];
                break;
            }

            $contents[] = ['role' => 'model', 'parts' => $res['parts']];
            $responses = [];
            $hadRead = false;
            foreach ($res['calls'] as $call) {
                $name = $call['name'];
                $args = (array) $call['args'];
                if (str_starts_with($name, 'propose_')) {
                    if (count($proposals) < self::MAX_PROPOSALS) {
                        $card = $this->propose($user, substr($name, 8), $args);
                        if ($card) $proposals[] = $card;
                        $responses[] = ['functionResponse' => ['name' => $name, 'response' => ['result' => $card ? 'Shown to the user as a confirmation card. Nothing is saved until they confirm.' : 'Could not prepare that action (task not found or not allowed).']]];
                    } else {
                        $responses[] = ['functionResponse' => ['name' => $name, 'response' => ['result' => 'Too many proposals in one turn.']]];
                    }
                } else {
                    $hadRead = true;
                    $responses[] = ['functionResponse' => ['name' => $name, 'response' => $this->read($user, $name, $args)]];
                }
            }
            $contents[] = ['role' => 'user', 'parts' => $responses];

            // Proposals only: the model's own words (if any) are enough, no need for another round trip.
            if (! $hadRead && $res['text'] !== '') {
                $reply = $res['text'];
                break;
            }
            if (! $hadRead && $proposals && $round >= 0) {
                $reply = $res['text'] ?: 'I prepared '.(count($proposals) === 1 ? 'an action' : count($proposals).' actions').' for you. Review and tap Confirm.';
                break;
            }
        }

        if ($ok && $reply !== '') {
            return ['reply' => $reply, 'proposals' => $proposals, 'model' => $this->gemini->modelUsed, 'offline' => false, 'note' => null];
        }

        // Fallback: never leave the user without an answer.
        $why = $this->gemini->failure['message'] ?? ($ok ? 'The AI returned no text.' : 'The AI is unavailable.');
        $off = $this->offline->handle($user, $message);

        return ['reply' => $off['reply'], 'proposals' => $off['proposals'], 'model' => null, 'offline' => true,
            'note' => $this->gemini->enabled() ? "AI unavailable ({$why}) — answered in offline mode." : 'Offline mode: add a Gemini key in .env for full AI replies.'];
    }

    /* ------------------------------------------------------------------ prompt */

    private function system(User $user): string
    {
        $scope = match (true) {
            $user->isAdmin() => 'Administrator: can see and manage every task, assign to anyone, restore and permanently delete.',
            $user->isManager() => 'Manager: can see and manage every task and assign to anyone. Cannot permanently delete.',
            default => 'Member: can only see tasks they own or that are assigned to them, and can only delete tasks they created. Cannot assign tasks to other people.',
        };
        $date = now()->format('l, j F Y');
        $s = $this->stats->for($user);
        $rows = $this->tasks->query($user, ['sort' => 'manual'])->limit(40)->get()->map(function (Task $t) {
            return sprintf('#%d | %s | %s | %s | due %s | %s', $t->id, $t->title, $t->status->value, $t->priority->value, $t->due_date?->format('Y-m-d') ?? 'none', $t->category?->name ?? 'no category');
        })->implode("\n");
        $cats = Category::orderBy('name')->pluck('name')->implode(', ') ?: 'none';

        return <<<PROMPT
You are the Task Assistant inside a Laravel task manager. The signed-in user is {$user->name} ({$user->role->label()}). Today is {$date}.
Permissions: {$scope}

WHAT YOU DO
- Answer questions about their tasks and help them plan their day.
- To CHANGE anything you call a propose_* tool. That shows an editable confirmation card and NOTHING is saved until the user taps Confirm. Never say a change is already done; say it is ready to confirm.
- You may call several propose_* tools in one turn (max 6). For bulk requests ("complete all my overdue tasks") first call list_tasks, then propose one card per task.
- Use the ids in WORKSPACE (#12 means id 12). If the target is ambiguous, ask ONE short question.
- Fill every field you can from what the user said (convert "tomorrow", "next Friday" to YYYY-MM-DD using today's date). Status is pending or completed. Priority is low, medium, high or urgent.
- Reply in the user's language, short and plain, no markdown tables or headings. Do not invent tasks that are not in WORKSPACE.

STATS: total {$s['total']}, pending {$s['pending']}, completed {$s['completed']}, overdue {$s['overdue']}, due today {$s['due_today']}.
CATEGORIES: {$cats}

WORKSPACE (id | title | status | priority | due | category), up to 40 tasks:
{$rows}
PROMPT;
    }

    private function declarations(): array
    {
        $str = fn (string $d) => ['type' => 'STRING', 'description' => $d];
        $taskFields = [
            'title' => $str('Task title'), 'description' => $str('Longer details'),
            'priority' => ['type' => 'STRING', 'enum' => ['low', 'medium', 'high', 'urgent']],
            'status' => ['type' => 'STRING', 'enum' => ['pending', 'completed']],
            'due_date' => $str('YYYY-MM-DD'), 'category' => $str('Category name'), 'tags' => $str('Comma separated tags'),
        ];

        return [
            ['name' => 'list_tasks', 'description' => 'Find tasks the user can see. Use before bulk actions or when the answer is not in WORKSPACE.', 'parameters' => ['type' => 'OBJECT', 'properties' => [
                'search' => $str('Words in title, description or tag'),
                'status' => ['type' => 'STRING', 'enum' => ['pending', 'completed']],
                'priority' => ['type' => 'STRING', 'enum' => ['low', 'medium', 'high', 'urgent']],
                'due' => ['type' => 'STRING', 'enum' => ['overdue', 'today', 'week', 'none']],
                'limit' => ['type' => 'INTEGER', 'description' => 'Max rows (default 15, max 40)'],
            ]]],
            ['name' => 'get_summary', 'description' => 'Totals, overdue and the next due tasks.'],
            ['name' => 'propose_create_task', 'description' => 'Propose creating a new task.', 'parameters' => ['type' => 'OBJECT', 'properties' => $taskFields, 'required' => ['title']]],
            ['name' => 'propose_update_task', 'description' => 'Propose editing an existing task. Only include fields that change.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['id' => ['type' => 'INTEGER']] + $taskFields, 'required' => ['id']]],
            ['name' => 'propose_set_status', 'description' => 'Propose marking a task completed or pending.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['id' => ['type' => 'INTEGER'], 'status' => ['type' => 'STRING', 'enum' => ['pending', 'completed']]], 'required' => ['id', 'status']]],
            ['name' => 'propose_delete_task', 'description' => 'Propose moving a task to the trash.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['id' => ['type' => 'INTEGER']], 'required' => ['id']]],
        ];
    }

    /* ------------------------------------------------------------------ tools */

    private function read(User $user, string $name, array $a): array
    {
        if ($name === 'get_summary') {
            $s = $this->stats->for($user);

            return ['total' => $s['total'], 'pending' => $s['pending'], 'completed' => $s['completed'], 'overdue' => $s['overdue'], 'due_today' => $s['due_today'],
                'next_due' => $s['upcoming']->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'due' => $t->due_date->format('Y-m-d')])->all()];
        }
        if ($name === 'list_tasks') {
            $limit = max(1, min(40, (int) ($a['limit'] ?? 15)));
            $rows = $this->tasks->query($user, ['q' => $a['search'] ?? null, 'status' => $a['status'] ?? null, 'priority' => $a['priority'] ?? null, 'due' => $a['due'] ?? null])
                ->limit($limit)->get()->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'status' => $t->status->value, 'priority' => $t->priority->value, 'due' => $t->due_date?->format('Y-m-d'), 'category' => $t->category?->name])->all();

            return ['count' => count($rows), 'tasks' => $rows];
        }

        return ['error' => 'unknown tool'];
    }

    private function propose(User $user, string $action, array $a): ?array
    {
        $action = match ($action) {
            'create_task' => 'create_task', 'update_task' => 'update_task', 'set_status' => 'set_status', 'delete_task' => 'delete_task', default => null,
        };
        if (! $action) return null;

        if (isset($a['category']) && $a['category'] !== '') {
            $cat = Category::where('name', 'like', $a['category'])->first();
            if ($cat) $a['category_id'] = $cat->id;
            unset($a['category']);
        }
        if ($action !== 'create_task') {
            $exists = Task::query()->visibleTo($user)->whereKey((int) ($a['id'] ?? 0))->exists();
            if (! $exists) return null;
        }

        return AiActions::card($user, $action, $a);
    }
}
