<?php

declare(strict_types=1);

namespace AgentGateway\Middleware;

use AgentGateway\Logger;
use AgentGateway\RedisFactory;
use Predis\Client as RedisClient;

class RateLimitMiddleware
{
    private static ?RedisClient $redis = null;

    private static function getRedis(): RedisClient
    {
        if (self::$redis === null) {
            self::$redis = RedisFactory::create($_ENV['REDIS_URL'] ?? 'redis://localhost:6379');
        }
        return self::$redis;
    }

    public static function handle(string $appId, int $perMinute, int $perDay): bool
    {
        $redis = self::getRedis();
        $now   = time();

        $minuteKey = "ratelimit:{$appId}:minute:" . intdiv($now, 60);
        $minuteCount = (int) $redis->incr($minuteKey);
        if ($minuteCount === 1) {
            $redis->expire($minuteKey, 120);
        }

        if ($minuteCount > $perMinute) {
            Logger::get()->warning('Rate limit exceeded (per-minute)', ['app_id' => $appId]);
            http_response_code(429);
            echo json_encode([
                'error'  => "Rate limit exceeded. Maximum {$perMinute} requests per minute.",
                'status' => 429,
            ]);
            return false;
        }

        $dayKey = "ratelimit:{$appId}:day:" . date('Y-m-d', $now);
        $dayCount = (int) $redis->incr($dayKey);
        if ($dayCount === 1) {
            $redis->expire($dayKey, 90000);
        }

        if ($dayCount > $perDay) {
            Logger::get()->warning('Rate limit exceeded (per-day)', ['app_id' => $appId]);
            http_response_code(429);
            echo json_encode([
                'error'  => "Rate limit exceeded. Maximum {$perDay} requests per day.",
                'status' => 429,
            ]);
            return false;
        }

        return true;
    }
}
