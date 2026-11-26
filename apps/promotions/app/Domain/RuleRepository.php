<?php

namespace App\Domain;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RuleRepository
{
    public function active(): array
    {
        return Cache::remember('promotion-rules', 60, fn () => DB::table('promotion_rules')
            ->where('active', true)->orderBy('id')->get()
            ->map(fn ($r) => ['code' => $r->code, 'label' => $r->label, 'type' => $r->type, 'params' => json_decode($r->params, true)])
            ->all());
    }

    public function engine(): PromotionEngine
    {
        return new PromotionEngine($this->active());
    }
}
