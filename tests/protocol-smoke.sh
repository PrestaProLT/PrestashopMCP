#!/bin/bash
# Protocol conformance smoke test for the ppmcp endpoint (both MCP eras).
# Usage: tests/protocol-smoke.sh "https://shop/module/ppmcp/server" ppmcp_xxx
U="$1"; T="$2"; DIR="$(cd "$(dirname "$0")" && pwd)"; FAIL=0
check() { if echo "$2" | grep -q "$3"; then echo "ok   $1"; else echo "FAIL $1 :: $(echo "$2" | head -c 300)"; FAIL=1; fi; }
code() { curl -s -o /dev/null -w "%{http_code}" "$@"; }

check "GET → 405"                 "$(code "$U")" "405"
check "no token → 401"            "$(code -X POST "$U" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}')" "401"
check "bad token → 401"           "$(code -X POST "$U" -H 'Authorization: Bearer ppmcp_bad0000000000000000000' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}')" "401"
check "bad origin → 403"          "$(code -X POST "$U" -H "Authorization: Bearer $T" -H 'Origin: https://evil.example' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}')" "403"
check "X-Api-Key accepted"        "$(curl -s -X POST "$U" -H "X-Api-Key: $T" -d '{"jsonrpc":"2.0","id":1,"method":"ping"}')" '"result"'
check "notification → 202"        "$(code -X POST "$U" -H "Authorization: Bearer $T" -d '{"jsonrpc":"2.0","method":"notifications/initialized"}')" "202"
check "parse error"               "$(curl -s -X POST "$U" -H "Authorization: Bearer $T" -d '{oops')" ':-32700'
check "legacy initialize"         "$(curl -s -X POST "$U" -H "Authorization: Bearer $T" -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"smoke","version":"1"}}}')" '"protocolVersion":"2025-11-25"'
check "old version negotiated"    "$(curl -s -X POST "$U" -H "Authorization: Bearer $T" -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{}}}')" '"protocolVersion":"2025-03-26"'
check "batch (2025-03-26)"        "$(curl -s -X POST "$U" -H "Authorization: Bearer $T" -d '[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","id":2,"method":"tools/list"}]')" '"id":2'
check "unknown method -32601"     "$("$DIR/mcp-call.sh" "$U" "$T" nope/nope)" ':-32601'
check "unknown tool -32602"       "$("$DIR/mcp-call.sh" "$U" "$T" tools/call '{"name":"nope_tool_get","arguments":{}}')" ':-32602'
check "tools/list"                "$("$DIR/mcp-call.sh" "$U" "$T" tools/list)" '"mcp_tool_search"'
check "annotations"               "$("$DIR/mcp-call.sh" "$U" "$T" tools/list)" '"readOnlyHint"'
check "bad args → isError"        "$("$DIR/mcp-call.sh" "$U" "$T" tools/call '{"name":"mcp_skill_get","arguments":{}}')" '"isError":true'
check "prompts/list"              "$("$DIR/mcp-call.sh" "$U" "$T" prompts/list)" '"module_analysis"'
check "prompts/get"               "$("$DIR/mcp-call.sh" "$U" "$T" prompts/get '{"name":"module_analysis"}')" '"messages"'
check "resources/read"            "$("$DIR/mcp-call.sh" "$U" "$T" resources/read '{"uri":"ppmcp://skills/module_analysis"}')" '"text/markdown"'
check "modern server/discover"    "$("$DIR/mcp-call.sh" "$U" "$T" server/discover '{}' modern)" '"supportedVersions"'
check "modern tools/list ttlMs"   "$("$DIR/mcp-call.sh" "$U" "$T" tools/list '{}' modern)" '"ttlMs"'
check "modern resultType"         "$("$DIR/mcp-call.sh" "$U" "$T" tools/call '{"name":"mcp_whoami","arguments":{}}' modern)" '"resultType":"complete"'
check "modern header mismatch"    "$(curl -s -X POST "$U" -H "Authorization: Bearer $T" -H 'MCP-Protocol-Version: 2026-07-28' -H 'Mcp-Method: tools/list' -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"mcp_whoami","_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}')" ':-32020'
check "modern unsupported ver"    "$(curl -s -X POST "$U" -H "Authorization: Bearer $T" -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2099-01-01","io.modelcontextprotocol/clientCapabilities":{}}}}')" ':-32022'
exit $FAIL
