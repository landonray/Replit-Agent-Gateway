<?php

declare(strict_types=1);

namespace AgentGateway\Service;

use AgentGateway\Logger;

class AnthropicService
{
    private string $apiKey;
    private string $mcpServerUrl;
    private string $defaultModel;

    public function __construct(string $apiKey, string $mcpServerUrl, string $defaultModel)
    {
        $this->apiKey       = $apiKey;
        $this->mcpServerUrl = $mcpServerUrl;
        $this->defaultModel = $defaultModel;
    }

    /**
     * Send a message to the Anthropic Messages API with MCP server integration.
     *
     * @param string $systemPrompt
     * @param list<array{role: string, content: string}> $messages
     * @param string|null $model
     * @param string $userApiKey    Ontraport API key forwarded to MCP
     * @param string $userAppId     Ontraport App ID forwarded to MCP
     *
     * @return array{response: string, actions_taken: list<array{tool_name: string, summary: string}>, usage: array{input_tokens: int, output_tokens: int}, anthropic_latency_ms: int, mcp_tool_latency_ms: int}
     */
    public function sendMessage(
        string $systemPrompt,
        array $messages,
        ?string $model,
        string $userApiKey,
        string $userAppId
    ): array {
        $startTime = microtime(true);

        $payload = [
            'model'      => $model ?? $this->defaultModel,
            'max_tokens' => 4096,
            'system'     => $systemPrompt,
            'messages'   => $messages,
            'mcp_servers' => [
                [
                    'url'     => $this->mcpServerUrl,
                    'headers' => [
                        'Api-Key'   => $userApiKey,
                        'Api-Appid' => $userAppId,
                    ],
                ],
            ],
        ];

        $response = $this->request('/v1/messages', $payload);
        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        // Extract text response
        $responseText = '';
        $actionsTaken = [];
        $mcpToolLatencyMs = 0;

        foreach ($response['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $responseText .= $block['text'];
            }
            if ($block['type'] === 'tool_use') {
                $actionsTaken[] = [
                    'tool_name' => $block['name'] ?? 'unknown',
                    'summary'   => $this->summarizeToolCall($block),
                ];
            }
        }

        // Track usage — for single-call responses, just use response usage.
        // For tool loops, accumulate across all iterations.
        $usage = [
            'input_tokens'  => $response['usage']['input_tokens'] ?? 0,
            'output_tokens' => $response['usage']['output_tokens'] ?? 0,
        ];

        // If the model wants to use tools, we need to handle the tool use loop
        if (($response['stop_reason'] ?? '') === 'tool_use') {
            $result = $this->handleToolLoop($response, $systemPrompt, $messages, $payload, $userApiKey, $userAppId);
            $responseText     = $result['response'];
            $actionsTaken     = $result['actions_taken'];
            $mcpToolLatencyMs = $result['mcp_tool_latency_ms'];
            $messages         = $result['full_messages'];
            $usage            = $result['total_usage'];
            $response         = $result['final_response'];
        }

        return [
            'response'             => $responseText,
            'response_content'     => $response['content'] ?? [],
            'full_messages'        => $messages,
            'actions_taken'        => $actionsTaken,
            'usage'                => $usage,
            'anthropic_latency_ms' => $latencyMs,
            'mcp_tool_latency_ms'  => $mcpToolLatencyMs,
        ];
    }

    /**
     * Handle the agentic tool-use loop: Claude calls tools via MCP, we collect results
     * and re-send until Claude produces a final text response.
     *
     * @return array{response: string, actions_taken: list<array{tool_name: string, summary: string}>, mcp_tool_latency_ms: int, full_messages: array, total_usage: array{input_tokens: int, output_tokens: int}, final_response: array<string, mixed>}
     */
    private function handleToolLoop(
        array $currentResponse,
        string $systemPrompt,
        array $conversationMessages,
        array $originalPayload,
        string $userApiKey,
        string $userAppId
    ): array {
        $actionsTaken     = [];
        $messages         = $conversationMessages;
        $response         = $currentResponse;
        $maxIterations    = 20;
        $iteration        = 0;
        $mcpToolLatencyMs = 0;
        $totalInputTokens  = $response['usage']['input_tokens'] ?? 0;
        $totalOutputTokens = $response['usage']['output_tokens'] ?? 0;

        while (($response['stop_reason'] ?? '') === 'tool_use' && $iteration < $maxIterations) {
            $iteration++;

            // Add the assistant message with tool_use blocks
            $messages[] = ['role' => 'assistant', 'content' => $response['content']];

            // Collect tool_use blocks and execute via MCP
            $toolResults = [];
            foreach ($response['content'] as $block) {
                if ($block['type'] === 'tool_use') {
                    $actionsTaken[] = [
                        'tool_name' => $block['name'] ?? 'unknown',
                        'summary'   => $this->summarizeToolCall($block),
                    ];

                    $toolStart  = microtime(true);
                    $toolResult = $this->executeMcpTool(
                        $block['name'],
                        $block['input'] ?? [],
                        $userApiKey,
                        $userAppId
                    );
                    $mcpToolLatencyMs += (int) ((microtime(true) - $toolStart) * 1000);

                    $toolResults[] = [
                        'type'       => 'tool_result',
                        'tool_use_id' => $block['id'],
                        'content'    => json_encode($toolResult),
                    ];
                }
            }

            // Send tool results back to Claude
            $messages[] = ['role' => 'user', 'content' => $toolResults];

            $payload = [
                'model'      => $originalPayload['model'],
                'max_tokens' => 4096,
                'system'     => $systemPrompt,
                'messages'   => $messages,
            ];

            $response = $this->request('/v1/messages', $payload);

            // Accumulate token usage across all iterations
            $totalInputTokens  += $response['usage']['input_tokens'] ?? 0;
            $totalOutputTokens += $response['usage']['output_tokens'] ?? 0;
        }

        // Extract final text
        $responseText = '';
        foreach ($response['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $responseText .= $block['text'];
            }
        }

        return [
            'response'            => $responseText,
            'actions_taken'       => $actionsTaken,
            'mcp_tool_latency_ms' => $mcpToolLatencyMs,
            'full_messages'       => $messages,
            'total_usage'         => [
                'input_tokens'  => $totalInputTokens,
                'output_tokens' => $totalOutputTokens,
            ],
            'final_response'      => $response,
        ];
    }

    /**
     * Execute a tool call against the Ontraport MCP server.
     *
     * @return array<string, mixed>
     */
    private function executeMcpTool(string $toolName, array $input, string $apiKey, string $appId): array
    {
        $ch = curl_init($this->mcpServerUrl . '/call-tool');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Api-Key: {$apiKey}",
                "Api-Appid: {$appId}",
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'name'  => $toolName,
                'input' => $input,
            ]),
            CURLOPT_TIMEOUT => 30,
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            Logger::get()->error('MCP tool call failed', [
                'tool'  => $toolName,
                'error' => $error,
            ]);
            return ['error' => "MCP server error: {$error}"];
        }

        curl_close($ch);

        if ($httpCode >= 400) {
            Logger::get()->error('MCP tool call returned error', [
                'tool'      => $toolName,
                'http_code' => $httpCode,
            ]);
            return ['error' => "MCP server returned HTTP {$httpCode}"];
        }

        $decoded = json_decode((string) $result, true);
        return is_array($decoded) ? $decoded : ['result' => $result];
    }

    /**
     * Create a short human-readable summary of a tool call.
     */
    private function summarizeToolCall(array $block): string
    {
        $name  = $block['name'] ?? 'unknown';
        $input = $block['input'] ?? [];

        $params = [];
        foreach (array_slice($input, 0, 3) as $key => $value) {
            if (is_string($value)) {
                $params[] = "{$key}=" . mb_substr($value, 0, 50);
            }
        }

        $paramStr = !empty($params) ? ' (' . implode(', ', $params) . ')' : '';
        return "Called {$name}{$paramStr}";
    }

    /**
     * Make an HTTP request to the Anthropic API.
     *
     * @return array<string, mixed>
     */
    private function request(string $path, array $payload): array
    {
        $ch = curl_init("https://api.anthropic.com{$path}");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "x-api-key: {$this->apiKey}",
                'anthropic-version: 2025-04-14',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT    => 120,
        ]);

        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            Logger::get()->error('Anthropic API request failed', ['error' => $error]);
            throw new \RuntimeException("Anthropic API error: {$error}");
        }

        curl_close($ch);

        $decoded = json_decode((string) $result, true);

        if ($httpCode === 429) {
            throw new \OverflowException('Anthropic API rate limit exceeded');
        }

        if ($httpCode >= 500) {
            throw new \RuntimeException("Anthropic API returned HTTP {$httpCode}");
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid response from Anthropic API');
        }

        if (isset($decoded['error'])) {
            $msg = $decoded['error']['message'] ?? 'unknown';
            if ($httpCode >= 400 && $httpCode < 500) {
                throw new \InvalidArgumentException("Anthropic API bad request: {$msg}");
            }
            throw new \RuntimeException("Anthropic API error: {$msg}");
        }

        return $decoded;
    }
}
