<?php

declare(strict_types=1);

namespace AgentGateway\Service;

use AgentGateway\Logger;

class LlmGatewayService
{
    private string $gatewayApiKey;
    private string $gatewayBaseUrl;
    private string $defaultModel;
    private McpClient $mcpClient;

    public function __construct(
        string $gatewayApiKey,
        string $gatewayBaseUrl,
        string $mcpServerUrl,
        string $defaultModel
    ) {
        $this->gatewayApiKey  = $gatewayApiKey;
        $this->gatewayBaseUrl = rtrim($gatewayBaseUrl, '/');
        $this->defaultModel   = $defaultModel;
        $this->mcpClient      = new McpClient($mcpServerUrl);
    }

    /**
     * @param string $systemPrompt
     * @param list<array{role: string, content: string}> $messages
     * @param string|null $model
     * @param string $userApiKey    Ontraport API key forwarded to MCP
     * @param string $userAppId     Ontraport App ID forwarded to MCP
     *
     * @return array{response: string, response_content: array, full_messages: array, actions_taken: list<array{tool_name: string, summary: string}>, usage: array{input_tokens: int, output_tokens: int}, anthropic_latency_ms: int, mcp_tool_latency_ms: int}
     */
    public function sendMessage(
        string $systemPrompt,
        array $messages,
        ?string $model,
        string $userApiKey,
        string $userAppId
    ): array {
        $startTime = microtime(true);

        $mcpTools = $this->mcpClient->listTools($userApiKey, $userAppId);
        $openAiTools = $this->convertToolsToOpenAiFormat($mcpTools);

        $gatewayMessages = $this->buildGatewayMessages($systemPrompt, $messages);

        $payload = [
            'model'      => $model ?? $this->defaultModel,
            'max_tokens' => 4096,
            'messages'   => $gatewayMessages,
        ];

        if (!empty($openAiTools)) {
            $payload['tools'] = $openAiTools;
            $payload['tool_choice'] = 'auto';
        }

        $response = $this->request($payload);
        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        $responseText = $this->cleanResponseText($response['content'] ?? '');
        $actionsTaken = [];
        $mcpToolLatencyMs = 0;
        $totalInputTokens  = $response['usage']['prompt_tokens'] ?? 0;
        $totalOutputTokens = $response['usage']['completion_tokens'] ?? 0;

        if (isset($response['tool_calls']) && is_array($response['tool_calls']) && !empty($response['tool_calls'])) {
            $result = $this->handleToolLoop(
                $response,
                $gatewayMessages,
                $payload,
                $userApiKey,
                $userAppId,
                $openAiTools
            );
            $responseText      = $result['response'];
            $actionsTaken      = $result['actions_taken'];
            $mcpToolLatencyMs  = $result['mcp_tool_latency_ms'];
            $totalInputTokens  += $result['total_usage']['input_tokens'];
            $totalOutputTokens += $result['total_usage']['output_tokens'];
            $gatewayMessages   = $result['full_messages'];
        }

        $responseContent = [];
        if (!empty($responseText)) {
            $responseContent[] = ['type' => 'text', 'text' => $responseText];
        }

        return [
            'response'             => $responseText,
            'response_content'     => $responseContent,
            'full_messages'        => $messages,
            'actions_taken'        => $actionsTaken,
            'usage'                => [
                'input_tokens'  => $totalInputTokens,
                'output_tokens' => $totalOutputTokens,
            ],
            'anthropic_latency_ms' => $latencyMs,
            'mcp_tool_latency_ms'  => $mcpToolLatencyMs,
        ];
    }

    /**
     * @return array{response: string, actions_taken: list<array{tool_name: string, summary: string}>, mcp_tool_latency_ms: int, full_messages: array, total_usage: array{input_tokens: int, output_tokens: int}}
     */
    private function handleToolLoop(
        array $currentResponse,
        array $messages,
        array $originalPayload,
        string $userApiKey,
        string $userAppId,
        array $openAiTools
    ): array {
        $actionsTaken      = [];
        $response          = $currentResponse;
        $maxIterations     = 20;
        $iteration         = 0;
        $mcpToolLatencyMs  = 0;
        $totalInputTokens  = 0;
        $totalOutputTokens = 0;

        while (isset($response['tool_calls']) && !empty($response['tool_calls']) && $iteration < $maxIterations) {
            $iteration++;

            $assistantMessage = ['role' => 'assistant'];
            if (!empty($response['content'])) {
                $assistantMessage['content'] = $response['content'];
            }
            $assistantMessage['tool_calls'] = $response['tool_calls'];
            $messages[] = $assistantMessage;

            foreach ($response['tool_calls'] as $toolCall) {
                $functionName = $toolCall['function']['name'] ?? 'unknown';
                $functionArgs = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [];
                $toolCallId   = $toolCall['id'] ?? '';

                $actionsTaken[] = [
                    'tool_name' => $functionName,
                    'summary'   => $this->summarizeToolCall($functionName, $functionArgs),
                ];

                $toolStart  = microtime(true);
                $toolResult = $this->mcpClient->callTool($functionName, $functionArgs, $userApiKey, $userAppId);
                $mcpToolLatencyMs += (int) ((microtime(true) - $toolStart) * 1000);

                $resultContent = '';
                if (isset($toolResult['content']) && is_array($toolResult['content'])) {
                    foreach ($toolResult['content'] as $block) {
                        if (isset($block['text'])) {
                            $resultContent .= $block['text'];
                        }
                    }
                } else {
                    $resultContent = json_encode($toolResult);
                }

                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $toolCallId,
                    'content'      => $resultContent,
                ];
            }

            $payload = [
                'model'      => $originalPayload['model'],
                'max_tokens' => 4096,
                'messages'   => $messages,
            ];
            if (!empty($openAiTools)) {
                $payload['tools'] = $openAiTools;
                $payload['tool_choice'] = 'auto';
            }

            $response = $this->request($payload);
            $totalInputTokens  += $response['usage']['prompt_tokens'] ?? 0;
            $totalOutputTokens += $response['usage']['completion_tokens'] ?? 0;
        }

        $responseText = $this->cleanResponseText($response['content'] ?? '');

        return [
            'response'            => $responseText,
            'actions_taken'       => $actionsTaken,
            'mcp_tool_latency_ms' => $mcpToolLatencyMs,
            'full_messages'       => $messages,
            'total_usage'         => [
                'input_tokens'  => $totalInputTokens,
                'output_tokens' => $totalOutputTokens,
            ],
        ];
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @return list<array{role: string, content: string}>
     */
    private function buildGatewayMessages(string $systemPrompt, array $messages): array
    {
        $result = [['role' => 'system', 'content' => $systemPrompt]];

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';

            if (is_array($content)) {
                $textParts = [];
                foreach ($content as $block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'text') {
                        $textParts[] = $block['text'] ?? '';
                    } elseif (is_array($block) && ($block['type'] ?? '') === 'tool_result') {
                        $textParts[] = json_encode($block);
                    } elseif (is_string($block)) {
                        $textParts[] = $block;
                    }
                }
                $content = implode("\n", $textParts);
            }

            $result[] = ['role' => $role, 'content' => $content];
        }

        return $result;
    }

    /**
     * @param list<array{name: string, description: string, inputSchema: array}> $mcpTools
     * @return list<array{type: string, function: array{name: string, description: string, parameters: array}}>
     */
    private function convertToolsToOpenAiFormat(array $mcpTools): array
    {
        $tools = [];
        foreach ($mcpTools as $tool) {
            $schema = $tool['inputSchema'] ?? ['type' => 'object', 'properties' => new \stdClass()];

            if (!isset($schema['type'])) {
                $schema['type'] = 'object';
            }
            if (!isset($schema['properties'])) {
                $schema['properties'] = new \stdClass();
            }

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name'        => $tool['name'] ?? '',
                    'description' => $tool['description'] ?? '',
                    'parameters'  => $schema,
                ],
            ];
        }

        return $tools;
    }

    private function cleanResponseText(string $text): string
    {
        $text = preg_replace('/<invoke\b[^>]*>.*?<\/invoke>/s', '', $text);
        $text = preg_replace('/<result>.*?<\/result>/s', '', $text);
        $text = preg_replace('/<tool_call>.*?<\/tool_call>/s', '', $text);
        $text = preg_replace('/<tool_result>.*?<\/tool_result>/s', '', $text);
        $text = preg_replace('/<function_call>.*?<\/function_call>/s', '', $text);
        $text = preg_replace('/<function_result>.*?<\/function_result>/s', '', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    private function summarizeToolCall(string $name, array $args): string
    {
        $params = [];
        foreach (array_slice($args, 0, 3) as $key => $value) {
            if (is_string($value)) {
                $params[] = "{$key}=" . mb_substr($value, 0, 50);
            }
        }

        $paramStr = !empty($params) ? ' (' . implode(', ', $params) . ')' : '';
        return "Called {$name}{$paramStr}";
    }

    /**
     * @return array<string, mixed>
     */
    private function request(array $payload): array
    {
        $ch = curl_init("{$this->gatewayBaseUrl}/api/v1/chat/completions");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: Bearer {$this->gatewayApiKey}",
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT    => 120,
        ]);

        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            Logger::get()->error('LLM Gateway request failed', ['error' => $error]);
            throw new \RuntimeException("LLM Gateway error: {$error}");
        }

        curl_close($ch);

        $decoded = json_decode((string) $result, true);

        if ($httpCode === 429) {
            throw new \OverflowException('LLM Gateway rate limit exceeded');
        }

        if ($httpCode >= 500) {
            throw new \RuntimeException("LLM Gateway returned HTTP {$httpCode}");
        }

        if (!is_array($decoded)) {
            Logger::get()->error('Invalid LLM Gateway response', [
                'http_code' => $httpCode,
                'body' => mb_substr((string) $result, 0, 500),
            ]);
            throw new \RuntimeException('Invalid response from LLM Gateway');
        }

        if (isset($decoded['error'])) {
            $msg = is_string($decoded['error']) ? $decoded['error'] : ($decoded['error']['message'] ?? 'unknown');
            if ($httpCode >= 400 && $httpCode < 500) {
                throw new \InvalidArgumentException("LLM Gateway bad request: {$msg}");
            }
            throw new \RuntimeException("LLM Gateway error: {$msg}");
        }

        return $decoded;
    }
}
