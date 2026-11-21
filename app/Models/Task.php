<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id', 'assignee_id', 'category_id', 'title', 'description',
        'status', 'priority', 'due_date', 'completed_at', 'position',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    /* ---------------------------------------------------------------- relations */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'task_tag');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Subtask::class)->orderBy('position')->orderBy('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->latest();
    }

    /* ---------------------------------------------------------------- accessors */
    public function getIsCompletedAttribute(): bool
    {
        return $this->status === TaskStatus::Completed;
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->due_date !== null
            && $this->status === TaskStatus::Pending
            && $this->due_date->isBefore(today());
    }

    /** 0-100: share of finished subtasks (100 when the task itself is completed). */
    public function getProgressAttribute(): int
    {
        if ($this->is_completed) {
            return 100;
        }
        $total = $this->relationLoaded('subtasks') ? $this->subtasks->count() : $this->subtasks()->count();
        if ($total === 0) {
            return 0;
        }
        $done = $this->relationLoaded('subtasks') ? $this->subtasks->where('is_done', true)->count() : $this->subtasks()->where('is_done', true)->count();

        return (int) round($done / $total * 100);
    }

    /* ---------------------------------------------------------------- scopes */

    /** Row-level visibility: staff see everything, members see tasks they own or that are assigned to them. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->isStaff()) {
            return $q;
        }

        return $q->where(fn (Builder $w) => $w->where('user_id', $user->id)->orWhere('assignee_id', $user->id));
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        return $q->where(function (Builder $w) use ($like, $term) {
            $w->where('title', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhereHas('tags', fn (Builder $t) => $t->where('name', 'like', $like));
            // allow searching by status word too: "pending" / "completed"
            if (in_array(strtolower($term), TaskStatus::values(), true)) {
                $w->orWhere('status', strtolower($term));
            }
        });
    }

    public function scopeOverdue(Builder $q): Builder
    {
        return $q->where('status', TaskStatus::Pending->value)->whereNotNull('due_date')->whereDate('due_date', '<', today());
    }

    public function scopeManualOrder(Builder $q): Builder
    {
        return $q->orderBy('position')->orderByDesc('id');
    }
}
