<?php

namespace App\Messaging\Console;

use Illuminate\Console\Command;
use RdKafka\Conf;
use RdKafka\Producer;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Makes sure the topics exist. Runs in the deploy hook.
 *
 * It never fails the deploy: if the broker is unreachable, the outbox holds
 * events until it comes back, which is the point of the design.
 */
class EnsureTopicsCommand extends Command
{
    protected $signature = 'aurora:kafka-topics';

    protected $description = 'Create the Kafka topics if they do not exist yet';

    public function handle(): int
    {
        $brokers = (string) config('messaging.brokers');
        $topics = config('messaging.topics', []);

        // 1. Kafka's own tool, if the container provides it.
        $finder = new Process(['sh', '-c', 'command -v kafka-topics.sh']);
        $finder->run();
        if ($finder->isSuccessful()) {
            foreach ($topics as $topic) {
                $p = new Process([trim($finder->getOutput()), '--bootstrap-server', $brokers, '--create', '--if-not-exists', '--topic', $topic, '--partitions', '3']);
                $p->setTimeout(60)->run();
                $this->line(trim($p->getOutput().$p->getErrorOutput()) ?: "{$topic}: ok");
            }

            return self::SUCCESS;
        }

        // 2. Otherwise a metadata request, which creates the topic when the broker allows auto-creation.
        try {
            $conf = new Conf;
            $conf->set('bootstrap.servers', $brokers);
            $conf->set('allow.auto.create.topics', 'true');
            $producer = new Producer($conf);
            foreach ($topics as $topic) {
                $metadata = $producer->getMetadata(false, $producer->newTopic($topic), 10_000);
                foreach ($metadata->getTopics() as $t) {
                    $this->line("{$t->getTopic()}: ".($t->getErr() === RD_KAFKA_RESP_ERR_NO_ERROR ? 'ok' : rd_kafka_err2str($t->getErr())));
                }
            }
        } catch (Throwable $e) {
            $this->components->warn('Could not check topics now ('.$e->getMessage().'). Producers will retry; nothing is lost.');
        }

        return self::SUCCESS;
    }
}
