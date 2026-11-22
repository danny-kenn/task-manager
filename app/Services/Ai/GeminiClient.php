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
    private const MAX_ATTEMPTS = 3; // fail fast: the assistant falls back to instant live-data answers

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
        return Cache::remember('gemini.models.v2', 12 * 3600, function () {
            $keys = $this->keys();
            if (! $keys) {
                return self::FALLBACK_MODELS;
            }
            try {
                $res = Http::timeout(15)->connectTimeout(15)->withOptions(['verify' => $this->ca(), 'force_ip_resolve' => 'v4'])->withHeaders(['x-goog-api-key' => $keys[0]])->get($this->base().'/models', ['pageSize' => 200]);
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
                $found = array_keys($scores);
                // Speed first: these respond fastest for chat + tools; the rest follow as backups.
                $fast = array_values(array_filter(['gemini-2.5-flash', 'gemini-flash-lite-latest', 'gemini-flash-latest'], fn ($m) => in_array($m, $found, true)));

                return array_slice(array_values(array_unique(array_merge($fast, $found))), 0, 5) ?: self::FALLBACK_MODELS;
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

    /** When streaming we ask for thought summaries so the UI can show the assistant "thinking" in real time. */
    private function thinking(string $model, bool $thoughts = false): ?array
    {
        $inc = $thoughts ? ['includeThoughts' => true] : [];
        if (preg_match('/^gemini-2\.5-flash/', $model)) return $thoughts ? ['thinkingBudget' => 128] + $inc : ['thinkingBudget' => 0];
        if (preg_match('/^gemini-(\d+)/', $model, $m) && (int) $m[1] >= 3) return ['thinkingLevel' => 'low'] + $inc;
        if (str_contains($model, '-latest')) return ['thinkingLevel' => 'low'] + $inc;

        return null;
    }

    /**
     * Read a Server-Sent-Events body incrementally. Calls $onDelta('think'|'text', string) as pieces arrive.
     * @return array{text:string,calls:array,keep:array,tokens:?int}
     */
    /**
     * One tiny, token-light request for diagnostics (ai:check / test_gemini.php).
     *
     * @return array{http:int,ms:int,err:string,msg:string,ip:string,dns_ms:int,connect_ms:int,text:string}
     */
    public function ping(string $key, string $model, ?string $listUrl = null): array
    {
        $url = $listUrl ?? $this->base().'/models/'.rawurlencode($model).':generateContent';
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: '.$key],
        ];
        if (! $listUrl) {
            $opts += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['contents' => [['parts' => [['text' => 'Reply with only the word OK']]]], 'generationConfig' => ['maxOutputTokens' => 16]])];
        }
        $ca = $this->ca();
        if (is_string($ca)) $opts[CURLOPT_CAINFO] = $ca;
        curl_setopt_array($ch, $opts);
        $body = (string) curl_exec($ch);
        $err = curl_errno($ch) ? 'cURL error '.curl_errno($ch).': '.curl_error($ch) : '';
        $i = curl_getinfo($ch);
        curl_close($ch);
        $j = json_decode($body, true) ?: [];
        $r = ['http' => (int) $i['http_code'], 'ms' => (int) round($i['total_time'] * 1000), 'err' => $err, 'msg' => (string) ($j['error']['message'] ?? ''), 'ip' => (string) ($i['primary_ip'] ?? ''), 'dns_ms' => (int) round($i['namelookup_time'] * 1000), 'connect_ms' => (int) round($i['connect_time'] * 1000), 'text' => trim((string) ($j['candidates'][0]['content']['parts'][0]['text'] ?? '')), 'body' => $j];
        AiLog::info('gemini.ping', ['model' => $model, 'http' => $r['http'], 'ms' => $r['ms'], 'dns_ms' => $r['dns_ms'], 'err' => $err, 'msg' => mb_substr($r['msg'], 0, 160)]);

        return $r;
    }

    /** CA bundle for HTTPS. Windows/XAMPP often has none, which breaks every call to Google ("SSL certificate problem"). */
    private function ca(): string|bool
    {
        foreach ([env('GEMINI_CA_BUNDLE'), ini_get('curl.cainfo'), ini_get('openssl.cafile')] as $p) {
            if ($p && is_file($p)) return $p;
        }
        $own = resource_path('certs/cacert.pem');

        return is_file($own) ? $own : true;
    }

    /**
     * Streaming call with plain cURL (Laravel's Http stream mode falls back to PHP streams, which have no CA file on XAMPP).
     * Feeds server-sent events to $onDelta as they arrive.
     *
     * @return array{http:int,err:string,msg:string,text:string,calls:array,keep:array,tokens:?int}
     */
    private function streamRequest(string $url, string $key, array $body, callable $onDelta): array
    {
        $buf = ''; $raw = ''; $text = ''; $calls = []; $keep = []; $tokens = null;

        $handle = function (string $line) use (&$text, &$calls, &$keep, &$tokens, $onDelta) {
            $line = rtrim($line, "\r");
            if (! str_starts_with($line, 'data:')) return;
            $j = json_decode(trim(substr($line, 5)), true);
            if (! is_array($j)) return;
            $tokens = $j['usageMetadata']['totalTokenCount'] ?? $tokens;
            foreach ((array) ($j['candidates'][0]['content']['parts'] ?? []) as $p) {
                if (! empty($p['thought'])) {
                    if (isset($p['text']) && $p['text'] !== '') $onDelta('think', $p['text']);
                    continue;
                }
                if (isset($p['functionCall'])) {
                    $calls[] = ['name' => $p['functionCall']['name'] ?? '', 'args' => $p['functionCall']['args'] ?? []];
                    $keep[] = $p;
                } elseif (isset($p['text'])) {
                    $text .= $p['text'];
                    $onDelta('text', $p['text']);
                    $last = count($keep) - 1;
                    if ($last >= 0 && isset($keep[$last]['text'])) $keep[$last]['text'] .= $p['text'];
                    else $keep[] = ['text' => $p['text']] + (isset($p['thoughtSignature']) ? ['thoughtSignature' => $p['thoughtSignature']] : []);
                }
            }
        };

        $ch = curl_init($url);
        $ca = $this->ca();
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: '.$key],
            CURLOPT_CONNECTTIMEOUT => 20,           // slow Windows DNS counts against this, so be generous
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, // XAMPP/Windows: broken IPv6 lookups cause "Could not resolve host"
            CURLOPT_DNS_CACHE_TIMEOUT => 300,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buf, &$raw, $handle) {
                if (strlen($raw) < 20000) $raw .= $chunk;
                $buf .= $chunk;
                while (($pos = strpos($buf, "\n")) !== false) {
                    $handle(substr($buf, 0, $pos));
                    $buf = substr($buf, $pos + 1);
                }

                return strlen($chunk);
            },
        ]);
        if (is_string($ca)) curl_setopt($ch, CURLOPT_CAINFO, $ca);
        $tries = 0;
        do {
            $tries++;
            $raw = ''; $buf = '';
            curl_exec($ch);
            $errno = curl_errno($ch);
        } while ($errno === 6 && $tries < 2 && usleep(400000) === null); // one quick retry for a flaky DNS lookup
        $err = $errno ? 'cURL error '.$errno.': '.curl_error($ch) : '';
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $info = curl_getinfo($ch);
        AiLog::info('gemini.timing', ['http' => $http, 'dns_ms' => round(($info['namelookup_time'] ?? 0) * 1000), 'connect_ms' => round(($info['connect_time'] ?? 0) * 1000), 'tls_ms' => round(($info['appconnect_time'] ?? 0) * 1000), 'first_byte_ms' => round(($info['starttransfer_time'] ?? 0) * 1000), 'total_ms' => round(($info['total_time'] ?? 0) * 1000), 'ip' => $info['primary_ip'] ?? '', 'tries' => $tries, 'err' => $err]);
        curl_close($ch);
        if (trim($buf) !== '') $handle($buf);
        $msg = '';
        if ($http !== 200 && $http !== 0) $msg = (string) (json_decode($raw, true)['error']['message'] ?? mb_substr($raw, 0, 200));

        return ['http' => $err && $http === 0 ? 0 : $http, 'err' => $err, 'msg' => $msg, 'text' => $text, 'calls' => $calls, 'keep' => $keep, 'tokens' => $tokens];
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
    public function generate(array $contents, string $system, array $decls = [], array $gen = [], ?callable $onDelta = null): ?array
    {
        $this->failure = null;
        $this->modelUsed = null;
        if (! $this->enabled()) {
            $this->failure = ['category' => 'config', 'message' => 'The AI key is not configured (GEMINI_API_KEYS is empty in .env).'];

            return null;
        }

        $attempts = [];
        foreach (array_slice($this->candidates(), 0, self::MAX_ATTEMPTS) as $c) {
            $stream = $onDelta !== null;
            $cfg = array_merge(['temperature' => 0.3, 'maxOutputTokens' => $stream ? 3000 : 1500], $gen);
            if ($t = $this->thinking($c['model'], $stream)) $cfg['thinkingConfig'] = $t;
            $body = ['systemInstruction' => ['parts' => [['text' => $system]]], 'contents' => $contents, 'generationConfig' => $cfg];
            if ($decls) $body['tools'] = [['functionDeclarations' => $this->cleanDecls($decls)]];

            $url = $this->base().'/models/'.rawurlencode($c['model']).($stream ? ':streamGenerateContent?alt=sse' : ':generateContent');

            if ($stream) {
                $r = $this->streamRequest($url, $c['key'], $body, $onDelta);
                if ($r['err'] !== '' && $r['http'] === 0) {
                    $attempts[] = ['model' => $c['model'], 'http' => 0, 'msg' => $r['err']];
                    $this->block('-', $c['model'], str_contains($r['err'], 'error 28') ? 120 : 15); // a model that stalled is skipped for 2 min
                    AiLog::warn('gemini.network', ['model' => $c['model'], 'error' => $r['err']]);
                    continue;
                }
                if ($r['http'] === 200) {
                    if ($r['text'] === '' && ! $r['calls']) {
                        $attempts[] = ['model' => $c['model'], 'http' => 200, 'msg' => 'empty'];
                        continue;
                    }
                    $this->modelUsed = $c['model'];
                    AiLog::info('gemini.ok', ['model' => $c['model'], 'stream' => true, 'tools' => array_column($r['calls'], 'name'), 'tokens' => $r['tokens']]);

                    return ['text' => trim($r['text']), 'calls' => $r['calls'], 'parts' => $r['keep']];
                }
                $http = $r['http'];
                $msg = $r['msg'];
                $res = null;
            } else {
                try {
                    $res = Http::timeout(25)->connectTimeout(20)->withOptions(['verify' => $this->ca(), 'force_ip_resolve' => 'v4'])->withHeaders(['x-goog-api-key' => $c['key']])->post($url, $body);
                } catch (\Throwable $e) {
                    $attempts[] = ['model' => $c['model'], 'http' => 0, 'msg' => $e->getMessage()];
                    $this->block('-', $c['model'], 15);
                    AiLog::warn('gemini.network', ['model' => $c['model'], 'error' => $e->getMessage()]);
                    continue;
                }
                $http = $res->status();
                $msg = $http === 200 ? '' : (string) $res->json('error.message', '');
            }

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

    private function firstNetworkError(array $a): string
    {
        foreach ($a as $x) if ($x['http'] === 0 && ! empty($x['msg'])) return (string) $x['msg'];

        return '';
    }

    private function explain(array $a): void
    {
        $has = fn (callable $f) => (bool) array_filter($a, $f);
        $this->failure = match (true) {
            $has(fn ($x) => in_array($x['http'], [401, 403], true) || ($x['http'] === 400 && preg_match('/API key/i', $x['msg']))) => ['category' => 'auth', 'message' => 'Google rejected the AI key (invalid, expired or restricted).'],
            $has(fn ($x) => $x['http'] === 429) => ['category' => 'quota', 'message' => 'The AI is rate-limited right now. Try again in a minute.'],
            $has(fn ($x) => ! empty($x['blocked'])) => ['category' => 'blocked', 'message' => 'The AI declined to answer that request.'],
            $has(fn ($x) => $x['http'] === 0) => ['category' => 'network', 'message' => 'This server could not reach Google’s AI ('.mb_substr((string) ($this->firstNetworkError($a) ?: 'network or DNS problem'), 0, 160).').'],
            $has(fn ($x) => $x['http'] >= 500) => ['category' => 'server', 'message' => 'Google’s AI service had an internal error. Try again shortly.'],
            default => ['category' => 'unknown', 'message' => 'The AI did not answer.'],
        };
        AiLog::error('gemini.all_failed', ['category' => $this->failure['category'], 'attempts' => $a]);
    }
}
