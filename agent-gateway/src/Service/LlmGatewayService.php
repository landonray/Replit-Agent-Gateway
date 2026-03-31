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

    private ?DeduplicationService $dedup = null;
    private ?SchemaValidationService $schemaValidator = null;
    private ?LlmJudgeService $judge = null;
    private ?CircuitBreakerService $circuitBreaker = null;

    private string $accountId = '';
    private string $conversationId = '';
    private string $currentUserMessage = '';

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

    public function setDeduplicationService(DeduplicationService $dedup): void
    {
        $this->dedup = $dedup;
    }

    public function setSchemaValidationService(SchemaValidationService $validator): void
    {
        $this->schemaValidator = $validator;
    }

    public function setLlmJudgeService(LlmJudgeService $judge): void
    {
        $this->judge = $judge;
    }

    public function setCircuitBreakerService(CircuitBreakerService $cb): void
    {
        $this->circuitBreaker = $cb;
    }

    /**
     * @param string $systemPrompt
     * @param list<array{role: string, content: string}> $messages
     * @param string|null $model
     * @param string $userApiKey    Ontraport API key forwarded to MCP
     * @param string $userAppId     Ontraport App ID forwarded to MCP
     * @param string $conversationId
     * @param string $currentUserMessage  The user's latest message (for dedup repeat detection)
     *
     * @return array{response: string, response_content: array, full_messages: array, tool_calls: list<array>, actions_taken: list<array>, usage: array{input_tokens: int, output_tokens: int}, judge_usage: array{input_tokens: int, output_tokens: int}, anthropic_latency_ms: int, mcp_tool_latency_ms: int}
     */
    public function sendMessage(
        string $systemPrompt,
        array $messages,
        ?string $model,
        string $userApiKey,
        string $userAppId,
        string $conversationId = '',
        string $currentUserMessage = ''
    ): array {
        $startTime = microtime(true);
        $this->accountId = $userAppId;
        $this->conversationId = $conversationId;
        $this->currentUserMessage = $currentUserMessage;

        $mcpTools = $this->mcpClient->listTools($userApiKey, $userAppId);

        // Load tool manifest into schema validator
        if ($this->schemaValidator !== null) {
            $this->schemaValidator->loadManifest($mcpTools);
        }

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

        $responseText = $response['content'] ?? '';
        $actionsTaken = [];
        $toolCallResults = [];
        $mcpToolLatencyMs = 0;
        $totalInputTokens  = $response['usage']['prompt_tokens'] ?? 0;
        $totalOutputTokens = $response['usage']['completion_tokens'] ?? 0;
        $totalJudgeInputTokens = 0;
        $totalJudgeOutputTokens = 0;

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
            $toolCallResults   = $result['tool_call_results'];
            $mcpToolLatencyMs  = $result['mcp_tool_latency_ms'];
            $totalInputTokens  += $result['total_usage']['input_tokens'];
            $totalOutputTokens += $result['total_usage']['output_tokens'];
            $totalJudgeInputTokens  += $result['judge_usage']['input_tokens'];
            $totalJudgeOutputTokens += $result['judge_usage']['output_tokens'];
            $gatewayMessages   = $result['full_messages'];
        }

        $responseContent = [];
        if (!empty($responseText)) {
            $responseContent[] = ['type' => 'text', 'text' => $responseText];
        }

        // Strip the system prompt (index 0) from gateway messages before returning
        // so conversation history doesn't duplicate the system prompt on each turn
        $messagesForHistory = array_slice($gatewayMessages, 1);

        return [
            'response'             => $responseText,
            'response_content'     => $responseContent,
            'full_messages'        => $messagesForHistory,
            'tool_calls'           => $toolCallResults,
            'actions_taken'        => $actionsTaken,
            'usage'                => [
                'input_tokens'  => $totalInputTokens,
                'output_tokens' => $totalOutputTokens,
            ],
            'judge_usage'          => [
                'input_tokens'  => $totalJudgeInputTokens,
                'output_tokens' => $totalJudgeOutputTokens,
            ],
            'anthropic_latency_ms' => $latencyMs,
            'mcp_tool_latency_ms'  => $mcpToolLatencyMs,
        ];
    }

    /**
     * @return array{response: string, actions_taken: list<array>, tool_call_results: list<array>, mcp_tool_latency_ms: int, full_messages: array, total_usage: array{input_tokens: int, output_tokens: int}, judge_usage: array{input_tokens: int, output_tokens: int}}
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
        $toolCallResults   = [];
        $response          = $currentResponse;
        $maxIterations     = 50;
        $iteration         = 0;
        $mcpToolLatencyMs  = 0;
        $totalInputTokens  = 0;
        $totalOutputTokens = 0;
        $totalJudgeInputTokens  = 0;
        $totalJudgeOutputTokens = 0;

        $bypassDedup = $this->dedup !== null && $this->dedup->userRequestedRepeat($this->currentUserMessage);
        $schemaRetryCount = []; // Track retry attempts per tool call: key = "toolName:paramHash" => count

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

                $isWriteTool = $this->schemaValidator !== null
                    ? $this->schemaValidator->getToolCategory($functionName) === 'write'
                    : true;
                $isCommunicationTool = $this->schemaValidator !== null
                    ? $this->schemaValidator->isCommunicationTool($functionName)
                    : false;
                $isFinancialTool = $this->schemaValidator !== null
                    ? $this->schemaValidator->isFinancialTool($functionName)
                    : false;

                // === GUARDRAIL PIPELINE ===

                // 1. Circuit Breaker check (before anything else for account-level protection)
                if ($this->circuitBreaker !== null) {
                    $cbResult = $this->circuitBreaker->check(
                        $this->accountId,
                        $this->conversationId,
                        $functionName,
                        $isWriteTool,
                        $isCommunicationTool,
                        $isFinancialTool
                    );
                    if ($cbResult !== null) {
                        Logger::get()->warning('Circuit breaker blocked tool call', [
                            'tool' => $functionName,
                            'metric' => $cbResult['metric'],
                        ]);

                        $errorContent = json_encode([
                            'success' => false,
                            'error' => $cbResult['message'],
                        ]);

                        $toolCallResults[] = [
                            'tool' => $functionName,
                            'parameters' => $functionArgs,
                            'success' => false,
                            'result' => null,
                            'error' => $cbResult['message'],
                            'blocked_by' => 'circuit_breaker',
                        ];

                        $messages[] = [
                            'role'         => 'tool',
                            'tool_call_id' => $toolCallId,
                            'content'      => $errorContent,
                        ];
                        continue;
                    }
                }

                // 2. Deduplication check (write tools only)
                if ($isWriteTool && $this->dedup !== null && !$bypassDedup) {
                    $dedupResult = $this->dedup->check($this->conversationId, $functionName, $functionArgs);
                    if ($dedupResult !== null) {
                        Logger::get()->info('Dedup intercepted duplicate tool call', [
                            'tool' => $functionName,
                        ]);

                        $toolCallResults[] = [
                            'tool' => $functionName,
                            'parameters' => $functionArgs,
                            'success' => true,
                            'result' => $dedupResult,
                            'deduplicated' => true,
                        ];

                        $messages[] = [
                            'role'         => 'tool',
                            'tool_call_id' => $toolCallId,
                            'content'      => $dedupResult,
                        ];
                        continue;
                    }
                }

                // 3. Schema Validation (with 2-retry limit)
                if ($this->schemaValidator !== null) {
                    $validationError = $this->schemaValidator->validate($functionName, $functionArgs);

                    if ($validationError !== null) {
                        $retryKey = $functionName . ':' . md5(json_encode($functionArgs) ?: '{}');
                        $schemaRetryCount[$retryKey] = ($schemaRetryCount[$retryKey] ?? 0) + 1;
                        $attemptNum = $schemaRetryCount[$retryKey];

                        Logger::get()->warning('Schema validation failed', [
                            'tool' => $functionName,
                            'error' => $validationError,
                            'params' => $functionArgs,
                            'retry_attempt' => $attemptNum,
                        ]);

                        $retryMessage = $attemptNum < 3
                            ? "Validation error: {$validationError} Please correct the parameters and try again. (Attempt {$attemptNum} of 2)"
                            : "Validation error: {$validationError} Maximum retry attempts reached. This tool call cannot be completed.";

                        $errorContent = json_encode([
                            'success' => false,
                            'error' => $retryMessage,
                        ]) ?: '{"success":false,"error":"Validation error"}';

                        $toolCallResults[] = [
                            'tool' => $functionName,
                            'parameters' => $functionArgs,
                            'success' => false,
                            'result' => null,
                            'error' => $validationError,
                            'blocked_by' => 'schema_validation',
                            'retry_attempt' => $attemptNum,
                        ];

                        $messages[] = [
                            'role'         => 'tool',
                            'tool_call_id' => $toolCallId,
                            'content'      => $errorContent,
                        ];
                        continue;
                    }

                    // Strip extra parameters
                    $functionArgs = $this->schemaValidator->stripExtraParams($functionName, $functionArgs);
                }

                // 4. LLM Judge (write tools only)
                if ($isWriteTool && $this->judge !== null) {
                    $toolDef = $this->schemaValidator !== null
                        ? $this->schemaValidator->getToolDefinition($functionName)
                        : null;
                    $toolDescription = $toolDef['description'] ?? "Tool: {$functionName}";

                    $judgeResult = $this->judge->review(
                        $messages,
                        $functionName,
                        $functionArgs,
                        $toolDescription
                    );

                    $totalJudgeInputTokens  += $judgeResult['judge_usage']['input_tokens'];
                    $totalJudgeOutputTokens += $judgeResult['judge_usage']['output_tokens'];

                    if (!$judgeResult['approved']) {
                        Logger::get()->warning('LLM Judge rejected tool call', [
                            'tool' => $functionName,
                            'reason' => $judgeResult['reason'],
                            'confidence' => $judgeResult['confidence'],
                        ]);

                        $errorContent = json_encode([
                            'success' => false,
                            'error' => "Blocked by safety review: {$judgeResult['reason']}",
                        ]);

                        $toolCallResults[] = [
                            'tool' => $functionName,
                            'parameters' => $functionArgs,
                            'success' => false,
                            'result' => null,
                            'error' => "Blocked by safety review: {$judgeResult['reason']}",
                            'blocked_by' => 'llm_judge',
                        ];

                        $messages[] = [
                            'role'         => 'tool',
                            'tool_call_id' => $toolCallId,
                            'content'      => $errorContent,
                        ];
                        continue;
                    }

                    Logger::get()->info('LLM Judge approved tool call', [
                        'tool' => $functionName,
                        'confidence' => $judgeResult['confidence'],
                        'reason' => $judgeResult['reason'],
                    ]);
                }

                // === EXECUTE TOOL CALL ===
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

                // Determine success from MCP response
                $toolSuccess = !isset($toolResult['error']) && !($toolResult['isError'] ?? false);

                // Parse structured result from MCP envelope if present
                $parsedResult = null;
                $resultJson = json_decode($resultContent, true);
                if (is_array($resultJson)) {
                    if (isset($resultJson['success'])) {
                        $toolSuccess = (bool) $resultJson['success'];
                    }
                    $parsedResult = $resultJson['result'] ?? $resultJson;
                }

                // Record in circuit breaker
                if ($this->circuitBreaker !== null) {
                    $this->circuitBreaker->recordExecution(
                        $this->accountId,
                        $this->conversationId,
                        $functionName,
                        $isWriteTool,
                        $isCommunicationTool,
                        $isFinancialTool,
                        $toolSuccess
                    );
                }

                // Record in dedup log (write tools, successful only)
                if ($isWriteTool && $toolSuccess && $this->dedup !== null) {
                    $this->dedup->record($this->conversationId, $functionName, $functionArgs, $resultContent);
                }

                // Build tool_calls entry
                $toolCallEntry = [
                    'tool' => $functionName,
                    'parameters' => $functionArgs,
                    'success' => $toolSuccess,
                ];
                if ($toolSuccess) {
                    $toolCallEntry['result'] = $parsedResult ?? $resultContent;
                } else {
                    $toolCallEntry['error'] = $resultJson['error'] ?? ($toolResult['error'] ?? 'Tool call failed');
                }
                $toolCallResults[] = $toolCallEntry;

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

        $responseText = $response['content'] ?? '';

        return [
            'response'            => $responseText,
            'actions_taken'       => $actionsTaken,
            'tool_call_results'   => $toolCallResults,
            'mcp_tool_latency_ms' => $mcpToolLatencyMs,
            'full_messages'       => $messages,
            'total_usage'         => [
                'input_tokens'  => $totalInputTokens,
                'output_tokens' => $totalOutputTokens,
            ],
            'judge_usage'         => [
                'input_tokens'  => $totalJudgeInputTokens,
                'output_tokens' => $totalJudgeOutputTokens,
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
            $schema = $this->sanitizeSchema($schema);

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

    private function sanitizeSchema(array $schema): array
    {
        if (!isset($schema['type'])) {
            $schema['type'] = 'object';
        }

        if (isset($schema['required'])) {
            if (!is_array($schema['required'])) {
                unset($schema['required']);
            } else {
                $schema['required'] = array_values(array_filter($schema['required'], 'is_string'));
                if (empty($schema['required'])) {
                    unset($schema['required']);
                }
            }
        }

        if ($schema['type'] === 'object') {
            if (!isset($schema['properties']) || !is_array($schema['properties'])) {
                $schema['properties'] = new \stdClass();
            } else {
                foreach ($schema['properties'] as $propName => &$propDef) {
                    if (!is_array($propDef)) {
                        $propDef = ['type' => 'string', 'description' => is_string($propDef) ? $propDef : ''];
                    } else {
                        $propDef = $this->sanitizeSchema($propDef);
                    }
                }
                unset($propDef);

                if (empty($schema['properties'])) {
                    $schema['properties'] = new \stdClass();
                }
            }

            if (isset($schema['required']) && is_array($schema['required']) && is_array($schema['properties'])) {
                $validProps = array_keys($schema['properties']);
                $schema['required'] = array_values(array_intersect($schema['required'], $validProps));
                if (empty($schema['required'])) {
                    unset($schema['required']);
                }
            }
        }

        if ($schema['type'] === 'array' && isset($schema['items'])) {
            if (is_array($schema['items'])) {
                $schema['items'] = $this->sanitizeSchema($schema['items']);
            } else {
                $schema['items'] = ['type' => 'string'];
            }
        }

        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            foreach ($schema['anyOf'] as &$subSchema) {
                if (is_array($subSchema)) {
                    $subSchema = $this->sanitizeSchema($subSchema);
                }
            }
            unset($subSchema);
        }

        if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
            foreach ($schema['oneOf'] as &$subSchema) {
                if (is_array($subSchema)) {
                    $subSchema = $this->sanitizeSchema($subSchema);
                }
            }
            unset($subSchema);
        }

        return $schema;
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
            Logger::get()->error('LLM Gateway 5xx error', [
                'http_code' => $httpCode,
                'body' => mb_substr((string) $result, 0, 1000),
            ]);
            $errMsg = "LLM Gateway returned HTTP {$httpCode}";
            if (is_array($decoded) && isset($decoded['error'])) {
                $detail = is_string($decoded['error']) ? $decoded['error'] : ($decoded['error']['message'] ?? json_encode($decoded['error']));
                $errMsg .= ": {$detail}";
            }
            throw new \RuntimeException($errMsg);
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
