<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;

/**
 * Dedicated AI log: storage/logs/ai-YYYY-MM-DD.log (JSON lines, kept 30 days).
 * The short `ref` is shown in the chat UI, so any message on screen can be found in the log in seconds.
 * API keys never reach the log.
 */
class AiLog
{
    private static $logger = null;

    public static function ref(): string
    {
        try {
            $r = request();
            if ($r->attributes->has('ai_ref')) {
                return $r->attributes->get('ai_ref');
            }
            $ref = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            $r->attributes->set('ai_ref', $ref);

            return $ref;
        } catch (\Throwable) {
            return 'NOREF0';
        }
    }

    public static function log(string $level, string $event, array $ctx = []): void
    {
        try {
            self::$logger ??= Log::build(['driver' => 'daily', 'path' => storage_path('logs/ai.log'), 'level' => 'debug', 'days' => 30]);
            $line = json_encode([
                'ref' => self::ref(), 'user' => auth()->id(), 'event' => $event,
                'ctx' => self::redact($ctx),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            self::$logger->{$level}($line);
        } catch (\Throwable) {
            // logging must never break the request
        }
    }

    public static function info(string $event, array $ctx = []): void { self::log('info', $event, $ctx); }
    public static function warn(string $event, array $ctx = []): void { self::log('warning', $event, $ctx); }
    public static function error(string $event, array $ctx = []): void { self::log('error', $event, $ctx); }

    public static function exception(string $event, \Throwable $e, array $ctx = []): void
    {
        self::error($event, $ctx + ['exception' => get_class($e), 'message' => $e->getMessage(), 'at' => basename($e->getFile()).':'.$e->getLine()]);
    }

    private static function redact(array $ctx): array
    {
        array_walk_recursive($ctx, function (&$v, $k) {
            if (is_string($v)) {
                $v = preg_replace('/AIza[0-9A-Za-z_\-]{20,}/', '[redacted-key]', $v);
                if (preg_match('/key|token|password/i', (string) $k)) {
                    $v = '[redacted]';
                }
            }
        });

        return $ctx;
    }
}
