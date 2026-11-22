<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'conversation_id', 'role', 'content', 'proposals', 'model', 'blocks', 'attachments', 'followups', 'steps', 'thinking_ms'];

    protected function casts(): array
    {
        return ['proposals' => 'array', 'blocks' => 'array', 'attachments' => 'array', 'followups' => 'array', 'steps' => 'array', 'created_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
