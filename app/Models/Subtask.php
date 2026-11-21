<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subtask extends Model
{
    protected $fillable = ['task_id', 'title', 'is_done', 'position'];

    protected function casts(): array
    {
        return ['is_done' => 'boolean'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
