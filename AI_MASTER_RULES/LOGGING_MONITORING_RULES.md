# Logging and Monitoring Rules

## Audit trail
Every state-changing action records an audit entry: who (actor, tenant/account), what (action, module, target), when, from where (IP/user agent where appropriate), and a short description. Use the project's existing audit mechanism instead of creating a new one. Authorization denials and login failures are also recorded.

## Application logs
- Levels: error for failures needing attention, warning for recoverable anomalies, info for notable events, debug off in production.
- Include context: class/method, request or job ID, tenant/account, identifiers (not content).
- Mask or omit: passwords, tokens, API keys, session IDs, authorization headers, full card/bank data, full message bodies, and personal data beyond what is needed.
- Sanitize user-controlled values to prevent log injection (strip newlines/control chars).

## Errors
Catch at boundaries. Send full detail to protected logs; show users a generic message. Never expose traces, SQL or paths in production.

## Retention
Define retention and cleanup for logs and audit data; protect logs from tampering and from public web access. Restrict who can read them.

## Monitoring and alerting
Surface: repeated auth failures, rate-limit hits, webhook signature failures, queue backlog/failures, payment reconcile mismatches, cron job failures, and unusual cross-tenant access attempts. Cron/worker output goes to a log, with failure notification.

## Health
Provide a safe diagnostic path (CLI commands or protected screens) for support; do not leave open debug/info pages in production.

## Privacy
Log retention and content must comply with the product's privacy commitments; support deletion of personal data in logs where required.
