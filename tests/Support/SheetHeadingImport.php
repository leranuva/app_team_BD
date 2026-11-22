<?php

namespace Tests\Support;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class SheetHeadingImport implements ToArray, WithHeadingRow
{
    public function array(array $array): void {}
}
