<?php

namespace App\Messaging;

use RdKafka\Conf;
use RdKafka\Message;
use RdKafka\Producer as KafkaProducer;

/**
 * Kafka producer built on the rdkafka extension.
 *
 * A message counts as delivered only when librdkafka reports a successful
 * delivery (acks=all), never merely because produce() returned. The relay
 * marks an outbox row as sent only for those IDs.
 */
class RdKafkaProducer implements Producer
{
    private ?KafkaProducer $producer = null;

    private ?string $connectedTo = null;

    /** @var array<string, string|null> event id => error (null when delivered) */
    private array $reports = [];

    public function __construct(private readonly BrokerSwitch $switch) {}

    public function publish(array $messages): array
    {
        $producer = $this->producer();
        $this->reports = [];

        foreach ($messages as $message) {
            $topic = $producer->newTopic($message['topic']);
            // The event ID is passed as the opaque value so the delivery report can be matched to the outbox row.
            $topic->producev(RD_KAFKA_PARTITION_UA, 0, $message['body'], $message['key'], ['event-id' => $message['id']], null, $message['id']);
            $producer->poll(0);
        }

        $timeoutMs = (int) config('messaging.delivery_timeout_ms') + 2000;
        $producer->flush($timeoutMs);

        $delivered = [];
        $failed = [];
        foreach ($messages as $message) {
            $id = $message['id'];
            if (array_key_exists($id, $this->reports) && $this->reports[$id] === null) {
                $delivered[] = $id;
            } else {
                $failed[$id] = $this->reports[$id] ?? 'no delivery report within timeout';
            }
        }

        if ($failed !== []) {
            // Start from a clean client next time so nothing queued in librdkafka is sent twice behind our back.
            $this->producer = null;
        }

        return ['delivered' => $delivered, 'failed' => $failed];
    }

    private function producer(): KafkaProducer
    {
        $brokers = $this->switch->brokers();

        if ($this->producer !== null && $this->connectedTo === $brokers) {
            return $this->producer;
        }

        $conf = new Conf;
        $conf->set('bootstrap.servers', $brokers);
        $conf->set('client.id', config('messaging.app').'-relay');
        $conf->set('acks', 'all');
        $conf->set('enable.idempotence', 'true');
        $conf->set('message.timeout.ms', (string) config('messaging.delivery_timeout_ms'));
        $conf->set('socket.connection.setup.timeout.ms', '3000');
        $conf->set('log_level', (string) LOG_WARNING);
        $conf->setDrMsgCb(function (KafkaProducer $kafka, Message $message): void {
            $id = $message->opaque;
            if (is_string($id)) {
                $this->reports[$id] = $message->err === RD_KAFKA_RESP_ERR_NO_ERROR ? null : rd_kafka_err2str($message->err);
            }
        });

        $this->connectedTo = $brokers;

        return $this->producer = new KafkaProducer($conf);
    }
}
