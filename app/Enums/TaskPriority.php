<?php

namespace App\Enums;

enum TaskPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Low => 'secondary',
            self::Medium => 'info',
            self::High => 'warning',
            self::Urgent => 'danger',
        };
    }

    public function weight(): int
    {
        return match ($this) {
            self::Low => 1, self::Medium => 2, self::High => 3, self::Urgent => 4,
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
