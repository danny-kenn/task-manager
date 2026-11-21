<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Small, resilient Gemini client (same ideas as the portfolio engine, trimmed to what a task assistant needs).
 *   - several API keys, rotated; a rate-limited key/model pair is skipped for a while without a network call
 *   - models are discovered from Google every 12 h, so a retired model drops out with no code change
 *   - every failure is turned into a plain-English reason (no key / rejected key / quota / network / safety)
 */
class GeminiClient
{
    private const FALLBACK_MODELS = ['gemini-flash-latest', 'gemini-2.5-flash', 'gemini-3-flash-preview', 'gemini-flash-lite-latest'];
    private const MAX_ATTEMPTS = 6;

    public ?string $modelUsed = null;
    /** @var array{category:string,message:string}|null */
    public ?array $failure = null;

    public function keys(): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', (string) config('services.gemini.keys', '')) as $k) {
            $k = trim($k);
            if ($k !== '' && ! in_array($k, $out, true)) {
                $out[] = $k;
            }
        }

        return $out;
    }

    public function enabled(): bool
    {
        return (bool) $this->keys();
    }

    private function base(): string
    {
        return rtrim((string) config('services.gemini.endpoint'), '/');
    }

    private function kid(string $key): string
    {
        return substr(sha1($key), 0, 10);
    }

    public function models(): array
    {
        return Cache::remember('gemini.models.v1', 12 * 3600, function () {
            $keys = $this->keys();
            if (! $keys) {
                return self::FALLBACK_MODELS;
            }
            try {
                $res = Http::timeout(8)->withHeaders(['x-goog-api-key' => $keys[0]])->get($this->base().'/models', ['pageSize' => 200]);
                if (! $res->ok()) {
                    return self::FALLBACK_MODELS;
                }
                $scores = [];
                foreach ((array) $res->json('models', []) as $m) {
                    $name = preg_replace('#^models/#', '', (string) ($m['name'] ?? ''));
                    if (! in_array('generateContent', (array) ($m['supportedGenerationMethods'] ?? []), true)) continue;
                    if (! preg_match('/^gemini-.*flash/', $name)) continue;
                    if (preg_match('/image|tts|live|audio|embed|robot|computer|exp|thinking|8b|vision/i', $name)) continue;
                    $score = preg_match('/gemini-(\d+(?:\.\d+)?)/', $name, $v) ? (float) $v[1] * 100 : 100;
                    if (str_contains($name, 'lite')) $score -= 40;
                    if (str_contains($name, 'preview')) $score -= 15;
                    if ($name === 'gemini-flash-latest') $score = 900;
                    $scores[$name] = $score;
                }
                arsort($scores);

                return array_slice(array_keys($scores), 0, 5) ?: self::FALLBACK_MODELS;
            } catch (\Throwable $e) {
                AiLog::exception('gemini.list_models', $e);

                return self::FALLBACK_MODELS;
            }
        });
    }

    private function blocked(string $kid, string $model): bool
    {
        return Cache::has("gem:b:$kid:*") || Cache::has("gem:b:$kid:$model");
    }

    private function block(string $kid, string $model, int $secs): void
    {
        Cache::put("gem:b:$kid:$model", 1, max(5, $secs));
    }

    /** Healthy (model, key) pairs, keys rotated so load spreads. */
    private function candidates(): array
    {
        $keys = $this->keys();
        if (! $keys) return [];
        $offset = (int) (floor(microtime(true) / 20) % count($keys));
        $keys = array_merge(array_slice($keys, $offset), array_slice($keys, 0, $offset));
        $c = [];
        foreach ($this->models() as $model) {
            foreach ($keys as $key) {
                if (! $this->blocked($this->kid($key), $model)) {
                    $c[] = ['model' => $model, 'key' => $key, 'kid' => $this->kid($key)];
                }
            }
        }

        return $c ?: [['model' => $this->models()[0], 'key' => $keys[0], 'kid' => $this->kid($keys[0])]];
    }

    private function thinking(string $model): ?array
    {
        if (preg_match('/^gemini-2\.5-flash/', $model)) return ['thinkingBudget' => 0];
        if (preg_match('/^gemini-(\d+)/', $model, $m) && (int) $m[1] >= 3) return ['thinkingLevel' => 'low'];
        if (str_contains($model, '-latest')) return ['thinkingLevel' => 'low'];

        return null;
    }

    /** Google rejects `properties: []`; tools without arguments must omit `parameters`. */
    private function cleanDecls(array $decls): array
    {
        foreach ($decls as &$d) {
            $props = $d['parameters']['properties'] ?? null;
            if (isset($d['parameters'])) {
                if (! $props) {
                    unset($d['parameters']);
                } else {
                    $d['parameters']['properties'] = (object) $props;
                }
            }
        }

        return $decls;
    }

    /**
     * One model turn. Returns ['text' => string, 'calls' => [{name,args}], 'parts' => raw model parts] or null.
     * On null, $this->failure explains why in plain English.
     */
    public function generate(array $contents, string $system, array $decls = [], array $gen = []): ?array
    {
        $this->failure = null;
        $this->modelUsed = null;
        if (! $this->enabled()) {
            $this->failure = ['category' => 'config', 'message' => 'The AI key is not configured (GEMINI_API_KEYS is empty in .env).'];

            return null;
        }

        $attempts = [];
        foreach (array_slice($this->candidates(), 0, self::MAX_ATTEMPTS) as $c) {
            $cfg = array_merge(['temperature' => 0.3, 'maxOutputTokens' => 1500], $gen);
            if ($t = $this->thinking($c['model'])) $cfg['thinkingConfig'] = $t;
            $body = ['systemInstruction' => ['parts' => [['text' => $system]]], 'contents' => $contents, 'generationConfig' => $cfg];
            if ($decls) $body['tools'] = [['functionDeclarations' => $this->cleanDecls($decls)]];

            try {
                $res = Http::timeout(30)->connectTimeout(6)->withHeaders(['x-goog-api-key' => $c['key']])
                    ->post($this->base().'/models/'.rawurlencode($c['model']).':generateContent', $body);
            } catch (\Throwable $e) {
                $attempts[] = ['model' => $c['model'], 'http' => 0, 'msg' => $e->getMessage()];
                $this->block('-', $c['model'], 15);
                AiLog::warn('gemini.network', ['model' => $c['model'], 'error' => $e->getMessage()]);
                continue;
            }

            $http = $res->status();
            $msg = (string) $res->json('error.message', '');

            if ($http === 200) {
                $parts = (array) $res->json('candidates.0.content.parts', []);
                $text = '';
                $calls = [];
                $keep = [];
                foreach ($parts as $p) {
                    if (! empty($p['thought'])) continue;
                    $keep[] = $p;
                    if (isset($p['functionCall'])) $calls[] = ['name' => $p['functionCall']['name'] ?? '', 'args' => $p['functionCall']['args'] ?? []];
                    elseif (isset($p['text'])) $text .= $p['text'];
                }
                if ($text === '' && ! $calls) {
                    $block = $res->json('promptFeedback.blockReason');
                    $attempts[] = ['model' => $c['model'], 'http' => 200, 'msg' => 'empty', 'blocked' => $block];
                    continue;
                }
                $this->modelUsed = $c['model'];
                AiLog::info('gemini.ok', ['model' => $c['model'], 'tools' => array_column($calls, 'name'), 'tokens' => $res->json('usageMetadata.totalTokenCount')]);

                return ['text' => trim($text), 'calls' => $calls, 'parts' => $keep];
            }

            $attempts[] = ['model' => $c['model'], 'http' => $http, 'msg' => mb_substr($msg, 0, 200)];
            AiLog::warn('gemini.attempt_failed', ['model' => $c['model'], 'http' => $http, 'msg' => mb_substr($msg, 0, 300)]);

            if (in_array($http, [400, 401, 403], true) && preg_match('/API key|permission|denied|disabled|billing|not valid|expired/i', $msg)) {
                $this->block($c['kid'], '*', 6 * 3600);
            } elseif ($http === 429) {
                $secs = preg_match('/per day|daily/i', $msg) ? 3 * 3600 : 60;
                if (preg_match('/retry in ([\d.]+)s/i', $msg, $m)) $secs = (int) ceil((float) $m[1]) + 3;
                $this->block($c['kid'], $c['model'], $secs);
            } elseif ($http === 404) {
                $this->block($c['kid'], $c['model'], 6 * 3600);
            } elseif ($http >= 500) {
                $this->block('-', $c['model'], 20);
            } elseif ($http === 400 && preg_match('/Invalid value|Cannot bind|Unknown name|function_declarations/i', $msg)) {
                AiLog::error('gemini.bad_request', ['reason' => $msg]);
                break; // our request is malformed: other models would fail the same way
            }
        }

        $this->explain($attempts);

        return null;
    }

    private function explain(array $a): void
    {
        $has = fn (callable $f) => (bool) array_filter($a, $f);
        $this->failure = match (true) {
            $has(fn ($x) => in_array($x['http'], [401, 403], true) || ($x['http'] === 400 && preg_match('/API key/i', $x['msg']))) => ['category' => 'auth', 'message' => 'Google rejected the AI key (invalid, expired or restricted).'],
            $has(fn ($x) => $x['http'] === 429) => ['category' => 'quota', 'message' => 'The AI is rate-limited right now. Try again in a minute.'],
            $has(fn ($x) => ! empty($x['blocked'])) => ['category' => 'blocked', 'message' => 'The AI declined to answer that request.'],
            $has(fn ($x) => $x['http'] === 0) => ['category' => 'network', 'message' => 'This server could not reach Google’s AI (network or DNS problem).'],
            $has(fn ($x) => $x['http'] >= 500) => ['category' => 'server', 'message' => 'Google’s AI service had an internal error. Try again shortly.'],
            default => ['category' => 'unknown', 'message' => 'The AI did not answer.'],
        };
        AiLog::error('gemini.all_failed', ['category' => $this->failure['category'], 'attempts' => $a]);
    }
}
