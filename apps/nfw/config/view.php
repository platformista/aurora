<?php

return [
    'paths' => [resource_path('views')],

    // Not wrapped in realpath(): on Upsun `storage` is a mount that starts empty
    // in every container, and Blade creates this directory on first compile.
    'compiled' => env('VIEW_COMPILED_PATH', storage_path('framework/views')),
];
