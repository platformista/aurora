<?php

namespace App\Messaging;

use RuntimeException;

/**
 * Creates topics with Kafka's own CreateTopics request (API key 19, version 2).
 *
 * php-rdkafka has no admin API, the Kafka CLI is not in the app container, and
 * the broker does not auto-create topics, so the deploy hook sends the request
 * itself. Version 2 is plain (non-"flexible") encoding and is still accepted by
 * Kafka 4.x. The single-broker service is also the controller.
 */
class KafkaAdmin
{
    private const API_CREATE_TOPICS = 19;

    private const VERSION = 2;

    /** Error codes that mean "fine". */
    private const OK = [0 => 'created', 36 => 'already exists'];

    public function __construct(private readonly string $bootstrap, private readonly float $timeout = 10.0) {}

    /**
     * @param  list<string>  $topics
     * @return array<string, string> topic => outcome
     */
    public function createTopics(array $topics, int $partitions = 3, int $replicationFactor = 1): array
    {
        $body = $this->int32(count($topics));
        foreach ($topics as $topic) {
            $body .= $this->string($topic)
                .$this->int32($partitions)
                .$this->int16($replicationFactor)
                .$this->int32(0)    // no manual assignments
                .$this->int32(0);   // no per-topic configs
        }
        $body .= $this->int32((int) ($this->timeout * 1000)).chr(0); // timeout_ms, validate_only = false

        $response = $this->send(self::API_CREATE_TOPICS, self::VERSION, $body);

        $offset = 4; // throttle_time_ms
        $count = $this->readInt32($response, $offset);
        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $name = $this->readString($response, $offset);
            $code = $this->readInt16($response, $offset);
            $message = $this->readString($response, $offset);
            $result[$name] = self::OK[$code] ?? "error {$code}".($message ? ": {$message}" : '');
        }

        return $result;
    }

    private function send(int $apiKey, int $version, string $body): string
    {
        [$host, $port] = explode(':', explode(',', $this->bootstrap)[0]) + [1 => '9092'];
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, $this->timeout);
        if (! $socket) {
            throw new RuntimeException("Cannot connect to {$host}:{$port}: {$error}");
        }
        stream_set_timeout($socket, (int) ceil($this->timeout) + 5);

        $correlationId = random_int(1, 1 << 30);
        $header = $this->int16($apiKey).$this->int16($version).$this->int32($correlationId).$this->string('aurora-admin');
        fwrite($socket, $this->int32(strlen($header.$body)).$header.$body);

        $size = unpack('N', $this->read($socket, 4))[1];
        $payload = $this->read($socket, $size);
        fclose($socket);

        if (unpack('N', substr($payload, 0, 4))[1] !== $correlationId) {
            throw new RuntimeException('Unexpected Kafka response.');
        }

        return substr($payload, 4);
    }

    private function read($socket, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Kafka closed the connection.');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function int16(int $v): string
    {
        return pack('n', $v & 0xFFFF);
    }

    private function int32(int $v): string
    {
        return pack('N', $v & 0xFFFFFFFF);
    }

    private function string(string $v): string
    {
        return $this->int16(strlen($v)).$v;
    }

    private function readInt16(string $b, int &$o): int
    {
        $v = unpack('n', substr($b, $o, 2))[1];
        $o += 2;

        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function readInt32(string $b, int &$o): int
    {
        $v = unpack('N', substr($b, $o, 4))[1];
        $o += 4;

        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }

    private function readString(string $b, int &$o): ?string
    {
        $len = $this->readInt16($b, $o);
        if ($len < 0) {
            return null;
        }
        $v = substr($b, $o, $len);
        $o += $len;

        return $v;
    }
}
