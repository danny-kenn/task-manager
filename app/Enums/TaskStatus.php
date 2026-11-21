<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Bootstrap 4 contextual colour. */
    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Completed => 'success',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Pending => 'fa-clock',
            self::Completed => 'fa-check-circle',
        };
    }

    public function opposite(): self
    {
        return $this === self::Pending ? self::Completed : self::Pending;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
