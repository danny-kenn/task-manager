<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'task_id', 'action', 'description', 'properties', 'source', 'ip_address'];

    protected function casts(): array
    {
        return ['properties' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function icon(): string
    {
        return match ($this->action) {
            'created' => 'fa-plus-circle text-success',
            'updated' => 'fa-pen text-info',
            'status_changed' => 'fa-exchange-alt text-warning',
            'deleted' => 'fa-trash text-danger',
            'restored' => 'fa-undo text-success',
            'reordered' => 'fa-arrows-alt-v text-muted',
            'commented' => 'fa-comment text-primary',
            default => 'fa-circle text-muted',
        };
    }
}
