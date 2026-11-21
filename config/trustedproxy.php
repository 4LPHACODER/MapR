<?php

return [
    // Empty by default. Never trust every proxy or client-supplied headers.
    'proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
];
