<?php

declare(strict_types=1);

namespace AgentGateway\Middleware;

class AuthMiddleware
{
    /**
     * @return array{api_key: string, app_id: string}|null
     */
    public static function handle(): ?array
    {
        $apiKey = $_SERVER['HTTP_API_KEY'] ?? null;
        $appId  = $_SERVER['HTTP_API_APPID'] ?? null;

        if (empty($apiKey) || empty($appId)) {
            http_response_code(401);
            echo json_encode([
                'error'  => 'Missing required authentication headers: Api-Key and Api-Appid',
                'status' => 401,
            ]);
            return null;
        }

        return [
            'api_key' => $apiKey,
            'app_id'  => $appId,
        ];
    }
}
