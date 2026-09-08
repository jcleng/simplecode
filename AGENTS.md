# AGENTS.md

PHP 8.1+ CLI tool (symfony/console) that lets an LLM drive MCP servers. It is not an opencode plugin; it re-implements a small opencode-like loop. README and code comments are bilingual (Chinese + English) - keep that.

## Run / verify

- Run: ./bin/simplecode (defaults to chat). Subcommands: chat, config [key] [value], tools.
- No test suite, no lint config, no CI. Verification = php -l <file> or manually run the binary.
- Requires composer install (vendor/ is gitignored) - PSR-4 SimpleCode\ maps to src/.

## Runtime prerequisites (needed to actually chat)

The tool only works end-to-end when these are up; editing code does not require them:

- .simplecode.json (repo root, tracked) holds api_key, base_url (OpenAI-compatible chat/completions endpoint), model, mcp_base_url.
- MCP infra: docker-compose.yml runs mcp-proxy (mcp-superassistant-proxy) exposing a streamable-http MCP endpoint at http://localhost:3006/mcp, backed by mcphub on :3008 (config.json).
- Logs: Stream debug logs are written to logs/llm_stream_YYYY-MM-DD.log via Util\Logger. Check this file when SSE parsing or tool call accumulation behaves unexpectedly.

## Architecture quirks (non-obvious)

- All real tools come from the MCP server. ToolRegistry registers zero local tools (src/Tool/ToolRegistry.php:15), so the LLM tool_calls loop in ChatCommand never fires. Actual work happens via the JSONL path below.
- Chat flow: LLM is instructed by a huge system prompt (ChatCommand::SYSTEM_PROMPT) to reply with SuperAssistant-style JSONL inside triple-backtick jsonl fences. processMcpJsonl extracts the fence, MCP\Client::convertToMCP rewrites it as MCP JSON-RPC tools/call, and MCP\Client::send posts it.
- MCP\Client::send only sends the first line of the converted JSON-RPC (temporarily simplified, TODO batch).
- JSONL Parsing Robustness: MCP\Client::splitJsonRows uses brace-depth tracking with string-escape awareness to split JSONL. It tolerates pretty-printed or multi-line JSON objects, not just strict one-line-per-object format.
- Type Normalization: MCP\Client::normalizeType maps common LLM typos/variations (e.g., functioncall_start, callstart, params) to canonical types (function_call_start, parameter). Always preserve this when adding new JSONL types.
- SSE Everywhere: Both LLM\Client::chatStream and MCP\Client use SSE parsing. LLM side accumulates tool_calls deltas by index; MCP side uses parseSSEStream to extract result.content[].text from tool responses.
- Tool Example Generation: MCP\Client::formatExample recursively generates sample values for nested array/object/enum parameters. If you add new MCP tools with complex schemas, verify the generated examples in the system prompt are valid JSONL.