#!/bin/bash
# Call one MCP method. Usage: tests/mcp-call.sh URL TOKEN METHOD [PARAMS_JSON] [modern]
# "modern" sends protocol 2026-07-28 (_meta + Mcp-Method/Mcp-Name headers), otherwise 2025-06-18.
# mcp.sh URL TOKEN METHOD PARAMS_JSON [modern]
U="$1"; T="$2"; M="$3"; P="${4:-{\}}"; MODERN="$5"
if [ -n "$MODERN" ]; then
  NAME=$(echo "$P" | python3 -c 'import sys,json; d=json.load(sys.stdin); print(d.get("name", d.get("uri","")))')
  P=$(echo "$P" | python3 -c 'import sys,json; d=json.load(sys.stdin); d["_meta"]={"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{},"io.modelcontextprotocol/clientInfo":{"name":"modern-test","version":"1"}}; print(json.dumps(d))')
  curl -s -X POST "$U" -H "Authorization: Bearer $T" -H 'Content-Type: application/json' -H 'MCP-Protocol-Version: 2026-07-28' -H "Mcp-Method: $M" ${NAME:+-H "Mcp-Name: $NAME"} -d "{\"jsonrpc\":\"2.0\",\"id\":7,\"method\":\"$M\",\"params\":$P}"
else
  curl -s -X POST "$U" -H "Authorization: Bearer $T" -H 'Content-Type: application/json' -H 'MCP-Protocol-Version: 2025-06-18' -d "{\"jsonrpc\":\"2.0\",\"id\":7,\"method\":\"$M\",\"params\":$P}"
fi
