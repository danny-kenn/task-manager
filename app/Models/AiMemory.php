<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One remembered fact about ONE user. Categories: name | preference | work | fact. */
class AiMemory extends Model
{
    public const CATEGORIES = ['name', 'preference', 'work', 'fact'];

    protected $fillable = ['user_id', 'category', 'content'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
