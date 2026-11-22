<?php

namespace App\Enums;

enum ShiftRole: string
{
    case Supervisor = 'supervisor';
    case Carpenter = 'carpintero';
    case Helper = 'ayudante';

    public function label(): string
    {
        return $this->logisticsRole()->label();
    }

    public function logisticsRole(): LogisticsRole
    {
        return match ($this) {
            self::Supervisor => LogisticsRole::Supervisor,
            self::Carpenter => LogisticsRole::Carpenter,
            self::Helper => LogisticsRole::Helper,
        };
    }
}
