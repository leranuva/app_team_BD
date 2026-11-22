<?php

namespace App\Enums;

enum MaterialListStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Ordered = 'ordered';
    case Delivered = 'delivered';
    case PartiallyConsumed = 'partially_consumed';
    case Consumed = 'consumed';
    case Cancelled = 'cancelled';
    case Pending = 'pending';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Submitted => 'Enviada',
            self::Approved => 'Aprobada',
            self::Ordered => 'Ordenada',
            self::Delivered => 'Entregada',
            self::PartiallyConsumed => 'Consumo parcial',
            self::Consumed => 'Consumida',
            self::Cancelled => 'Cancelada',
            self::Pending => 'Pendiente',
        };
    }
}
