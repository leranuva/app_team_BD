<?php

namespace App\Exceptions;

use RuntimeException;

class ScopePhotoOriginalMissing extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The original file is no longer available');
    }
}
