<?php

namespace App\Messaging;

use Illuminate\Support\ServiceProvider;

class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BrokerSwitch::class);
        $this->app->singleton(Producer::class, RdKafkaProducer::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\RelayCommand::class,
                Console\ConsumeCommand::class,
                Console\EnsureTopicsCommand::class,
            ]);
        }
    }
}
