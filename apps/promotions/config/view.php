<?php

return [
    // Only existing directories: the Promotion Engine has no views at all.
    'paths' => array_values(array_filter([resource_path('views')], 'is_dir')),

    // Not wrapped in realpath(): on Upsun `storage` is a mount that starts empty
    // in every container, and Blade creates this directory on first compile.
    'compiled' => env('VIEW_COMPILED_PATH', storage_path('framework/views')),
];
