# AGENTS.md — Entry Point for AI Coding Agents

Read this file first. It tells you what to load and in what order of precedence.

## Precedence (highest wins on conflict)
1. Explicit instruction from the human in the current session (but never to violate a **Security** or **Never Guess** rule without an explicit, written risk acceptance).
2. `PROJECT_CONTEXT.md` — facts and constraints specific to this repository.
3. Repository-native rules already in place (e.g. `CLAUDE.md`, `.agents/rules/`, `.cursor/rules/`). These are stricter-or-equal supplements; do not weaken them.
4. Domain rule files in this directory (`SECURITY_RULES.md`, `DATABASE_RULES.md`, ...).
5. `UNIVERSAL_AI_MASTER_RULES.md` (constitution).

If two rules conflict, follow the stricter one and tell the human.

## Always load
- `UNIVERSAL_AI_MASTER_RULES.md`
- `AI_AGENT_BEHAVIOR_RULES.md`
- `PROJECT_CONTEXT.md`

## Load by task
| Task touches | Also read |
|---|---|
| Auth, sessions, roles, permissions | `SECURITY_RULES.md`, `AUTHORIZATION_RULES.md` |
| Controllers, routes, JSON endpoints | `API_SECURITY_RULES.md`, `INPUT_VALIDATION_RULES.md` |
| Schema, queries, migrations | `DATABASE_RULES.md` |
| Views, JS, CSS | `FRONTEND_SECURITY_RULES.md` |
| Uploads, downloads, media | `FILE_UPLOAD_SECURITY_RULES.md` |
| External APIs, webhooks, payments | `THIRD_PARTY_INTEGRATION_RULES.md`, `SECRET_MANAGEMENT_RULES.md` |
| Logging, audit, errors | `LOGGING_MONITORING_RULES.md` |
| Structure, layering, refactors | `ARCHITECTURE_RULES.md`, `CODING_STANDARDS.md` |
| Tests | `TESTING_RULES.md` |
| composer/npm changes | `DEPENDENCY_RULES.md` |
| Commits, branches, PRs | `GIT_RULES.md` |
| Deploy, env, cron, server | `DEVOPS_SECURITY_RULES.md` |
| Speed, queries, caching, batching | `PERFORMANCE_RULES.md` |
| Before declaring done | `SECURITY_REVIEW_CHECKLIST.md` |

## Layout
Rule files are numbered by concern, not by priority. Only `PROJECT_CONTEXT.md` may contain project-specific names, tables, vendors or hosts. Every other file must stay reusable across projects; if you learn a project-specific fact, put it in `PROJECT_CONTEXT.md`.
