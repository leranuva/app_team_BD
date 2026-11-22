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
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Approved => 'Approved',
            self::Ordered => 'Ordered',
            self::Delivered => 'Delivered',
            self::PartiallyConsumed => 'Partially consumed',
            self::Consumed => 'Consumed',
            self::Cancelled => 'Canceled',
            self::Pending => 'Pending',
        };
    }
}
