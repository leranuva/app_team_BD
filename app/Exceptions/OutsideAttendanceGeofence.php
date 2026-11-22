<?php

namespace App\Exceptions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OutsideAttendanceGeofence extends ValidationException
{
    public function __construct(public readonly int $siteId)
    {
        $validator = Validator::make([], []);
        $validator->errors()->add('location', 'The time entry is outside the allowed job site radius.');

        parent::__construct($validator);
    }
}
