# Git Rules

- **Never commit, push, tag, merge, rebase, reset, or force anything unless the human explicitly asks.** Ask before committing.
- Never commit to the protected/main branch directly; work on the current feature/dev branch unless told otherwise.
- Never use `--no-verify`, skip signing, force-push, or run destructive commands (`reset --hard`, `clean -fd`, `checkout --`) without explicit approval.
- Before staging, review `git status` and `git diff`. Stage specific files, not `git add -A`.
- Never commit secrets, real data dumps, local env files, uploads, build artifacts, IDE state, or large binaries. If one is already tracked, report it instead of silently deleting history.
- One logical change per commit. Message: imperative summary line, then a short why. Follow the repository's existing style (e.g. conventional prefixes if used).
- Include any attribution lines the environment requires.
- Do not rewrite shared history. If a secret was committed, tell the human so it can be rotated and history handled deliberately.
- Pull requests: describe what, why, risk, how tested, and rollback; list schema or config changes and required env/cron changes.
- Do not resolve merge conflicts by discarding the other side blindly; understand both.
- Hooks and CI failures are signals: fix the cause.
