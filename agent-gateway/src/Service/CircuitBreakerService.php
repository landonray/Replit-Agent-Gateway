<?php

declare(strict_types=1);

namespace AgentGateway\Service;

use AgentGateway\Logger;
use AgentGateway\RedisFactory;
use Predis\Client as RedisClient;

class CircuitBreakerService
{
    private RedisClient $redis;

    // Default thresholds
    private int $totalWriteLimit;
    private int $totalWriteWindowSeconds;
    private int $sameToolLimit;
    private int $sameToolWindowSeconds;
    private int $consecutiveFailureLimit;
    private int $communicationLimit;
    private int $communicationWindowSeconds;
    private int $financialLimit;
    private int $financialWindowSeconds;

    public function __construct(
        string $redisUrl,
        int $totalWriteLimit = 50,
        int $totalWriteWindowSeconds = 300,
        int $sameToolLimit = 20,
        int $sameToolWindowSeconds = 300,
        int $consecutiveFailureLimit = 5,
        int $communicationLimit = 10,
        int $communicationWindowSeconds = 3600,
        int $financialLimit = 5,
        int $financialWindowSeconds = 3600
    ) {
        $this->redis = RedisFactory::create($redisUrl);
        $this->totalWriteLimit = $totalWriteLimit;
        $this->totalWriteWindowSeconds = $totalWriteWindowSeconds;
        $this->sameToolLimit = $sameToolLimit;
        $this->sameToolWindowSeconds = $sameToolWindowSeconds;
        $this->consecutiveFailureLimit = $consecutiveFailureLimit;
        $this->communicationLimit = $communicationLimit;
        $this->communicationWindowSeconds = $communicationWindowSeconds;
        $this->financialLimit = $financialLimit;
        $this->financialWindowSeconds = $financialWindowSeconds;
    }

    /**
     * Check all relevant circuit breakers before executing a tool call.
     * Returns null if all clear, or an error array if tripped.
     *
     * @return array{error: string, metric: string, threshold: int, window: string, resets_at: string, message: string}|null
     */
    public function check(
        string $accountId,
        string $conversationId,
        string $toolName,
        bool $isWriteTool,
        bool $isCommunicationTool,
        bool $isFinancialTool
    ): ?array {
        // 1. Total write tool calls
        if ($isWriteTool) {
            $result = $this->checkThreshold(
                $accountId,
                'total_writes',
                $this->totalWriteLimit,
                $this->totalWriteWindowSeconds,
                '5 minutes',
                'Write tool limit reached. All write operations are temporarily blocked.'
            );
            if ($result !== null) return $result;
        }

        // 2. Same tool called repeatedly
        if ($isWriteTool) {
            $result = $this->checkThreshold(
                $accountId,
                "tool:{$toolName}",
                $this->sameToolLimit,
                $this->sameToolWindowSeconds,
                '5 minutes',
                "Tool '{$toolName}' call limit reached. This specific tool is temporarily blocked."
            );
            if ($result !== null) return $result;
        }

        // 3. Consecutive failures (per conversation)
        $failKey = "cb:{$accountId}:conv_failures:{$conversationId}";
        $failCount = (int) ($this->redis->get($failKey) ?? 0);
        if ($failCount >= $this->consecutiveFailureLimit) {
            return [
                'error' => 'circuit_breaker_tripped',
                'metric' => 'consecutive_failures',
                'threshold' => $this->consecutiveFailureLimit,
                'window' => 'per conversation',
                'resets_at' => 'Start a new conversation',
                'message' => "Too many consecutive tool failures in this conversation. Please start a new conversation.",
            ];
        }

        // 4. Communication actions
        if ($isCommunicationTool) {
            $result = $this->checkThreshold(
                $accountId,
                'communication',
                $this->communicationLimit,
                $this->communicationWindowSeconds,
                '1 hour',
                'Communication tool limit reached. Email, SMS, and invoice tools are temporarily blocked.'
            );
            if ($result !== null) return $result;
        }

        // 5. Financial actions
        if ($isFinancialTool) {
            $result = $this->checkThreshold(
                $accountId,
                'financial',
                $this->financialLimit,
                $this->financialWindowSeconds,
                '1 hour',
                'Financial tool limit reached. Charge, refund, and payment tools are temporarily blocked.'
            );
            if ($result !== null) return $result;
        }

        return null;
    }

    /**
     * Increment counters after a tool call executes.
     */
    public function recordExecution(
        string $accountId,
        string $conversationId,
        string $toolName,
        bool $isWriteTool,
        bool $isCommunicationTool,
        bool $isFinancialTool,
        bool $success
    ): void {
        if ($isWriteTool) {
            $this->increment($accountId, 'total_writes', $this->totalWriteWindowSeconds);
            $this->increment($accountId, "tool:{$toolName}", $this->sameToolWindowSeconds);
        }

        if ($isCommunicationTool) {
            $this->increment($accountId, 'communication', $this->communicationWindowSeconds);
        }

        if ($isFinancialTool) {
            $this->increment($accountId, 'financial', $this->financialWindowSeconds);
        }

        // Track consecutive failures per conversation
        $failKey = "cb:{$accountId}:conv_failures:{$conversationId}";
        if ($success) {
            $this->redis->set($failKey, '0');
            $this->redis->expire($failKey, 86400); // Same as conversation TTL
        } else {
            $this->redis->incr($failKey);
            $this->redis->expire($failKey, 86400);
        }
    }

    /**
     * Reset a specific circuit breaker for a specific account.
     */
    public function reset(string $accountId, string $metric): bool
    {
        $key = "cb:{$accountId}:{$metric}";
        $deleted = $this->redis->del([$key]);
        Logger::get()->info('Circuit breaker manually reset', [
            'account_id' => $accountId,
            'metric' => $metric,
        ]);
        return $deleted > 0;
    }

    /**
     * Get the current status of all circuit breakers for an account.
     */
    public function getStatus(string $accountId): array
    {
        $metrics = ['total_writes', 'communication', 'financial'];
        $status = [];

        foreach ($metrics as $metric) {
            $key = "cb:{$accountId}:{$metric}";
            $count = (int) ($this->redis->get($key) ?? 0);
            $ttl = $this->redis->ttl($key);
            $status[$metric] = [
                'count' => $count,
                'ttl_seconds' => $ttl > 0 ? $ttl : 0,
            ];
        }

        return $status;
    }

    private function checkThreshold(
        string $accountId,
        string $metric,
        int $defaultLimit,
        int $windowSeconds,
        string $windowLabel,
        string $message
    ): ?array {
        // Check for per-account override
        $overrideKey = "cb_override:{$accountId}:{$metric}";
        $override = $this->redis->get($overrideKey);
        $limit = $override !== null ? (int) $override : $defaultLimit;

        $key = "cb:{$accountId}:{$metric}";
        $count = (int) ($this->redis->get($key) ?? 0);

        if ($count >= $limit) {
            $ttl = $this->redis->ttl($key);
            $resetsAt = date('c', time() + max($ttl, 0));

            Logger::get()->warning('Circuit breaker tripped', [
                'account_id' => $accountId,
                'metric' => $metric,
                'count' => $count,
                'threshold' => $limit,
            ]);

            return [
                'error' => 'circuit_breaker_tripped',
                'metric' => $metric,
                'threshold' => $limit,
                'window' => $windowLabel,
                'resets_at' => $resetsAt,
                'message' => $message . " This limit resets in " . $this->formatTtl($ttl) . ".",
            ];
        }

        return null;
    }

    private function increment(string $accountId, string $metric, int $windowSeconds): void
    {
        $key = "cb:{$accountId}:{$metric}";

        // Use pipeline for atomicity: INCR + conditional EXPIRE
        $pipe = $this->redis->pipeline();
        $pipe->incr($key);
        $pipe->ttl($key);
        $results = $pipe->execute();

        $ttl = $results[1] ?? -1;
        // TTL of -1 means no expiry set (new key or lost expiry); set it
        if ($ttl < 0) {
            $this->redis->expire($key, $windowSeconds);
        }
    }

    private function formatTtl(int $ttl): string
    {
        if ($ttl <= 0) return 'shortly';
        if ($ttl < 60) return "{$ttl} seconds";
        $minutes = (int) ceil($ttl / 60);
        if ($minutes < 60) return "{$minutes} minutes";
        $hours = (int) ceil($minutes / 60);
        return "{$hours} hours";
    }
}
