<?php

declare(strict_types=1);

namespace AgentGateway\Service;

use AgentGateway\Logger;
use AgentGateway\RedisFactory;
use Predis\Client as RedisClient;

class DeduplicationService
{
    private RedisClient $redis;
    private int $ttl;

    private const REPEAT_KEYWORDS = [
        'send that again',
        'do it one more time',
        'retry',
        'try again',
        'repeat',
        'resend',
        'redo',
        'one more time',
        'send it again',
        'do it again',
    ];

    public function __construct(string $redisUrl, int $ttl = 86400)
    {
        $this->redis = RedisFactory::create($redisUrl);
        $this->ttl = $ttl;
    }

    /**
     * Compute a deterministic SHA-256 hash for a tool call.
     */
    public function computeHash(string $toolName, array $params): string
    {
        $params = $this->recursiveKsort($params);
        $normalized = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($normalized === false) {
            $normalized = serialize($params);
        }
        return hash('sha256', $toolName . '|' . $normalized);
    }

    /**
     * Recursively sort array keys for deterministic hashing.
     */
    private function recursiveKsort(array $arr): array
    {
        ksort($arr);
        foreach ($arr as $key => $value) {
            if (is_array($value) && !array_is_list($value)) {
                $arr[$key] = $this->recursiveKsort($value);
            }
        }
        return $arr;
    }

    /**
     * Check if this tool call has already succeeded in this conversation.
     * Returns the cached result if duplicate, or null if not a duplicate.
     */
    public function check(string $conversationId, string $toolName, array $params): ?string
    {
        $hash = $this->computeHash($toolName, $params);
        $key = "dedup:{$conversationId}";

        $cached = $this->redis->hget($key, $hash);

        if ($cached !== null) {
            Logger::get()->info('Deduplication hit', [
                'conversation_id' => $conversationId,
                'tool' => $toolName,
                'hash' => $hash,
            ]);

            return $cached . "\n\n[This action was already completed earlier in this conversation. Returning the previous result.]";
        }

        return null;
    }

    /**
     * Record a successful write tool call result in the dedup log.
     */
    public function record(string $conversationId, string $toolName, array $params, string $result): void
    {
        $hash = $this->computeHash($toolName, $params);
        $key = "dedup:{$conversationId}";

        // Pipeline HSET+EXPIRE for atomicity
        $pipe = $this->redis->pipeline();
        $pipe->hset($key, $hash, $result);
        $pipe->expire($key, $this->ttl);
        $pipe->execute();

        Logger::get()->debug('Dedup recorded', [
            'conversation_id' => $conversationId,
            'tool' => $toolName,
            'hash' => $hash,
        ]);
    }

    /**
     * Check if the user's current message contains explicit repeat language.
     */
    public function userRequestedRepeat(string $userMessage): bool
    {
        $lower = mb_strtolower($userMessage);

        foreach (self::REPEAT_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
