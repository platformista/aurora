<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'promotions' => 'array',
            'gifts' => 'array',
            'placed_at' => 'datetime',
            'shipped_at' => 'datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Incaricata::class, 'seller_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }
}
