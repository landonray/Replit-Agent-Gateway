<?php

declare(strict_types=1);

namespace AgentGateway\Service;

use AgentGateway\Logger;

class SchemaValidationService
{
    /** @var array<string, array{name: string, description: string, inputSchema: array, category?: string}> */
    private array $toolManifest = [];
    private float $lastRefresh = 0;
    private int $refreshInterval;

    public function __construct(int $refreshIntervalSeconds = 300)
    {
        $this->refreshInterval = $refreshIntervalSeconds;
    }

    /**
     * Load or refresh the tool manifest from the MCP server.
     *
     * @param list<array{name: string, description: string, inputSchema: array, category?: string}> $tools
     */
    public function loadManifest(array $tools): void
    {
        $this->toolManifest = [];
        foreach ($tools as $tool) {
            $name = $tool['name'] ?? '';
            if ($name !== '') {
                $this->toolManifest[$name] = $tool;
            }
        }
        $this->lastRefresh = microtime(true);
    }

    /**
     * Whether the cached manifest needs refreshing.
     */
    public function needsRefresh(): bool
    {
        return (microtime(true) - $this->lastRefresh) > $this->refreshInterval;
    }

    /**
     * Check if a tool name exists in the manifest. Returns true if unknown
     * (triggering an on-demand refresh).
     */
    public function isToolUnknown(string $toolName): bool
    {
        return !isset($this->toolManifest[$toolName]);
    }

    /**
     * Get the tool definition from the manifest.
     */
    public function getToolDefinition(string $toolName): ?array
    {
        return $this->toolManifest[$toolName] ?? null;
    }

    /**
     * Get the category of a tool. Returns 'write' or 'read'.
     * Falls back to heuristic if no category in manifest.
     */
    public function getToolCategory(string $toolName): string
    {
        $tool = $this->toolManifest[$toolName] ?? null;

        if ($tool !== null && isset($tool['category'])) {
            return $tool['category'];
        }

        // Heuristic fallback: classify based on tool name prefixes
        $readPrefixes = ['get_', 'list_', 'search_', 'find_', 'count_', 'fetch_', 'lookup_', 'retrieve_', 'check_'];
        $lowerName = strtolower($toolName);

        foreach ($readPrefixes as $prefix) {
            if (str_starts_with($lowerName, $prefix)) {
                return 'read';
            }
        }

        return 'write';
    }

    /**
     * Check if a tool is a communication tool (email, SMS, invoice sending).
     */
    public function isCommunicationTool(string $toolName): bool
    {
        $keywords = ['send_email', 'send_sms', 'send_message', 'resend_invoice', 'send_invoice'];
        $lowerName = strtolower($toolName);

        foreach ($keywords as $keyword) {
            if (str_contains($lowerName, $keyword)) {
                return true;
            }
        }

        $tool = $this->toolManifest[$toolName] ?? null;
        if ($tool !== null && isset($tool['subcategory'])) {
            return in_array($tool['subcategory'], ['communication', 'email', 'sms'], true);
        }

        return false;
    }

    /**
     * Check if a tool is a financial tool (charges, refunds, etc.).
     */
    public function isFinancialTool(string $toolName): bool
    {
        $keywords = ['charge', 'refund', 'void', 'payment', 'invoice_create'];
        $lowerName = strtolower($toolName);

        foreach ($keywords as $keyword) {
            if (str_contains($lowerName, $keyword)) {
                return true;
            }
        }

        $tool = $this->toolManifest[$toolName] ?? null;
        if ($tool !== null && isset($tool['subcategory'])) {
            return in_array($tool['subcategory'], ['financial', 'billing'], true);
        }

        return false;
    }

    /**
     * Validate a tool call against the schema.
     * Returns null on success, or an error message on failure.
     */
    public function validate(string $toolName, array $params): ?string
    {
        // 1. Tool name exists
        if (!isset($this->toolManifest[$toolName])) {
            Logger::get()->warning('Schema validation: unknown tool', ['tool' => $toolName]);
            return "Unknown tool '{$toolName}'. This tool does not exist. Available tools: "
                . implode(', ', array_keys($this->toolManifest));
        }

        $tool = $this->toolManifest[$toolName];
        $schema = $tool['inputSchema'] ?? ['type' => 'object', 'properties' => []];

        // 2. Required parameters present
        $required = $schema['required'] ?? [];
        if (is_array($required)) {
            foreach ($required as $field) {
                if (!array_key_exists($field, $params)) {
                    return "Missing required parameter '{$field}' for tool '{$toolName}'.";
                }
            }
        }

        $properties = $schema['properties'] ?? [];
        if (!is_array($properties)) {
            $properties = [];
        }

        // 3. Parameter types match + 4. Enum values valid
        foreach ($params as $key => $value) {
            if (!isset($properties[$key])) {
                // 5. Strip extra parameters silently — don't reject
                continue;
            }

            $propSchema = $properties[$key];
            $typeError = $this->validateType($key, $value, $propSchema);
            if ($typeError !== null) {
                return $typeError . " (tool: '{$toolName}')";
            }
        }

        return null;
    }

    /**
     * Strip parameters not in the schema from a tool call.
     * Returns cleaned parameters.
     */
    public function stripExtraParams(string $toolName, array $params): array
    {
        $tool = $this->toolManifest[$toolName] ?? null;
        if ($tool === null) {
            return $params;
        }

        $schema = $tool['inputSchema'] ?? ['type' => 'object', 'properties' => []];
        $properties = $schema['properties'] ?? [];

        if (!is_array($properties) || empty($properties)) {
            return $params;
        }

        $validKeys = array_keys($properties);
        return array_intersect_key($params, array_flip($validKeys));
    }

    private function validateType(string $key, mixed $value, array $propSchema): ?string
    {
        $expectedType = $propSchema['type'] ?? null;

        if ($expectedType !== null) {
            $actualType = $this->getJsonType($value);

            // Allow numeric strings for integer/number types
            if (($expectedType === 'integer' || $expectedType === 'number') && is_string($value) && is_numeric($value)) {
                // Acceptable — Claude sometimes sends numbers as strings
            } elseif ($expectedType === 'string' && ($actualType === 'integer' || $actualType === 'number')) {
                // Acceptable — numeric values for string fields
            } elseif ($expectedType !== $actualType && $actualType !== 'null') {
                return "Parameter '{$key}' has type '{$actualType}' but expected '{$expectedType}'";
            }
        }

        // Enum validation
        if (isset($propSchema['enum']) && is_array($propSchema['enum'])) {
            if (!in_array($value, $propSchema['enum'], true)) {
                $allowed = implode(', ', array_map(fn($v) => "'{$v}'", $propSchema['enum']));
                return "Parameter '{$key}' value is not allowed. Valid values: {$allowed}";
            }
        }

        return null;
    }

    private function getJsonType(mixed $value): string
    {
        if (is_null($value)) return 'null';
        if (is_bool($value)) return 'boolean';
        if (is_int($value)) return 'integer';
        if (is_float($value)) return 'number';
        if (is_string($value)) return 'string';
        if (is_array($value)) {
            return array_is_list($value) ? 'array' : 'object';
        }
        return 'unknown';
    }
}
