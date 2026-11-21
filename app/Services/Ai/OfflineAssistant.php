<?php

namespace App\Services\Ai;

use App\Models\Category;
use App\Models\Task;
use App\Models\User;
use App\Services\StatsService;
use App\Services\TaskService;
use Illuminate\Support\Carbon;

/**
 * Rule-based assistant used when there is no Gemini key (or Google is unreachable).
 * Understands a handful of plain commands and answers with the very same confirmation cards.
 *   add task Buy milk due tomorrow priority high      complete #12      reopen #12
 *   delete #12      show overdue|pending|completed|urgent|today      summary
 */
class OfflineAssistant
{
    public function __construct(private TaskService $tasks, private StatsService $stats) {}

    /** @return array{reply:string,proposals:array} */
    public function handle(User $user, string $text): array
    {
        $t = trim($text);
        $lower = mb_strtolower($t);

        // ---- create
        if (preg_match('/^(?:please\s+)?(?:add|create|new|make|remind me to)\s+(?:a\s+|an\s+)?(?:new\s+)?(?:task|todo|reminder)?\s*(?:to|:|called|named|-)?\s*(.+)$/iu', $t, $m)) {
            $title = trim($m[1]);
            $p = ['status' => 'pending'];

            if (preg_match('/\b(?:priority|prio)\s+(low|medium|high|urgent)\b/i', $title, $x) || preg_match('/\b(low|medium|high|urgent)\s+priority\b/i', $title, $x)) {
                $p['priority'] = strtolower($x[1]);
                $title = trim(str_replace($x[0], '', $title));
            }
            if (preg_match('/\b(?:due|by|on)\s+((?:today|tomorrow|next week|in \d+ days?|\d{4}-\d{2}-\d{2}|(?:next\s+)?(?:mon|tues|wednes|thurs|fri|satur|sun)day))\b/i', $title, $x)) {
                if ($d = $this->date($x[1])) {
                    $p['due_date'] = $d;
                    $title = trim(str_replace($x[0], '', $title));
                }
            }
            if (preg_match('/\b(?:category|in)\s+(' . implode('|', array_map('preg_quote', Category::pluck('name')->all()) ?: ['__none__']) . ')\b/i', $title, $x)) {
                $cat = Category::where('name', 'like', $x[1])->first();
                if ($cat) { $p['category_id'] = $cat->id; $title = trim(str_replace($x[0], '', $title)); }
            }
            if (preg_match('/\btags?\s+([\w,\s-]+)$/i', $title, $x)) {
                $p['tags'] = trim($x[1]);
                $title = trim(str_replace($x[0], '', $title));
            }
            $p['title'] = ucfirst(rtrim($title, " .,"));

            return ['reply' => 'Here is the task I prepared. Adjust anything you like, then tap Confirm.', 'proposals' => [AiActions::card($user, 'create_task', $p)]];
        }

        // ---- complete / reopen / delete by #id or title
        if (preg_match('/^(?:mark\s+)?(complete|completed|finish|finished|done|close|reopen|pending|delete|remove|trash)\b\s*(?:task\s*)?(?:#?(\d+)|(.+?))?(?:\s+as\s+(completed|done|pending))?$/iu', $t, $m)) {
            $verb = strtolower($m[1]);
            $task = ! empty($m[2]) ? Task::query()->visibleTo($user)->find((int) $m[2]) : $this->findByTitle($user, $m[3] ?? '');
            if (! $task) {
                return ['reply' => 'I could not find that task. Use its number, for example “complete #12”, or say “show pending”.', 'proposals' => []];
            }
            if (in_array($verb, ['delete', 'remove', 'trash'], true)) {
                return ['reply' => "Ready to move “{$task->title}” to the trash. Confirm to continue.", 'proposals' => [AiActions::card($user, 'delete_task', ['id' => $task->id])]];
            }
            $status = in_array($verb, ['reopen', 'pending'], true) ? 'pending' : 'completed';

            return ['reply' => "Ready to mark “{$task->title}” as {$status}.", 'proposals' => [AiActions::card($user, 'set_status', ['id' => $task->id, 'status' => $status])]];
        }

        // ---- lists
        if (preg_match('/\b(show|list|what|which|find|search)\b/i', $t)) {
            $f = ['sort' => 'due'];
            foreach (['pending', 'completed'] as $s) if (str_contains($lower, $s)) $f['status'] = $s;
            foreach (['overdue', 'today'] as $d) if (str_contains($lower, $d)) $f['due'] = $d;
            if (str_contains($lower, 'urgent')) $f['priority'] = 'urgent';
            elseif (str_contains($lower, 'high')) $f['priority'] = 'high';
            if (preg_match('/(?:search|find|about|for|matching)\s+"?([\w\s-]{2,})"?$/i', $t, $q) && count($f) === 1) $f['q'] = trim($q[1]);
            $rows = $this->tasks->query($user, $f)->limit(10)->get();
            if ($rows->isEmpty()) return ['reply' => 'No tasks match that.', 'proposals' => []];

            return ['reply' => "Here is what I found:\n".$rows->map(fn ($x) => sprintf('#%d  %s  [%s%s]', $x->id, $x->title, $x->status->value, $x->due_date ? ', due '.$x->due_date->format('d M') : ''))->implode("\n"), 'proposals' => []];
        }

        // ---- summary
        if (preg_match('/\b(summary|summarise|summarize|stats|overview|progress|how am i|today)\b/i', $t)) {
            $s = $this->stats->for($user);
            $next = $s['upcoming']->first();

            return ['reply' => "You have {$s['total']} tasks: {$s['pending']} pending and {$s['completed']} completed ({$s['rate']}% done). {$s['overdue']} overdue, {$s['due_today']} due today."
                .($next ? " Next due: “{$next->title}” on {$next->due_date->format('d M')}." : ''), 'proposals' => []];
        }

        return ['reply' => "I can help with:\n• add task Buy milk due tomorrow priority high\n• complete #12  /  reopen #12  /  delete #12\n• show overdue  /  show pending  /  show urgent\n• summary\n\nAdd a Gemini key to .env for free-form conversation.", 'proposals' => []];
    }

    private function findByTitle(User $user, string $title): ?Task
    {
        $title = trim($title, " \"'");

        return $title === '' ? null : Task::query()->visibleTo($user)->where('title', 'like', '%'.$title.'%')->orderBy('id')->first();
    }

    private function date(string $phrase): ?string
    {
        $phrase = strtolower(trim($phrase));
        try {
            return match (true) {
                $phrase === 'today' => today()->toDateString(),
                $phrase === 'tomorrow' => today()->addDay()->toDateString(),
                $phrase === 'next week' => today()->addWeek()->toDateString(),
                (bool) preg_match('/^in (\d+) days?$/', $phrase, $m) => today()->addDays((int) $m[1])->toDateString(),
                default => Carbon::parse($phrase)->toDateString(),
            };
        } catch (\Throwable) {
            return null;
        }
    }
}
