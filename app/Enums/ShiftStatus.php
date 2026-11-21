<?php

namespace App\Enums;

enum ShiftStatus: string
{
    case Programmed = 'programmed';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Programmed => 'Programado',
            self::Confirmed => 'Confirmado',
            self::Completed => 'Completado',
            self::Cancelled => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Programmed => 'gray',
            self::Confirmed => 'info',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }
}
