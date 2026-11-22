<?php

namespace App\Http\Controllers;

use App\Models\AiConversation;
use App\Models\AiMemory;
use App\Models\AiMessage;
use App\Services\Ai\AiActions;
use App\Services\Ai\AiLog;
use App\Services\Ai\AiToolbox;
use App\Services\Ai\AssistantService;
use App\Services\Ai\GeminiClient;
use App\Services\StatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiController extends Controller
{
    private const MIMES = 'image/jpeg,image/png,image/webp,image/gif,audio/webm,audio/ogg,audio/mpeg,audio/mp3,audio/wav,audio/x-wav,audio/mp4,audio/x-m4a,audio/aac,video/webm,video/mp4';

    /* ------------------------------------------------------------------ bootstrap + chat list */

    public function history(Request $request, GeminiClient $gemini, StatsService $stats): JsonResponse
    {
        $this->authorize('use-ai');
        $u = $request->user();
        $s = $stats->for($u);

        $starters = [['icon' => 'fa-sun', 'title' => 'Daily briefing', 'text' => 'Give me my daily briefing', 'sub' => 'Today at a glance']];
        if ($s['overdue'] > 0) $starters[] = ['icon' => 'fa-triangle-exclamation', 'title' => "{$s['overdue']} overdue", 'text' => 'Show my overdue tasks', 'sub' => 'What slipped past its date'];
        $starters[] = ['icon' => 'fa-plus', 'title' => 'Add a task', 'text' => 'Add a task to review my notes tomorrow', 'sub' => 'Just say it in plain words'];
        $starters[] = ['icon' => 'fa-file-export', 'title' => 'Make a report', 'text' => 'I want a report. Ask me with tap choices what it should contain and which format (PDF, Word, Excel or CSV).', 'sub' => 'PDF, Word, Excel, CSV…'];
        $starters[] = $u->isStaff()
            ? ['icon' => 'fa-people-group', 'title' => 'Team workload', 'text' => 'Who is busiest right now and which tasks are unassigned?', 'sub' => 'Spot overload early']
            : ['icon' => 'fa-bullseye', 'title' => 'Plan my day', 'text' => 'What should I do first today?', 'sub' => 'A sensible order'];

        $last = AiConversation::where('user_id', $u->id)->latest('updated_at')->first();

        return response()->json([
            'ok' => true, 'ai' => $gemini->enabled(), 'name' => AiToolbox::displayName($u), 'role' => $u->role->label(),
            'starters' => array_slice($starters, 0, 5),
            'conversations' => $this->list($request),
            'active' => $last ? $this->payload($last) : null,
            'memory_count' => AiMemory::where('user_id', $u->id)->count(),
        ]);
    }

    public function conversations(Request $request): JsonResponse
    {
        $this->authorize('use-ai');

        return response()->json(['ok' => true, 'conversations' => $this->list($request, (string) $request->query('q', ''))]);
    }

    public function conversation(Request $request, AiConversation $conversation): JsonResponse
    {
        $this->authorize('use-ai');
        abort_unless($conversation->user_id === $request->user()->id, 404);

        return response()->json(['ok' => true] + $this->payload($conversation));
    }

    public function destroyConversation(Request $request, AiConversation $conversation): JsonResponse
    {
        abort_unless($conversation->user_id === $request->user()->id, 404);
        $this->purgeFiles($conversation->messages()->get());
        $conversation->delete();

        return response()->json(['ok' => true]);
    }

    public function clear(Request $request): JsonResponse
    {
        $convs = AiConversation::where('user_id', $request->user()->id)->get();
        foreach ($convs as $c) $this->purgeFiles($c->messages()->get());
        AiConversation::where('user_id', $request->user()->id)->delete();
        AiMessage::where('user_id', $request->user()->id)->delete();

        return response()->json(['ok' => true]);
    }

    /* ------------------------------------------------------------------ chat (JSON) and stream (SSE) */

    public function chat(Request $request, AssistantService $assistant): JsonResponse
    {
        [$u, $conv, $text, $files, $err] = $this->prepare($request);
        if ($err) return $err;

        try {
            $userMsg = $this->saveUser($u, $conv, $text, $files);
            $r = $assistant->run($u, $conv, $text, $files, fn () => null);
            $saved = $this->saveAssistant($u, $conv, $r);

            return response()->json($r + ['ok' => true, 'conversation_id' => $conv->id, 'title' => $conv->title, 'message_id' => $saved->id, 'user_message_id' => $userMsg->id, 'ref' => AiLog::ref()]);
        } catch (\Throwable $e) {
            AiLog::exception('ai.chat.exception', $e);

            return response()->json(['ok' => false, 'message' => 'The assistant hit an unexpected problem. Please try again.', 'ref' => AiLog::ref()], 500);
        }
    }

    public function stream(Request $request, AssistantService $assistant): StreamedResponse|JsonResponse
    {
        [$u, $conv, $text, $files, $err] = $this->prepare($request);
        if ($err) return $err;
        $request->session()->save(); // release the session so other requests are not blocked while we stream
        $ref = AiLog::ref();

        return response()->stream(function () use ($assistant, $u, $conv, $text, $files, $ref) {
            @set_time_limit(120);
            ignore_user_abort(true);
            while (ob_get_level() > 0) @ob_end_flush();
            $send = function (array $e) {
                echo 'data: '.json_encode($e, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
                @ob_flush();
                flush();
            };
            echo ':'.str_repeat(' ', 2048)."\n\n"; // defeat proxy buffering
            flush();

            try {
                $userMsg = $this->saveUser($u, $conv, $text, $files);
                $send(['t' => 'meta', 'conversation_id' => $conv->id, 'title' => $conv->title, 'user_message_id' => $userMsg->id, 'attachments' => $this->attachmentPayload($userMsg)]);
                $r = $assistant->run($u, $conv, $text, $files, $send);
                $saved = $this->saveAssistant($u, $conv, $r);
                $send(['t' => 'done', 'message_id' => $saved->id, 'reply' => $r['reply'], 'thinking_ms' => $r['thinking_ms'], 'offline' => $r['offline'], 'note' => $r['note'], 'steps' => $r['steps'], 'title' => $conv->title, 'ref' => $ref]);
            } catch (\Throwable $e) {
                AiLog::exception('ai.stream.exception', $e);
                $send(['t' => 'error', 'message' => 'The assistant hit an unexpected problem. Please try again.', 'ref' => $ref]);
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no', 'Connection' => 'keep-alive']);
    }

    /** Shared validation, rate limit, conversation lookup and file storage for chat + stream. */
    private function prepare(Request $request): array
    {
        $this->authorize('use-ai');
        $u = $request->user();
        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer'],
            'files' => ['nullable', 'array', 'max:4'],
            'files.*' => ['file', 'max:8192', 'mimetypes:'.self::MIMES],
        ], ['message.max' => 'Please keep the message under 2000 characters.', 'files.*.max' => 'Each file must be under 8 MB.', 'files.*.mimetypes' => 'Only images and voice recordings are supported.']);
        $text = trim((string) ($data['message'] ?? ''));
        $upload = $request->file('files', []);
        if ($text === '' && ! $upload) {
            return [$u, null, '', [], response()->json(['ok' => false, 'message' => 'Type a message first.', 'errors' => ['message' => ['Type a message first.']]], 422)];
        }

        $key = 'ai-chat:'.$u->id;
        if (RateLimiter::tooManyAttempts($key, 20)) {
            return [$u, null, '', [], response()->json(['ok' => false, 'message' => 'You are sending messages too fast. Wait a few seconds.', 'ref' => AiLog::ref()], 429)];
        }
        RateLimiter::hit($key, 60);

        $conv = ! empty($data['conversation_id']) ? AiConversation::where('user_id', $u->id)->find((int) $data['conversation_id']) : null;
        $conv ??= AiConversation::create(['user_id' => $u->id, 'title' => Str::limit($text !== '' ? $text : 'Attachment', 44, '…')]);

        $files = [];
        foreach ($upload as $f) {
            $mime = (string) $f->getMimeType();
            $ext = $f->guessExtension() ?: 'bin';
            $path = $f->storeAs('ai/'.$u->id, Str::random(28).'.'.$ext, 'local');
            $files[] = ['path' => $path, 'mime' => $mime, 'name' => mb_substr($f->getClientOriginalName(), 0, 80), 'kind' => str_starts_with($mime, 'image/') ? 'image' : 'audio'];
        }

        return [$u, $conv, $text, $files, null];
    }

    private function saveUser($u, AiConversation $conv, string $text, array $files): AiMessage
    {
        return AiMessage::create(['user_id' => $u->id, 'conversation_id' => $conv->id, 'role' => 'user', 'content' => $text !== '' ? $text : '(attachment)', 'attachments' => $files ?: null]);
    }

    private function saveAssistant($u, AiConversation $conv, array $r): AiMessage
    {
        $m = AiMessage::create([
            'user_id' => $u->id, 'conversation_id' => $conv->id, 'role' => 'assistant', 'content' => $r['reply'], 'proposals' => $r['proposals'] ?: null,
            'blocks' => $r['blocks'] ?: null, 'followups' => $r['followups'] ?: null, 'steps' => $r['steps'] ?: null, 'thinking_ms' => $r['thinking_ms'] ?? null, 'model' => $r['model'],
        ]);
        $conv->touch();

        return $m;
    }

    /* ------------------------------------------------------------------ confirm cards */

    public function execute(Request $request): JsonResponse
    {
        $this->authorize('use-ai');
        $u = $request->user();
        $key = 'ai-exec:'.$u->id;
        if (RateLimiter::tooManyAttempts($key, 60)) {
            return response()->json(['ok' => false, 'message' => 'Too many actions in a minute.'], 429);
        }
        RateLimiter::hit($key, 60);

        $res = AiActions::execute($u, (string) $request->input('action'), (array) $request->input('params', []));

        if ($res['ok'] && $request->filled('message_id') && $request->filled('proposal_id')) {
            $m = AiMessage::where('user_id', $u->id)->find($request->integer('message_id'));
            if ($m && $m->proposals) {
                $m->proposals = collect($m->proposals)->map(function ($p) use ($request, $res) {
                    if (($p['id'] ?? null) === $request->input('proposal_id')) {
                        $p['done'] = true;
                        $p['result'] = $res['message'];
                        $p['url'] = $res['url'] ?? null;
                    }

                    return $p;
                })->all();
                $m->save();
            }
        }

        return response()->json($res + ['ref' => AiLog::ref()], $res['ok'] ? 200 : 422);
    }

    /* ------------------------------------------------------------------ attachments + memory */

    public function attachment(Request $request, int $message, int $index)
    {
        $m = AiMessage::where('user_id', $request->user()->id)->findOrFail($message);
        $a = ($m->attachments ?? [])[$index] ?? null;
        abort_unless($a && Storage::disk('local')->exists($a['path']), 404);

        return response(Storage::disk('local')->get($a['path']), 200, ['Content-Type' => $a['mime'], 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function memories(Request $request): JsonResponse
    {
        $this->authorize('use-ai');

        return response()->json(['ok' => true, 'memories' => AiMemory::where('user_id', $request->user()->id)->latest('id')->get(['id', 'category', 'content'])]);
    }

    public function forget(Request $request, AiMemory $memory): JsonResponse
    {
        abort_unless($memory->user_id === $request->user()->id, 404);
        $memory->delete();

        return response()->json(['ok' => true]);
    }

    public function forgetAll(Request $request): JsonResponse
    {
        AiMemory::where('user_id', $request->user()->id)->delete();

        return response()->json(['ok' => true]);
    }

    /* ------------------------------------------------------------------ helpers */

    private function list(Request $request, string $q = ''): array
    {
        return AiConversation::where('user_id', $request->user()->id)
            ->when($q !== '', fn ($x) => $x->where('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%'))
            ->latest('updated_at')->limit(60)->get()
            ->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'ago' => $c->updated_at->diffForHumans(null, true, true), 'at' => $c->updated_at->toIso8601String()])->all();
    }

    private function payload(AiConversation $c): array
    {
        $msgs = AiMessage::where('conversation_id', $c->id)->latest('id')->limit(60)->get()->reverse()->values();

        return ['conversation' => ['id' => $c->id, 'title' => $c->title], 'messages' => $msgs->map(fn ($m) => [
            'id' => $m->id, 'role' => $m->role, 'content' => $m->content, 'proposals' => $m->proposals ?? [], 'blocks' => $m->blocks ?? [], 'followups' => $m->followups ?? [],
            'steps' => $m->steps ?? [], 'thinking_ms' => $m->thinking_ms, 'attachments' => $this->attachmentPayload($m), 'time' => $m->created_at?->format('H:i'),
        ])->all()];
    }

    private function attachmentPayload(AiMessage $m): array
    {
        return collect($m->attachments ?? [])->map(fn ($a, $i) => ['url' => route('ai.attachment', [$m->id, $i]), 'name' => $a['name'], 'kind' => $a['kind'], 'mime' => $a['mime']])->all();
    }

    private function purgeFiles($messages): void
    {
        foreach ($messages as $m) {
            foreach ($m->attachments ?? [] as $a) Storage::disk('local')->delete($a['path']);
        }
    }
}
