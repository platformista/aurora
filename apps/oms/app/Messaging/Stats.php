<?php

namespace App\Messaging;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Figures for the demo dashboard, read from this app's own database.
 */
class Stats
{
    public function snapshot(): array
    {
        $oldestPending = OutboxEvent::query()->pending()->min('created_at');
        $lastError = OutboxEvent::query()->pending()->whereNotNull('last_error')->orderByDesc('last_attempt_at')->value('last_error');

        return [
            'app' => config('messaging.app'),
            'outbox_pending' => OutboxEvent::query()->pending()->count(),
            'outbox_published' => OutboxEvent::query()->whereNotNull('published_at')->count(),
            'oldest_pending_seconds' => $oldestPending ? (int) now()->diffInSeconds($oldestPending, true) : null,
            'last_error' => $lastError,
            'events_processed' => DB::table('processed_events')->count(),
            'duplicates_skipped' => (int) Cache::store('shared')->get('stats:'.config('messaging.app').':duplicates_skipped', 0),
        ];
    }
}
