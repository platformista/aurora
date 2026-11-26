<?php

return [
    // Name of this app on the bus; used as the event source and consumer group prefix.
    'app' => 'nfw',

    // "host:port[,host:port]". On Upsun, built from KAFKA_HOST/KAFKA_PORT in .environment.
    'brokers' => env('KAFKA_BROKERS', '127.0.0.1:19092'),

    // How long librdkafka keeps trying to deliver one batch before reporting failure.
    'delivery_timeout_ms' => (int) env('KAFKA_DELIVERY_TIMEOUT_MS', 5000),

    'topics' => ['aurora.orders', 'aurora.shipments', 'aurora.promotions'],

    'consumers' => [
        'shipments' => [
            'topics' => ['aurora.shipments'],
            'handlers' => [
                'order.shipped' => App\EventHandlers\OrderShipped::class,
            ],
        ],
    ],
];
