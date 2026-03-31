<?php

declare(strict_types=1);

return [
    'llm_gateway_api_key'  => $_ENV['LLM_GATEWAY_API_KEY'] ?? '',
    'llm_gateway_base_url' => $_ENV['LLM_GATEWAY_BASE_URL'] ?? 'https://llm-gateway.replit.app',
    'mcp_server_url'       => $_ENV['MCP_SERVER_URL'] ?? '',
    'redis_url'            => $_ENV['REDIS_URL'] ?? 'redis://localhost:6379',
    'port'                 => (int) ($_ENV['PORT'] ?? 3000),
    'app_env'              => $_ENV['APP_ENV'] ?? 'production',

    'rate_limit_per_minute' => (int) ($_ENV['RATE_LIMIT_PER_MINUTE'] ?? 10),
    'rate_limit_per_day'    => (int) ($_ENV['RATE_LIMIT_PER_DAY'] ?? 500),

    'conversation_ttl'          => (int) ($_ENV['CONVERSATION_TTL_SECONDS'] ?? 86400),
    'conversation_max_messages' => (int) ($_ENV['CONVERSATION_MAX_MESSAGES'] ?? 20),

    'default_model' => $_ENV['DEFAULT_MODEL'] ?? 'claude-sonnet-4-20250514',

    // LLM Judge settings
    // Judge defaults to the same model as the primary agent. Override JUDGE_MODEL
    // to a cheaper model (e.g. claude-haiku-4-5-20251001) once confirmed your
    // LLM Gateway supports it.
    'judge_model'   => $_ENV['JUDGE_MODEL'] ?? ($_ENV['DEFAULT_MODEL'] ?? 'claude-sonnet-4-20250514'),
    'judge_timeout' => (int) ($_ENV['JUDGE_TIMEOUT_SECONDS'] ?? 15),

    // Circuit breaker defaults (overridable via env)
    'cb_total_write_limit'          => (int) ($_ENV['CB_TOTAL_WRITE_LIMIT'] ?? 50),
    'cb_total_write_window'         => (int) ($_ENV['CB_TOTAL_WRITE_WINDOW'] ?? 300),
    'cb_same_tool_limit'            => (int) ($_ENV['CB_SAME_TOOL_LIMIT'] ?? 20),
    'cb_same_tool_window'           => (int) ($_ENV['CB_SAME_TOOL_WINDOW'] ?? 300),
    'cb_consecutive_failure_limit'  => (int) ($_ENV['CB_CONSECUTIVE_FAILURE_LIMIT'] ?? 5),
    'cb_communication_limit'        => (int) ($_ENV['CB_COMMUNICATION_LIMIT'] ?? 10),
    'cb_communication_window'       => (int) ($_ENV['CB_COMMUNICATION_WINDOW'] ?? 3600),
    'cb_financial_limit'            => (int) ($_ENV['CB_FINANCIAL_LIMIT'] ?? 5),
    'cb_financial_window'           => (int) ($_ENV['CB_FINANCIAL_WINDOW'] ?? 3600),

    // Schema validation
    'schema_refresh_interval' => (int) ($_ENV['SCHEMA_REFRESH_INTERVAL'] ?? 300),

    // Admin
    'admin_api_key' => $_ENV['ADMIN_API_KEY'] ?? '',
];
