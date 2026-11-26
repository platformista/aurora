<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incaricata extends Model
{
    protected $table = 'incaricate';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['joined_at' => 'date'];
    }

    public function upline(): BelongsTo
    {
        return $this->belongsTo(self::class, 'upline_id');
    }

    public function downline(): HasMany
    {
        return $this->hasMany(self::class, 'upline_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'seller_id');
    }

    public function fullName(): string
    {
        return $this->first_name.' '.$this->last_name;
    }

    public function summary(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->fullName(),
            'city' => $this->city,
            'province' => $this->province,
        ];
    }
}
