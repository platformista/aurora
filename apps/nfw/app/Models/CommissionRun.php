<?php

namespace App\Models;

use App\Domain\SalesCycle;
use Illuminate\Database\Eloquent\Model;

class CommissionRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function summary(): array
    {
        return [
            'id' => $this->id,
            'cycle' => SalesCycle::number($this->cycle)->label(),
            'status' => $this->status,
            'triggered_by' => $this->triggered_by,
            'chunks' => $this->chunks,
            'orders_count' => $this->orders_count,
            'beneficiaries_count' => $this->beneficiaries_count,
            'total_cents' => (int) $this->total_cents,
            'queued_at' => $this->queued_at?->toIso8601String(),
            'duration_ms' => $this->duration_ms,
            'note' => $this->note,
        ];
    }
}
