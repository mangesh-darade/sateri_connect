# Dependency Rules

## Adding or upgrading
- Do not add a dependency without need and approval. Prefer the standard library or an already-installed package.
- Check: maintenance status, license compatibility, transitive size, known advisories, and whether it requires native extensions or network access at install.
- Pin versions through the lockfile; commit lockfile changes with the manifest change. Do not use unpinned or `dev-master`/`latest` references.
- Install from the official registry only; no unverified forks, URLs or install scripts.
- Do not run install commands that execute unreviewed scripts from unknown packages.

## Upgrading
Upgrade for security fixes promptly. Read the changelog for breaking changes, run the tests, and keep major upgrades in their own change.

## Vulnerability management
Run the ecosystem's audit tool (e.g. `composer audit`, `npm audit`) regularly and in CI. Triage by exploitability; document accepted risks with an expiry.

## Removal
Remove unused packages in a dedicated change. Do not edit `vendor/`/`node_modules/`; patch via upstream, a documented override, or a wrapper.

## Supply chain
Review new transitive dependencies in the lockfile diff. Treat packages that request network, filesystem or process access as high-risk.

## Frontend libs
Self-host pinned versions with integrity hashes where practical.
