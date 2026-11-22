<?php

namespace App\Console\Commands;

use App\Services\Ai\AiLog;
use App\Services\Ai\GeminiClient;
use Illuminate\Console\Command;

/**
 * php artisan ai:check            — full health check: DNS, TCP, TLS, every key × every model, with timings.
 * php artisan ai:check --quick    — one key, one model.
 * Everything is also written to storage/logs/ai-YYYY-MM-DD.log.
 */
class AiCheck extends Command
{
    protected $signature = 'ai:check {--quick : only test the first key against one model}';
    protected $description = 'Test every Gemini key against every model and show exactly what is wrong';

    private const HOST = 'generativelanguage.googleapis.com';

    public function handle(GeminiClient $g): int
    {
        $keys = $g->keys();
        $this->line('<options=bold>1. Environment</>');
        $this->line('   PHP '.PHP_VERSION.' · '.PHP_OS_FAMILY.' · SAPI '.PHP_SAPI);
        if (! function_exists('curl_version')) { $this->error('   cURL extension is missing (enable extension=curl in php.ini).'); return self::FAILURE; }
        $cv = curl_version();
        $this->line('   curl '.$cv['version'].' · '.$cv['ssl_version'].' · IPv6 '.(($cv['features'] & CURL_VERSION_IPV6) ? 'yes' : 'no'));
        $this->line('   Keys in .env (GEMINI_API_KEYS): '.count($keys));
        if (! $keys) { $this->error('   Set GEMINI_API_KEYS in .env (comma separated), then php artisan config:clear.'); return self::FAILURE; }
        AiLog::info('check.start', ['keys' => count($keys), 'php' => PHP_VERSION, 'curl' => $cv['version']]);

        $this->line('<options=bold>2. Network</>');
        $t = microtime(true);
        $ips = @gethostbynamel(self::HOST) ?: [];
        $ms = round((microtime(true) - $t) * 1000);
        $this->line('   DNS '.self::HOST.' → '.($ips ? implode(', ', $ips) : '<fg=red>FAILED</>')." ({$ms} ms)");
        $t = microtime(true);
        $sock = @fsockopen($ips[0] ?? self::HOST, 443, $en, $es, 10);
        $this->line('   TCP :443 → '.($sock ? '<fg=green>open</>' : "<fg=red>blocked ($es)</>").' ('.round((microtime(true) - $t) * 1000).' ms)');
        if ($sock) fclose($sock);
        AiLog::info('check.network', ['dns_ok' => (bool) $ips, 'dns_ms' => $ms, 'tcp_ok' => (bool) $sock]);

        $this->line('<options=bold>3. Models Google lists for key #1</>');
        $list = $g->ping($keys[0], '', rtrim((string) config('services.gemini.endpoint'), '/').'/models?pageSize=200');
        $models = [];
        if ($list['http'] === 200) {
            foreach ((array) ($list['body']['models'] ?? []) as $m) {
                $id = str_replace('models/', '', $m['name'] ?? '');
                if (preg_match('/(flash|lite)/i', $id) && ! preg_match('/(image|tts|audio|live|embedding|robotics|computer|thinking-exp)/i', $id) && in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true)) $models[] = $id;
            }
            $this->line('   <fg=green>OK</> — '.count($list['body']['models'] ?? []).' models, '.count($models).' usable for chat ('.$list['ms'].' ms)');
        } else {
            $this->line('   <fg=red>FAILED</> HTTP '.$list['http'].' '.($list['err'] ?: $list['msg']));
        }
        $app = array_values(array_unique(array_merge(['gemini-2.5-flash', 'gemini-flash-latest', 'gemini-flash-lite-latest'], $g->models())));
        $models = array_values(array_unique(array_merge($app, array_slice($models, 0, 6))));
        if ($this->option('quick')) $models = array_slice($models, 0, 1);

        $this->line('<options=bold>4. Every key × model</> (one tiny request each)');
        $ok = []; $bad = [];
        foreach ($keys as $ki => $key) {
            $label = 'key #'.($ki + 1).' ('.substr($key, 0, 6).'…)';
            if ($this->option('quick') && $ki > 0) break;
            foreach ($models as $m) {
                $r = $g->ping($key, $m);
                [$tag, $color] = match (true) {
                    $r['http'] === 200 => ['OK', 'green'],
                    $r['http'] === 429 => ['QUOTA', 'yellow'],
                    in_array($r['http'], [401, 403], true) || ($r['http'] === 400 && stripos($r['msg'], 'API key') !== false) => ['BAD KEY', 'red'],
                    in_array($r['http'], [400, 404], true) => ['NO MODEL', 'yellow'],
                    $r['http'] === 0 => ['NETWORK', 'red'],
                    default => ['HTTP '.$r['http'], 'red'],
                };
                $this->line(sprintf('   %-18s %-30s <fg=%s>%-9s</> %5d ms  dns %d ms  %s', $label, $m, $color, $tag, $r['ms'], $r['dns_ms'], mb_strimwidth($r['err'] ?: ($r['http'] === 200 ? '“'.$r['text'].'”' : $r['msg']), 0, 70, '…')));
                if ($tag === 'OK') $ok[$m][] = $ki + 1; else $bad[] = [$ki + 1, $m, $tag];
                if ($tag === 'NETWORK') { $this->error('   Cannot reach Google from this server — skipping the rest. See the error above.'); AiLog::error('check.network_fail', ['err' => $r['err']]); return self::FAILURE; }
                if ($tag === 'BAD KEY') break; // the key itself is wrong; other models would say the same
            }
        }

        $this->newLine();
        $this->line('<options=bold>5. Result</>');
        $this->line('   Working: '.($ok ? implode(' · ', array_map(fn ($m, $k) => "$m (keys ".implode(',', $k).')', array_keys($ok), $ok)) : '<fg=red>none</>'));
        $this->line('   Problems: '.(count($bad) ? count($bad).' (see table; QUOTA resets daily, NO MODEL = retired for that key)' : 'none'));
        $this->line('   Log: storage/logs/ai-'.date('Y-m-d').'.log');
        AiLog::info('check.done', ['ok_models' => array_keys($ok), 'problems' => count($bad)]);

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
