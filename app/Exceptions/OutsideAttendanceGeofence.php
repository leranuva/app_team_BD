<?php

namespace App\Exceptions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OutsideAttendanceGeofence extends ValidationException
{
    public function __construct(public readonly int $siteId)
    {
        $validator = Validator::make([], []);
        $validator->errors()->add('location', 'El fichaje está fuera del radio permitido de la obra.');

        parent::__construct($validator);
    }
}
