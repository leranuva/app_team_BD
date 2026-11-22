<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Support\SheetHeadingImport;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('writes a fixture workbook and a pdf', function (): void {
    $this->artisan('reports:sample')->assertSuccessful();

    $pdf = Storage::disk('local')->get('reports-sample/sample-hours.pdf');
    expect($pdf)->toStartWith('%PDF')->and(strlen($pdf))->toBeGreaterThan(100);

    $sheet = Excel::toArray(new SheetHeadingImport, 'reports-sample/sample-hours.xlsx', 'local')[0];
    expect($sheet[0]['trabajador'])->toBe('Ana Torres')
        ->and($sheet[0]['obra'])->toBe('Queens');

    Storage::disk('local')->delete(['reports-sample/sample-hours.pdf', 'reports-sample/sample-hours.xlsx']);
});
