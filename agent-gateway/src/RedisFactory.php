<?php

declare(strict_types=1);

namespace AgentGateway;

use Predis\Client as RedisClient;

class RedisFactory
{
    public static function create(string $redisUrl): RedisClient
    {
        $url = self::parseUrl($redisUrl);

        if (str_starts_with($url, 'rediss://')) {
            $params = parse_url($url);
            return new RedisClient([
                'scheme'   => 'tls',
                'host'     => $params['host'] ?? 'localhost',
                'port'     => $params['port'] ?? 6379,
                'password' => $params['pass'] ?? null,
                'username' => $params['user'] ?? 'default',
            ]);
        }

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? 'localhost';
        if (str_contains($host, 'upstash.io') || str_contains($host, 'upstash.com')) {
            $params = parse_url($url);
            return new RedisClient([
                'scheme'   => 'tls',
                'host'     => $params['host'] ?? 'localhost',
                'port'     => $params['port'] ?? 6379,
                'password' => $params['pass'] ?? null,
                'username' => $params['user'] ?? 'default',
            ]);
        }

        return new RedisClient($url);
    }

    private static function parseUrl(string $raw): string
    {
        $raw = trim($raw);

        if (preg_match('/redis-cli\s.*?-u\s+(rediss?:\/\/\S+)/', $raw, $matches)) {
            $url = $matches[1];
            if (str_contains($raw, '--tls') && str_starts_with($url, 'redis://')) {
                $url = 'rediss://' . substr($url, 8);
            }
            return $url;
        }

        if (str_starts_with($raw, 'redis://') || str_starts_with($raw, 'rediss://')) {
            return $raw;
        }

        return $raw;
    }
}
