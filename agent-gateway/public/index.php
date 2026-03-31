<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use AgentGateway\Middleware\AuthMiddleware;
use AgentGateway\Middleware\RateLimitMiddleware;
use AgentGateway\Middleware\ValidationMiddleware;
use AgentGateway\Controller\AgentController;
use AgentGateway\Service\LlmGatewayService;
use AgentGateway\Service\ConversationService;
use AgentGateway\Service\DeduplicationService;
use AgentGateway\Service\SchemaValidationService;
use AgentGateway\Service\LlmJudgeService;
use AgentGateway\Service\CircuitBreakerService;

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

$config = require __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($uri === '/health' && $method === 'GET') {
    echo json_encode(['status' => 'ok']);
    exit;
}

if ($uri === '/api/v1/tools' && $method === 'GET') {
    try {
        $credentials = AuthMiddleware::handle();
        if ($credentials === null) {
            exit;
        }

        $mcpClient = new \AgentGateway\Service\McpClient($config['mcp_server_url']);
        $rawResponse = $mcpClient->listToolsRaw($credentials['api_key'], $credentials['app_id']);
        $tools = [];
        if (isset($rawResponse['result']['tools'])) {
            $tools = $rawResponse['result']['tools'];
        }

        $output = [
            'tool_count' => count($tools),
            'tools' => array_map(function ($t) {
                return [
                    'name' => $t['name'] ?? '',
                    'description' => $t['description'] ?? '',
                    'parameters' => array_keys($t['inputSchema']['properties'] ?? []),
                ];
            }, $tools),
            'mcp_server' => $config['mcp_server_url'],
        ];

        if (count($tools) === 0) {
            $output['mcp_raw_response'] = $rawResponse;
        }

        echo json_encode($output, JSON_PRETTY_PRINT);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($uri === '/api/v1/agent' && $method === 'POST') {
    try {
        $credentials = AuthMiddleware::handle();
        if ($credentials === null) {
            exit;
        }

        $allowed = RateLimitMiddleware::handle(
            $credentials['app_id'],
            $config['rate_limit_per_minute'],
            $config['rate_limit_per_day']
        );
        if (!$allowed) {
            exit;
        }

        $body = ValidationMiddleware::handle();
        if ($body === null) {
            exit;
        }

        if (!empty($body['stream'])) {
            http_response_code(501);
            echo json_encode([
                'error'  => 'Streaming is not yet supported. Omit the stream field or set it to false.',
                'status' => 501,
            ]);
            exit;
        }

        // Build LLM Gateway service
        $llmGateway = new LlmGatewayService(
            $config['llm_gateway_api_key'],
            $config['llm_gateway_base_url'],
            $config['mcp_server_url'],
            $config['default_model']
        );

        // Wire guardrail services
        $llmGateway->setDeduplicationService(
            new DeduplicationService($config['redis_url'], $config['conversation_ttl'])
        );

        $llmGateway->setSchemaValidationService(
            new SchemaValidationService($config['schema_refresh_interval'])
        );

        $llmGateway->setLlmJudgeService(
            new LlmJudgeService(
                $config['llm_gateway_api_key'],
                $config['llm_gateway_base_url'],
                $config['judge_model'],
                $config['judge_timeout']
            )
        );

        $llmGateway->setCircuitBreakerService(
            new CircuitBreakerService(
                $config['redis_url'],
                $config['cb_total_write_limit'],
                $config['cb_total_write_window'],
                $config['cb_same_tool_limit'],
                $config['cb_same_tool_window'],
                $config['cb_consecutive_failure_limit'],
                $config['cb_communication_limit'],
                $config['cb_communication_window'],
                $config['cb_financial_limit'],
                $config['cb_financial_window']
            )
        );

        $conversation = new ConversationService(
            $config['redis_url'],
            $config['conversation_ttl'],
            $config['conversation_max_messages']
        );

        $controller = new AgentController($llmGateway, $conversation);
        $controller->handle($body, $credentials);
    } catch (\Throwable $e) {
        \AgentGateway\Logger::get()->error('Unhandled exception', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        http_response_code(500);
        echo json_encode([
            'error'  => 'Internal server error',
            'status' => 500,
        ]);
    }
    exit;
}

// === Admin Endpoints ===

if ($uri === '/api/v1/admin/circuit-breaker/reset' && $method === 'POST') {
    try {
        $adminKey = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
        if (empty($config['admin_api_key']) || $adminKey !== $config['admin_api_key']) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $accountId = $input['account_id'] ?? '';
        $metric = $input['metric'] ?? '';

        if (empty($accountId) || empty($metric)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing account_id or metric']);
            exit;
        }

        // Sanitize metric to only allow known values
        $allowedMetrics = [
            'total_writes', 'communication', 'financial',
        ];
        // Also allow tool-specific metrics (tool:{name}) and conversation failures
        $isToolMetric = str_starts_with($metric, 'tool:') && preg_match('/^tool:[a-zA-Z0-9_]+$/', $metric);
        $isConvFailure = str_starts_with($metric, 'conv_failures:') && preg_match('/^conv_failures:[a-f0-9]+$/', $metric);

        if (!in_array($metric, $allowedMetrics, true) && !$isToolMetric && !$isConvFailure) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid metric. Allowed: ' . implode(', ', $allowedMetrics) . ', tool:{name}, conv_failures:{id}']);
            exit;
        }

        $cb = new CircuitBreakerService($config['redis_url']);
        $result = $cb->reset($accountId, $metric);

        echo json_encode([
            'success' => $result,
            'account_id' => $accountId,
            'metric' => $metric,
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($uri === '/api/v1/admin/circuit-breaker/status' && $method === 'GET') {
    try {
        $adminKey = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
        if (empty($config['admin_api_key']) || $adminKey !== $config['admin_api_key']) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }

        $accountId = $_GET['account_id'] ?? '';
        if (empty($accountId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing account_id query parameter']);
            exit;
        }

        $cb = new CircuitBreakerService($config['redis_url']);
        $status = $cb->getStatus($accountId);

        echo json_encode([
            'account_id' => $accountId,
            'circuit_breakers' => $status,
        ], JSON_PRETTY_PRINT);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(404);
echo json_encode([
    'error'  => 'Not found',
    'status' => 404,
]);
