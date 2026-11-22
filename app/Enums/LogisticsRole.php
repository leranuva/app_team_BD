<?php

namespace App\Enums;

enum LogisticsRole: string
{
    case SuperAdmin = 'Super Admin';
    case Administrator = 'Administrador';
    case Supervisor = 'Supervisor';
    case Carpenter = 'Carpintero';
    case Helper = 'Ayudante';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Administrator => 'Administrator',
            self::Supervisor => 'Supervisor',
            self::Carpenter => 'Carpenter',
            self::Helper => 'Helper',
        };
    }

    public static function displayName(string $name): string
    {
        return self::tryFrom($name)?->label() ?? $name;
    }
}
