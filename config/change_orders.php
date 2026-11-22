<?php

return [
    'code_prefix' => env('CHANGE_ORDER_PREFIX', 'CO'),
    // El umbral se compara con el valor absoluto del monto.
    'supervisor_approval_threshold_cents' => (int) env('CHANGE_ORDER_SUPERVISOR_THRESHOLD_CENTS', 500000),
];
