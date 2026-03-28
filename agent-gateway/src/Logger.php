<?php

declare(strict_types=1);

namespace AgentGateway;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\JsonFormatter;

class Logger
{
    private static ?MonologLogger $instance = null;

    public static function get(): MonologLogger
    {
        if (self::$instance === null) {
            self::$instance = new MonologLogger('agent-gateway');
            $handler = new StreamHandler('php://stdout', MonologLogger::INFO);
            $handler->setFormatter(new JsonFormatter());
            self::$instance->pushHandler($handler);
        }

        return self::$instance;
    }
}
