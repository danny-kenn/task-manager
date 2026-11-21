<?php

namespace App\Http\Controllers;

use App\Models\AiMessage;
use App\Services\Ai\AiActions;
use App\Services\Ai\AiLog;
use App\Services\Ai\AssistantService;
use App\Services\Ai\GeminiClient;
use App\Services\StatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AiController extends Controller
{
    public function history(Request $request, GeminiClient $gemini, StatsService $stats): JsonResponse
    {
        $this->authorize('use-ai');
        $u = $request->user();
        $s = $stats->for($u);

        $starters = [];
        if ($s['overdue'] > 0) $starters[] = "Show my {$s['overdue']} overdue tasks";
        if ($s['due_today'] > 0) $starters[] = 'What is due today?';
        $starters = array_merge($starters, ['Summarize my progress', 'Add a task to review my notes tomorrow', 'What should I do first?']);
        if ($u->isStaff()) $starters[] = 'Which tasks are still unassigned?';

        return response()->json([
            'ok' => true,
            'ai' => $gemini->enabled(),
            'starters' => array_slice($starters, 0, 5),
            'messages' => AiMessage::where('user_id', $u->id)->latest('id')->limit(30)->get()->reverse()->values()
                ->map(fn ($m) => ['id' => $m->id, 'role' => $m->role, 'content' => $m->content, 'proposals' => $m->proposals ?? []]),
        ]);
    }

    public function chat(Request $request, AssistantService $assistant): JsonResponse
    {
        $this->authorize('use-ai');
        $data = $request->validate(['message' => ['required', 'string', 'min:1', 'max:1000']], [
            'message.required' => 'Type a message first.', 'message.max' => 'Please keep the message under 1000 characters.',
        ]);

        $u = $request->user();
        $key = 'ai-chat:'.$u->id;
        if (RateLimiter::tooManyAttempts($key, 20)) {
            return response()->json(['ok' => false, 'message' => 'You are sending messages too fast. Wait a few seconds.', 'ref' => AiLog::ref()], 429);
        }
        RateLimiter::hit($key, 60);

        try {
            AiMessage::create(['user_id' => $u->id, 'role' => 'user', 'content' => $data['message']]);
            $r = $assistant->chat($u, $data['message']);
            $saved = AiMessage::create(['user_id' => $u->id, 'role' => 'assistant', 'content' => $r['reply'], 'proposals' => $r['proposals'] ?: null, 'model' => $r['model']]);

            return response()->json($r + ['ok' => true, 'message_id' => $saved->id, 'ref' => AiLog::ref()]);
        } catch (\Throwable $e) {
            AiLog::exception('ai.chat.exception', $e);

            return response()->json(['ok' => false, 'message' => 'The assistant hit an unexpected problem. Please try again.', 'ref' => AiLog::ref()], 500);
        }
    }

    public function execute(Request $request): JsonResponse
    {
        $this->authorize('use-ai');
        $u = $request->user();
        $key = 'ai-exec:'.$u->id;
        if (RateLimiter::tooManyAttempts($key, 60)) {
            return response()->json(['ok' => false, 'message' => 'Too many actions in a minute.'], 429);
        }
        RateLimiter::hit($key, 60);

        $action = (string) $request->input('action');
        $res = AiActions::execute($u, $action, (array) $request->input('params', []));

        if ($res['ok'] && $request->filled('message_id') && $request->filled('proposal_id')) {
            $m = AiMessage::where('user_id', $u->id)->find($request->integer('message_id'));
            if ($m && $m->proposals) {
                $m->proposals = collect($m->proposals)->map(function ($p) use ($request, $res) {
                    if (($p['id'] ?? null) === $request->input('proposal_id')) {
                        $p['done'] = true;
                        $p['result'] = $res['message'];
                    }

                    return $p;
                })->all();
                $m->save();
            }
        }

        return response()->json($res + ['ref' => AiLog::ref()], $res['ok'] ? 200 : 422);
    }

    public function clear(Request $request): JsonResponse
    {
        AiMessage::where('user_id', $request->user()->id)->delete();

        return response()->json(['ok' => true]);
    }
}
