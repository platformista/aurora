<?php

namespace App\Messaging;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of `outbox_events`. `published_at` is null until the relay has a
 * delivery acknowledgement from the broker.
 */
class OutboxEvent extends Model
{
    protected $table = 'outbox_events';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'published_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('published_at');
    }
}
