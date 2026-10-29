# DevOps Security Rules

## Environments
Strict separation of development, staging and production: distinct credentials, databases, keys and domains. Development conveniences (debug output, unsigned webhooks, relaxed TLS, seeded users) must be impossible to enable accidentally in production, i.e. gated on an explicit environment check and failing closed.

## Server and web root
- Only the public directory is web-accessible. Application code, config, env files, logs, uploads-of-private-data, dumps and archives are not.
- Deny execution of scripts in upload/writable directories. Deny dotfiles and backup extensions.
- Disable directory listing and server version banners. Install lock/installer routes must be disabled after setup.

## Transport
HTTPS everywhere, HTTP → HTTPS redirect, HSTS, modern TLS. Correct trusted-proxy configuration when behind a proxy/CDN (so client IP and scheme are accurate and not spoofable).

## Configuration and secrets
Per `SECRET_MANAGEMENT_RULES.md`. Production env validated at deploy (required keys present, debug off, secure cookies on).

## Least privilege
Separate OS/service accounts; DB user without admin/DDL rights at runtime; file permissions minimal; cron jobs run as the app user.

## CI/CD
Run lint, tests, dependency audit and secret scanning on every change. Protect main branches; require review. Deploy from a clean build, never from a developer machine with local edits. Keep rollback steps documented. CI secrets are scoped and masked.

## Containers (if used)
Non-root user, minimal base image, no secrets baked into images, pinned image digests, read-only filesystem where possible, no privileged mode.

## Scheduled jobs
Document every cron/worker entry (schedule, command, tenant context, owner). Make them idempotent and monitored; overlapping runs must be safe.

## Backups and recovery
Encrypted, tested restores, defined retention, access-controlled. Include the routing/master data if the system has any.

## Operations
Never run commands against production without human approval. Prefer read-only diagnostics first. Keep an incident contact and rotation runbook.
