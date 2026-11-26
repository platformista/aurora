<?php

namespace App\Messaging\Console;

use App\Messaging\Envelope;
use App\Messaging\IdempotentConsumer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use Throwable;

/**
 * Long-running Kafka consumer. Runs as its own Upsun worker.
 *
 * The offset is committed only after the handler's transaction has committed,
 * so a crash in between means redelivery, which IdempotentConsumer absorbs.
 */
class ConsumeCommand extends Command
{
    protected $signature = 'kafka:consume {consumer : Consumer name from config/messaging.php}';

    protected $description = 'Consume events from Kafka and handle them idempotently';

    private bool $stopping = false;

    public function handle(IdempotentConsumer $idempotent): int
    {
        $name = $this->argument('consumer');
        $handlers = config("messaging.consumers.{$name}.handlers", []);
        $topics = config("messaging.consumers.{$name}.topics", []);

        if ($topics === []) {
            $this->components->error("No topics configured for consumer '{$name}'.");

            return self::FAILURE;
        }

        $this->trapSignals();

        $conf = new Conf;
        $conf->set('bootstrap.servers', (string) config('messaging.brokers'));
        $conf->set('group.id', config('messaging.app').'-'.$name);
        $conf->set('client.id', config('messaging.app').'-'.$name);
        $conf->set('enable.auto.commit', 'false');
        $conf->set('auto.offset.reset', 'earliest');
        $conf->set('log_level', (string) LOG_WARNING);

        $consumer = new KafkaConsumer($conf);
        $consumer->subscribe($topics);
        $this->components->info('Consuming '.implode(', ', $topics).' as group '.config('messaging.app').'-'.$name);

        while (! $this->stopping) {
            $message = $consumer->consume(1000);

            if ($message->err === RD_KAFKA_RESP_ERR__PARTITION_EOF || $message->err === RD_KAFKA_RESP_ERR__TIMED_OUT) {
                continue;
            }
            if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                $this->components->warn('Kafka: '.$message->errstr());
                sleep(1);

                continue;
            }

            try {
                $event = Envelope::fromJson((string) $message->payload);
                $handlerClass = $handlers[$event->type] ?? null;

                if ($handlerClass === null) {
                    // Not for us: acknowledge and move on.
                    $consumer->commit($message);

                    continue;
                }

                $handled = $idempotent->handle($name, $event, fn (Envelope $e) => app($handlerClass)($e));
                $consumer->commit($message);
                $this->line(now()->toTimeString().' '.($handled ? 'handled' : 'duplicate skipped')." {$event->type} {$event->id}");
            } catch (Throwable $e) {
                // Do not commit: the event will be redelivered after a restart.
                Log::error('Event handling failed', ['error' => $e->getMessage(), 'offset' => $message->offset]);
                $this->components->error($e->getMessage());
                $consumer->close();

                return self::FAILURE; // Upsun restarts the worker; we resume from the last committed offset.
            }
        }

        $consumer->close();

        return self::SUCCESS;
    }

    private function trapSignals(): void
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stopping = true);
            pcntl_signal(SIGINT, fn () => $this->stopping = true);
        }
    }
}
