<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppNotification extends Model
{
    protected $fillable = ['user_id', 'event', 'title', 'body', 'url', 'icon', 'actor_id', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id, 'event' => $this->event, 'title' => $this->title, 'body' => $this->body,
            'url' => $this->url, 'icon' => $this->icon, 'read' => $this->read_at !== null,
            'time' => $this->created_at?->diffForHumans(), 'at' => $this->created_at?->toIso8601String(),
            'actor' => $this->actor?->only(['name', 'avatar_color']) + ['avatar_url' => $this->actor?->avatar_url, 'initials' => $this->actor?->initials],
        ];
    }
}
