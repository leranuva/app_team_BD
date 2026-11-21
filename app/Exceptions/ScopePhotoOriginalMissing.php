<?php

namespace App\Exceptions;

use RuntimeException;

class ScopePhotoOriginalMissing extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('El archivo original ya no está disponible');
    }
}
