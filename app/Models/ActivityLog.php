<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /** What staff may read: everything except activity on a member's personal tasks. */
    public function scopeWithoutPersonalTasks(Builder $q): Builder
    {
        return $q->where(fn (Builder $w) => $w->whereNull('task_id')->orWhereNotIn('task_id', function ($s) {
            $s->select('t.id')->from('tasks as t')
                ->whereIn('t.user_id', fn ($u) => $u->select('id')->from('users')->where('role', 'member'))
                ->where(fn ($a) => $a->whereNull('t.assignee_id')->orWhereColumn('t.assignee_id', 't.user_id'));
        }));
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
