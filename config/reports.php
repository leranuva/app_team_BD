<?php

return [
    // consumed_quantity < delivered_quantity * threshold, and delivered_quantity > 0.
    'low_consumption_threshold' => 0.5,

    // At or below this row count the file is built in the request. Above it, queue "reports".
    'sync_threshold' => 1000,

    // report_exports rows and their private objects live this many days.
    'retention_days' => 7,

    'url_minutes' => 15,
];
