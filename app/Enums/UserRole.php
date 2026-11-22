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
            self::Admin => 'The one owner account: everything, including managers, the trash and the audit log. Cannot be created, changed or deleted by anyone else.',
            self::Manager => 'Oversees the whole team: sees every task, assigns work, manages categories, approves and manages members.',
            self::Member => 'Private workspace: edits their own tasks; for tasks assigned to them they can complete, tick the checklist and comment.',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
