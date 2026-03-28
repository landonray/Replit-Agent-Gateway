<?php

declare(strict_types=1);

namespace AgentGateway\Service;

use Predis\Client as RedisClient;

class ConversationService
{
    private RedisClient $redis;
    private int $ttl;
    private int $maxMessages;

    public function __construct(string $redisUrl, int $ttl = 86400, int $maxMessages = 20)
    {
        $this->redis       = new RedisClient($redisUrl);
        $this->ttl         = $ttl;
        $this->maxMessages = $maxMessages;
    }

    /**
     * @return list<array{role: string, content: mixed}>
     */
    public function getHistory(string $conversationId): array
    {
        $data = $this->redis->get("conversation:{$conversationId}");
        if ($data === null) {
            return [];
        }

        $decoded = json_decode($data, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<array{role: string, content: mixed}> $fullMessages
     * @param array<string, mixed> $assistantContent
     */
    public function saveHistory(
        string $conversationId,
        array $fullMessages,
        array $assistantContent
    ): void {
        $fullMessages[] = ['role' => 'assistant', 'content' => $assistantContent];

        if (count($fullMessages) > $this->maxMessages) {
            $fullMessages = array_slice($fullMessages, -$this->maxMessages);
        }

        $key = "conversation:{$conversationId}";
        $this->redis->set($key, json_encode($fullMessages));
        $this->redis->expire($key, $this->ttl);
    }

    public static function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
