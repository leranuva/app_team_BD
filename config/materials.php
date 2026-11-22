<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Catálogo CWICR
    |--------------------------------------------------------------------------
    |
    | Las categorías se guardan tal cual vienen en el CSV. No hay mapa a
    | nombres de oficio (Framing, Plumbing, Drywall). El Select de Filament
    | debe leer esta misma lista. La unidad cabe en 20 caracteres: kg, m3,
    | m2, m, pcs, set, 100m, 100m2, 1000pcs.
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
    ],

    'units' => [
        'kg',
        'm3',
        'm2',
        'm',
        'pcs',
        'set',
        '100m',
        '100m2',
        '1000pcs',
    ],

];
