<?php

namespace Tests\Messaging;

use App\Messaging\Producer;
use RuntimeException;

/** In-memory producer for tests: records messages, can fail on demand. */
class FakeProducer implements Producer
{
    public array $sent = [];

    /** 'none', 'all', 'throw', or a list of event IDs to fail. */
    public string|array $fail = 'none';

    public function publish(array $messages): array
    {
        if ($this->fail === 'throw') {
            throw new RuntimeException('Connection refused');
        }

        $delivered = [];
        $failed = [];
        foreach ($messages as $m) {
            if ($this->fail === 'all' || (is_array($this->fail) && in_array($m['id'], $this->fail, true))) {
                $failed[$m['id']] = 'Local: Message timed out';
            } else {
                $this->sent[] = $m;
                $delivered[] = $m['id'];
            }
        }

        return ['delivered' => $delivered, 'failed' => $failed];
    }
}
