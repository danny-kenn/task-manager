<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'is_active', 'avatar_color', 'timezone', 'timezone_manual', 'avatar_path', 'notify_prefs', 'onboarded_at', 'last_login_at', 'approved_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'timezone_manual' => 'boolean',
            'notify_prefs' => 'array',
            'onboarded_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /* ---------------------------------------------------------------- relations */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    public function activity(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function aiMessages(): HasMany
    {
        return $this->hasMany(AiMessage::class);
    }

    public function aiConversations(): HasMany
    {
        return $this->hasMany(AiConversation::class);
    }

    public function aiMemories(): HasMany
    {
        return $this->hasMany(AiMemory::class);
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class);
    }

    /** Public URL of the uploaded profile photo (cache-busted), or null when the user has none. */
    public function getAvatarUrlAttribute(): ?string
    {
        if (! $this->avatar_path || ! is_file(public_path($this->avatar_path))) {
            return null;
        }

        return asset($this->avatar_path).'?v='.filemtime(public_path($this->avatar_path));
    }

    public function getFirstNameAttribute(): string
    {
        return explode(' ', trim($this->name))[0] ?: $this->name;
    }

    /** Does this user want $event delivered through $channel (in_app | email | push)? Falls back to the defaults. */
    public function wantsNotification(string $event, string $channel): bool
    {
        if (! isset(\App\Services\Notifier::eventsFor($this)[$event])) return false;
        $saved = $this->notify_prefs[$event][$channel] ?? null;

        return $saved === null ? (\App\Services\Notifier::EVENTS[$event]['defaults'][$channel] ?? false) : (bool) $saved;
    }

    /* ---------------------------------------------------------------- RBAC helpers */
    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isManager(): bool
    {
        return $this->role === UserRole::Manager;
    }

    /** Admins and managers can see and manage everybody's tasks. */
    /** Waiting for a manager/admin to approve a self-registered account. */
    public function isPending(): bool
    {
        return ! $this->is_active && $this->approved_at === null;
    }

    /** Roles this person may hand out. There is exactly ONE admin, so nobody can create or promote another. */
    public function assignableRoles(): array
    {
        return match (true) {
            $this->isAdmin() => [UserRole::Manager, UserRole::Member],
            $this->isManager() => [UserRole::Member],
            default => [],
        };
    }

    /** Admin manages managers + members; a manager manages members only; nobody touches the admin or themselves here. */
    public function canManageUser(User $target): bool
    {
        if ($target->id === $this->id || $target->isAdmin()) {
            return false;
        }

        return match (true) {
            $this->isAdmin() => true,
            $this->isManager() => $target->role === UserRole::Member,
            default => false,
        };
    }

    public function isStaff(): bool
    {
        return $this->isAdmin() || $this->isManager();
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role?->value, $roles, true);
    }

    public function getInitialsAttribute(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $letters = array_map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)), array_slice($parts, 0, 2));

        return implode('', $letters) ?: '?';
    }
}
