<?php

$backend = env('CHECKOUT_WRITER_BACKEND', 'apps_script');

return [
    // Invalid configuration deliberately remains on the existing writer; a
    // malformed environment value can never enable direct Sheets writes.
    'writer_backend' => in_array($backend, ['apps_script', 'direct'], true) ? $backend : 'apps_script',
];
