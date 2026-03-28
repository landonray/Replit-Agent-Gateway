<?php

declare(strict_types=1);

namespace AgentGateway\Controller;

use AgentGateway\Logger;
use AgentGateway\Service\AnthropicService;
use AgentGateway\Service\ConversationService;
use AgentGateway\Service\SystemPromptService;

class AgentController
{
    private AnthropicService $anthropic;
    private ConversationService $conversation;

    public function __construct(AnthropicService $anthropic, ConversationService $conversation)
    {
        $this->anthropic    = $anthropic;
        $this->conversation = $conversation;
    }

    /**
     * @param array<string, mixed> $body
     * @param array{api_key: string, app_id: string} $credentials
     */
    public function handle(array $body, array $credentials): void
    {
        $startTime = microtime(true);
        $logger    = Logger::get();

        $message        = $body['message'];
        $conversationId = $body['conversation_id'] ?? null;
        $context        = $body['context'] ?? null;
        $model          = $body['model'] ?? null;

        $isSingleTurn = ($conversationId === null);
        if ($isSingleTurn) {
            $conversationId = ConversationService::generateId();
            $history = [];
        } else {
            $history = $this->conversation->getHistory($conversationId);
        }

        $messages   = $history;
        $messages[] = ['role' => 'user', 'content' => $message];

        $systemPrompt = SystemPromptService::build(
            is_array($context) ? $context : null
        );

        try {
            $result = $this->anthropic->sendMessage(
                $systemPrompt,
                $messages,
                $model,
                $credentials['api_key'],
                $credentials['app_id']
            );
        } catch (\InvalidArgumentException $e) {
            $logger->error('Anthropic API bad request', [
                'error'           => $e->getMessage(),
                'conversation_id' => $conversationId,
                'app_id'          => $credentials['app_id'],
            ]);

            http_response_code(400);
            echo json_encode([
                'error'  => 'Invalid request: ' . $e->getMessage(),
                'status' => 400,
            ]);
            return;
        } catch (\OverflowException $e) {
            $logger->warning('Anthropic API rate limited', [
                'conversation_id' => $conversationId,
                'app_id'          => $credentials['app_id'],
            ]);

            http_response_code(429);
            echo json_encode([
                'error'  => 'Upstream rate limit exceeded. Please try again later.',
                'status' => 429,
            ]);
            return;
        } catch (\RuntimeException $e) {
            $logger->error('Anthropic API call failed', [
                'error'           => $e->getMessage(),
                'conversation_id' => $conversationId,
                'app_id'          => $credentials['app_id'],
            ]);

            http_response_code(502);
            echo json_encode([
                'error'  => 'Upstream service error. Please try again.',
                'status' => 502,
            ]);
            return;
        }

        if (!$isSingleTurn) {
            $this->conversation->saveHistory(
                $conversationId,
                $result['full_messages'],
                $result['response_content']
            );
        }

        $totalLatencyMs = (int) ((microtime(true) - $startTime) * 1000);

        $logger->info('Request completed', [
            'app_id'               => $credentials['app_id'],
            'conversation_id'      => $conversationId,
            'user_message'         => mb_substr($message, 0, 200),
            'tools_called'         => $result['actions_taken'],
            'usage'                => $result['usage'],
            'total_latency_ms'     => $totalLatencyMs,
            'anthropic_latency_ms' => $result['anthropic_latency_ms'],
            'mcp_tool_latency_ms'  => $result['mcp_tool_latency_ms'],
        ]);

        http_response_code(200);
        echo json_encode([
            'response'        => $result['response'],
            'conversation_id' => $conversationId,
            'actions_taken'   => $result['actions_taken'],
            'usage'           => $result['usage'],
        ]);
    }
}
