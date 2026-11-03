# Secret Management Rules

## Never
- Commit secrets: API keys, tokens, passwords, private keys, DB credentials, encryption keys, webhook secrets, `.env` files with real values, database dumps with real data.
- Hardcode secrets or default fallbacks like `'changeme'` in code.
- Print, log, return in an API/UI response, put in a URL/query string, or paste into prompts/issues/PRs any secret or real personal data.
- Reuse the same secret across environments or tenants.

## Do
- Load secrets from environment/secret store through the project's configuration layer; commit only a placeholder example file.
- Encrypt secrets that must be stored (e.g. per-tenant provider tokens) with a managed key; display them masked (never partial prefix/suffix leaks).
- Fail startup/closed when a required secret is missing in production; never generate a weak fallback.
- Use long random values (≥ 32 bytes from a CSPRNG) for signing and encryption keys.
- Scope credentials to least privilege (read-only where possible, per-service DB users).
- Provide a documented rotation procedure and rotate immediately after any suspected exposure.

## Repository hygiene
- `.gitignore` covers env files, key files, dumps, uploads, logs and local tooling state.
- Before committing, scan the diff for secrets. If one was committed, treat it as compromised: rotate it, then clean history only with human approval.
- Test data must be synthetic.

## Agent behavior
If you encounter a secret in the repo or logs, do not repeat it in your output. Report the file and line, say it should be rotated, and move on.
