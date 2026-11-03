#!/usr/bin/env node
// Sateri Connect MCP connector (stdio, no dependencies) for Claude Desktop / Claude Code / Antigravity / Cursor.
// It only forwards JSON-RPC to the app's /api/v1/mcp endpoint, where all tools live.
// Env: SATERI_BASE_URL (default http://localhost/sateri_connect/public), SATERI_API_KEY (required), SATERI_TENANT_KEY (optional).
import readline from 'node:readline';

const BASE = (process.env.SATERI_BASE_URL || 'http://localhost/sateri_connect/public').replace(/\/$/, '');
const KEY = process.env.SATERI_API_KEY || '';
const TENANT = process.env.SATERI_TENANT_KEY || '';

const send = (m) => process.stdout.write(JSON.stringify(m) + '\n');

async function forward(msg) {
  try {
    const res = await fetch(`${BASE}/api/v1/mcp`, {
      method: 'POST',
      headers: { 'X-API-Key': KEY, ...(TENANT && { 'X-Tenant-Key': TENANT }), Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify(msg),
    });
    if (msg.id === undefined) return; // notification: no reply
    const text = await res.text();
    try { send(JSON.parse(text)); } catch { send({ jsonrpc: '2.0', id: msg.id, error: { code: -32000, message: `HTTP ${res.status}: ${text.slice(0, 200)}` } }); }
  } catch (e) {
    if (msg.id !== undefined) send({ jsonrpc: '2.0', id: msg.id, error: { code: -32000, message: `Request failed: ${e.message}` } });
  }
}

readline.createInterface({ input: process.stdin }).on('line', (line) => {
  if (!line.trim()) return;
  try { forward(JSON.parse(line)); } catch { /* ignore malformed input */ }
});
