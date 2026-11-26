<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->applyWorkerEnvironment();
    }

    /**
     * Per-worker settings are read from the process environment at runtime.
     * The deploy hook caches config in the web container, where these
     * variables are not set; reading them here keeps each Upsun worker's own
     * values even when a config cache exists.
     */
    private function applyWorkerEnvironment(): void
    {
        if (($complexity = getenv('COMMISSION_COMPLEXITY')) !== false) {
            config(['commissions.complexity' => (int) $complexity]);
        }

        $processes = getenv('HORIZON_COMMISSION_PROCESSES');
        if ($processes !== false) {
            config(['horizon.commission_processes' => (int) $processes]);
        }

        $supervisors = config('horizon.environments.production');
        $supervisors['commissions']['maxProcesses'] = (int) config('horizon.commission_processes');

        $wanted = getenv('HORIZON_SUPERVISORS');
        if ($wanted !== false && $wanted !== '') {
            $supervisors = array_intersect_key($supervisors, array_flip(array_map('trim', explode(',', $wanted))));
        }

        // Same supervisors whatever APP_ENV is (production, staging, local).
        config(['horizon.environments' => [config('app.env') => $supervisors, 'production' => $supervisors]]);
    }
}
