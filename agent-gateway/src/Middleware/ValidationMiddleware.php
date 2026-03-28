<?php

declare(strict_types=1);

namespace AgentGateway\Middleware;

class ValidationMiddleware
{
    private const MAX_MESSAGE_LENGTH = 10_000;

    /**
     * @return array<string, mixed>|null
     */
    public static function handle(): ?array
    {
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw ?: '', true);

        if (!is_array($body) || empty($body['message']) || !is_string($body['message'])) {
            http_response_code(400);
            echo json_encode([
                'error'  => "Missing or invalid 'message' field in request body",
                'status' => 400,
            ]);
            return null;
        }

        if (mb_strlen($body['message']) > self::MAX_MESSAGE_LENGTH) {
            http_response_code(400);
            echo json_encode([
                'error'  => 'Message exceeds maximum length of ' . self::MAX_MESSAGE_LENGTH . ' characters',
                'status' => 400,
            ]);
            return null;
        }

        return $body;
    }
}
