# Workspace

## Overview

pnpm workspace monorepo using TypeScript, plus a standalone PHP Agent Gateway service.

## Stack

- **Monorepo tool**: pnpm workspaces
- **Node.js version**: 24
- **Package manager**: pnpm
- **TypeScript version**: 5.9
- **API framework**: Express 5
- **Database**: PostgreSQL + Drizzle ORM
- **Validation**: Zod (`zod/v4`), `drizzle-zod`
- **API codegen**: Orval (from OpenAPI spec)
- **Build**: esbuild (CJS bundle)
- **PHP**: 8.2 (Agent Gateway)

## Structure

```text
artifacts-monorepo/
├── artifacts/              # Deployable applications
│   └── api-server/         # Express API server
├── agent-gateway/          # PHP Agent Gateway (Ontraport + Claude via LLM Gateway)
│   ├── public/index.php    # Entry point (PHP built-in server document root)
│   ├── config/config.php   # Configuration from env vars
│   ├── src/
│   │   ├── Controller/AgentController.php
│   │   ├── Middleware/AuthMiddleware.php
│   │   ├── Middleware/RateLimitMiddleware.php
│   │   ├── Middleware/ValidationMiddleware.php
│   │   ├── Service/LlmGatewayService.php   # LLM Gateway integration (OpenAI-compatible)
│   │   ├── Service/McpClient.php           # MCP server client (tool discovery + execution)
│   │   ├── Service/ConversationService.php # Redis conversation history
│   │   ├── Service/SystemPromptService.php # System prompt builder
│   │   └── Logger.php
│   └── composer.json
├── lib/                    # Shared libraries
│   ├── api-spec/           # OpenAPI spec + Orval codegen config
│   ├── api-client-react/   # Generated React Query hooks
│   ├── api-zod/            # Generated Zod schemas from OpenAPI
│   └── db/                 # Drizzle ORM schema + DB connection
├── scripts/                # Utility scripts (single workspace package)
├── pnpm-workspace.yaml
├── tsconfig.base.json
├── tsconfig.json
└── package.json
```

## Agent Gateway

PHP 8.2 API that connects Ontraport users to Claude via the LLM Gateway. Users send natural language requests and the gateway orchestrates Claude + Ontraport MCP tools.

### Architecture

```
User → Agent Gateway → LLM Gateway → Claude
                    ↕
              Ontraport MCP Server (tool discovery + execution)
```

### Endpoint

`POST /api/v1/agent` — Main agent endpoint

Headers:
- `Api-Key`: Ontraport API key
- `Api-Appid`: Ontraport App ID

Body: `{ "message": "...", "conversation_id": "optional", "context": {}, "model": "optional" }`

`GET /health` — Health check

### Environment Variables (Secrets)

- `LLM_GATEWAY_API_KEY` — Bearer token for the LLM Gateway at llm-gateway.replit.app
- `MCP_SERVER_URL` — Ontraport MCP server URL (e.g. https://opmcp.replit.app/)
- `REDIS_URL` — Redis connection for conversation history and rate limiting

### Running

The Agent Gateway runs via PHP's built-in server on port 5000:
```bash
cd agent-gateway && php -S 0.0.0.0:5000 -t public
```

### Key Design Decisions

- Uses LLM Gateway (llm-gateway.replit.app) instead of direct Anthropic API
- OpenAI-compatible request/response format routed through LLM Gateway
- MCP tools discovered per-request via MCP Streamable HTTP protocol (JSON-RPC)
- Tools converted to OpenAI function-calling format for LLM Gateway
- Tool execution loop handles multi-step agentic workflows (up to 20 iterations)
- Rate limiting and conversation history stored in Redis

## TypeScript & Composite Projects

Every package extends `tsconfig.base.json` which sets `composite: true`. The root `tsconfig.json` lists all packages as project references.

- **Always typecheck from the root** — run `pnpm run typecheck`
- **`emitDeclarationOnly`** — only `.d.ts` files during typecheck; JS bundling handled by esbuild/tsx/vite
- **Project references** — when package A depends on package B, A's `tsconfig.json` must list B in its `references` array

## Root Scripts

- `pnpm run build` — runs `typecheck` first, then recursively runs `build` in all packages that define it
- `pnpm run typecheck` — runs `tsc --build --emitDeclarationOnly` using project references

## Packages

### `artifacts/api-server` (`@workspace/api-server`)

Express 5 API server. Routes live in `src/routes/`.

### `lib/db` (`@workspace/db`)

Database layer using Drizzle ORM with PostgreSQL.

### `lib/api-spec` (`@workspace/api-spec`)

Owns the OpenAPI 3.1 spec and Orval codegen config.

### `lib/api-zod` (`@workspace/api-zod`)

Generated Zod schemas from the OpenAPI spec.

### `lib/api-client-react` (`@workspace/api-client-react`)

Generated React Query hooks and fetch client from the OpenAPI spec.

### `scripts` (`@workspace/scripts`)

Utility scripts package.
