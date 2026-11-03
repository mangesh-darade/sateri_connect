# Core Rules (always load; everything else is on demand)
1. Security first: authenticate, authorize (role + tenant/owner + object), validate input, escape output, bind SQL, CSRF on state changes.
2. Never guess: no invented APIs, columns, config, env vars or business rules. Read the code or ask one short question.
3. No secrets in code, logs, chat or commits. TLS verification stays on.
4. Smallest change that works; match surrounding code; no unrelated refactors.
5. Errors: generic message to the user, details to the server log, never raw provider/SQL errors.
6. Verify before "done": run one relevant test/check and report real results.
7. Destructive or outward-facing action (delete, send, publish, commit, migrate): ask first.
8. Token discipline: read only needed lines (grep/ranges), edit in place, reply in 1-3 lines, no recap.
Details per topic: see the load-by-task table in `AGENTS.md`; open a file only when the task touches it.
