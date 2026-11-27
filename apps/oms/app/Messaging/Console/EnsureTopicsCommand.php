<?php

namespace App\Messaging\Console;

use App\Messaging\KafkaAdmin;
use Illuminate\Console\Command;
use Throwable;

/**
 * Makes sure the topics exist. Runs in the deploy hook.
 *
 * It never fails the deploy: if the broker is unreachable, the outbox holds
 * events until it comes back, which is the point of the design. The next
 * deploy (or `php artisan aurora:kafka-topics`) creates the topics.
 */
class EnsureTopicsCommand extends Command
{
    protected $signature = 'aurora:kafka-topics {--partitions=3}';

    protected $description = 'Create the Kafka topics if they do not exist yet';

    public function handle(): int
    {
        $admin = new KafkaAdmin((string) config('messaging.brokers'));

        try {
            foreach ($admin->createTopics(config('messaging.topics', []), (int) $this->option('partitions')) as $topic => $outcome) {
                $this->line("{$topic}: {$outcome}");
            }
        } catch (Throwable $e) {
            $this->components->warn('Could not create topics now ('.$e->getMessage().'). Producers will retry; nothing is lost.');
        }

        return self::SUCCESS;
    }
}
