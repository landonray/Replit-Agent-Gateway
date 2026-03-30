<?php

declare(strict_types=1);

namespace AgentGateway\Service;

use AgentGateway\Logger;

class McpClient
{
    private string $serverUrl;
    private ?string $sessionId = null;

    public function __construct(string $serverUrl)
    {
        $this->serverUrl = rtrim($serverUrl, '/');
    }

    public function initialize(string $apiKey, string $appId): bool
    {
        $response = $this->jsonRpc('initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities' => new \stdClass(),
            'clientInfo' => [
                'name' => 'agent-gateway',
                'version' => '1.0.0',
            ],
        ], $apiKey, $appId);

        if ($response !== null && isset($response['result'])) {
            $this->sendNotification('notifications/initialized', [], $apiKey, $appId);
            return true;
        }

        return false;
    }

    /**
     * @return list<array{name: string, description: string, inputSchema: array}>
     */
    public function listTools(string $apiKey, string $appId): array
    {
        $response = $this->listToolsRaw($apiKey, $appId);

        if ($response === null || !isset($response['result']['tools'])) {
            return [];
        }

        return $response['result']['tools'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function listToolsRaw(string $apiKey, string $appId): ?array
    {
        if ($this->sessionId === null) {
            $this->initialize($apiKey, $appId);
        }

        $response = $this->jsonRpc('tools/list', [], $apiKey, $appId);

        if ($response === null || !isset($response['result']['tools'])) {
            Logger::get()->warning('MCP tools/list returned no tools', [
                'response' => $response,
            ]);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function callTool(string $name, array $arguments, string $apiKey, string $appId): array
    {
        if ($this->sessionId === null) {
            $this->initialize($apiKey, $appId);
        }

        $response = $this->jsonRpc('tools/call', [
            'name' => $name,
            'arguments' => $arguments,
        ], $apiKey, $appId);

        if ($response === null) {
            return ['error' => 'MCP tool call failed: no response'];
        }

        if (isset($response['error'])) {
            return ['error' => $response['error']['message'] ?? 'MCP tool call error'];
        }

        return $response['result'] ?? ['error' => 'No result from MCP tool call'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonRpc(string $method, array $params, string $apiKey, string $appId): ?array
    {
        $id = bin2hex(random_bytes(8));
        $body = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => empty($params) ? new \stdClass() : $params,
            'id' => $id,
        ];

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
            "Api-Key: {$apiKey}",
            "Api-Appid: {$appId}",
        ];

        if ($this->sessionId !== null) {
            $headers[] = "Mcp-Session-Id: {$this->sessionId}";
        }

        $ch = curl_init($this->serverUrl . '/mcp');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADER => true,
        ]);

        $rawResult = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            Logger::get()->error('MCP request failed', ['method' => $method, 'error' => $error]);
            return null;
        }

        curl_close($ch);

        $responseHeaders = substr((string) $rawResult, 0, $headerSize);
        $responseBody = substr((string) $rawResult, $headerSize);

        if (preg_match('/mcp-session-id:\s*(\S+)/i', $responseHeaders, $matches)) {
            $this->sessionId = $matches[1];
        }

        if ($httpCode >= 400) {
            Logger::get()->error('MCP request returned error', [
                'method' => $method,
                'http_code' => $httpCode,
                'body' => mb_substr($responseBody, 0, 500),
            ]);
            return null;
        }

        $contentType = '';
        if (preg_match('/content-type:\s*([^\r\n]+)/i', $responseHeaders, $ctMatch)) {
            $contentType = trim($ctMatch[1]);
        }

        if (str_contains($contentType, 'text/event-stream')) {
            return $this->parseSSE($responseBody);
        }

        $decoded = json_decode($responseBody, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function sendNotification(string $method, array $params, string $apiKey, string $appId): void
    {
        $body = [
            'jsonrpc' => '2.0',
            'method' => $method,
        ];
        if (!empty($params)) {
            $body['params'] = $params;
        }

        $headers = [
            'Content-Type: application/json',
            "Api-Key: {$apiKey}",
            "Api-Appid: {$appId}",
        ];

        if ($this->sessionId !== null) {
            $headers[] = "Mcp-Session-Id: {$this->sessionId}";
        }

        $ch = curl_init($this->serverUrl . '/mcp');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 10,
        ]);

        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseSSE(string $body): ?array
    {
        $lines = explode("\n", $body);
        $lastData = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'data: ')) {
                $data = substr($line, 6);
                $decoded = json_decode($data, true);
                if (is_array($decoded)) {
                    $lastData = $decoded;
                }
            }
        }

        return $lastData;
    }
}
