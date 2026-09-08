# AGENTS.md

PHP 8.1+ CLI tool (`symfony/console`) that lets an LLM drive MCP servers. It is **not** an opencode plugin; it re-implements a small opencode-like loop. README and code comments are bilingual (中文 + English) — keep that.

## Run / verify

- Run: `./bin/simplecode` (defaults to `chat`). Subcommands: `chat`, `config [key] [value]`, `tools`.
- No test suite, no lint config, no CI. Verification = `php -l <file>` or manually run the binary.
- Requires `composer install` (vendor/ is gitignored) — PSR-4 `SimpleCode\` → `src/`.

## Runtime prerequisites (needed to actually chat)

The tool only works end-to-end when these are up; editing code does not require them:

- `.simplecode.json` (repo root, tracked) holds `api_key`, `base_url` (OpenAI-compatible chat/completions endpoint), `model`, `mcp_base_url`.
- MCP infra: `docker-compose.yml` runs `mcp-proxy` (mcp-superassistant-proxy) exposing a streamable-http MCP endpoint at `http://localhost:3006/mcp`, backed by mcphub on `:3008` (`config.json`).

## Architecture quirks (non-obvious)

- All real tools come from the **MCP server**. `ToolRegistry` registers zero local tools (`src/Tool/ToolRegistry.php:15`), so the LLM `tool_calls` loop in ChatCommand never fires — actual work happens via the JSONL path below.
- Chat flow: LLM is instructed by a huge system prompt (`ChatCommand::SYSTEM_PROMPT`) to reply with SuperAssistant-style JSONL inside ` ```jsonl ` fences. `processMcpJsonl` extracts the fence, `MCP\Client::convertToMCP` rewrites it as MCP JSON-RPC `tools/call`, and `MCP\Client::send` posts it.
- `MCP\Client::send` only sends the **first line** of the JSONL ("暂时简化", TODO batch).

## Config gotcha

`Util\Config` resolves the config file via a hardcoded path (`__DIR__.'/../../.simplecode.json'`), not the cwd. `simplecode config set <k> <v>` rewrites that file with `json_encode(JSON_PRETTY_PRINT)` (formatting/key order lost). `api_key` falls back to env `OPENCODE_API_KEY`.