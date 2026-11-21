<?php

namespace App\Enums;

enum ShiftRole: string
{
    case Supervisor = 'supervisor';
    case Carpenter = 'carpintero';
    case Helper = 'ayudante';

    public function label(): string
    {
        return match ($this) {
            self::Supervisor => LogisticsRole::Supervisor->value,
            self::Carpenter => LogisticsRole::Carpenter->value,
            self::Helper => LogisticsRole::Helper->value,
        };
    }
}
