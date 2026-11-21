<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;

/** Single entry point for the audit trail. Never throws: logging must not break a request. */
class ActivityLogger
{
    public static function record(?User $user, ?int $taskId, string $action, string $description, array $properties = [], string $source = 'web'): ?ActivityLog
    {
        try {
            return ActivityLog::create([
                'user_id' => $user?->id,
                'task_id' => $taskId,
                'action' => $action,
                'description' => mb_substr($description, 0, 255),
                'properties' => $properties ?: null,
                'source' => $source,
                'ip_address' => request()?->ip(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
