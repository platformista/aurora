<?php

return [
    'app' => 'promotions',
    'brokers' => env('KAFKA_BROKERS', '127.0.0.1:19092'),
    'delivery_timeout_ms' => (int) env('KAFKA_DELIVERY_TIMEOUT_MS', 5000),
    'topics' => ['aurora.orders', 'aurora.shipments', 'aurora.promotions'],

    'consumers' => [
        'orders' => [
            'topics' => ['aurora.orders'],
            'handlers' => [
                'order.placed' => App\EventHandlers\OrderPlaced::class,
            ],
        ],
    ],
];
