<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Catálogo CWICR
    |--------------------------------------------------------------------------
    |
    | Las categorías CWICR se guardan tal cual vienen en su CSV. Drywall es
    | una categoría propia de esta aplicación: el Select de Filament debe
    | leer esta misma lista. La unidad cabe en 20 caracteres: kg, m3, m2,
    | m, pcs, set, Sheet, 100m, 100m2, 1000pcs.
    |
    */

    'unit_max_length' => 20,

    'categories' => [
        'Steel & Metal',
        'Concrete & Cement',
        'Electrical',
        'Paint & Coatings',
        'Wood & Timber',
        'Fasteners',
        'Pipes & Fittings',
        'Insulation',
        'Waterproofing',
        'Aggregates & Earth',
        'Glass & Glazing',
        'General',
        'Drywall',
    ],

    'units' => [
        'kg',
        'm3',
        'm2',
        'm',
        'pcs',
        'set',
        'Sheet',
        '100m',
        '100m2',
        '1000pcs',
    ],

];
