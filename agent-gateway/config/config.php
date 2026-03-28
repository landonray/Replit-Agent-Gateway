<?php

declare(strict_types=1);

return [
    'anthropic_api_key'    => $_ENV['ANTHROPIC_API_KEY'] ?? '',
    'mcp_server_url'       => $_ENV['MCP_SERVER_URL'] ?? '',
    'redis_url'            => $_ENV['REDIS_URL'] ?? 'redis://localhost:6379',
    'port'                 => (int) ($_ENV['PORT'] ?? 3000),
    'app_env'              => $_ENV['APP_ENV'] ?? 'production',

    'rate_limit_per_minute' => (int) ($_ENV['RATE_LIMIT_PER_MINUTE'] ?? 10),
    'rate_limit_per_day'    => (int) ($_ENV['RATE_LIMIT_PER_DAY'] ?? 500),

    'conversation_ttl'          => (int) ($_ENV['CONVERSATION_TTL_SECONDS'] ?? 86400),
    'conversation_max_messages' => (int) ($_ENV['CONVERSATION_MAX_MESSAGES'] ?? 20),

    'default_model' => $_ENV['DEFAULT_MODEL'] ?? 'claude-sonnet-4-20250514',
];
