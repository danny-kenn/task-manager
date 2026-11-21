<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Member = 'member';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Admin => 'danger', self::Manager => 'primary', self::Member => 'secondary',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Full access: every task, every user, categories, trash and the audit log.',
            self::Manager => 'Sees and manages every task, assigns work to members, manages categories and the audit log.',
            self::Member => 'Manages their own tasks and the tasks assigned to them.',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
