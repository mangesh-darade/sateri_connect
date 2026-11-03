# Coding Standards

## Match the codebase
Before writing, read two or three neighboring files. Match their naming, comment density, formatting, error contract and idioms. Do not introduce a new pattern when an existing one fits.

## General
- One responsibility per function/class. Prefer small, named, testable units.
- Reuse existing helpers/services before writing new ones (search first).
- Explicit over clever. No dead code, commented-out blocks, or unused parameters left behind.
- Type declarations and return types wherever the language supports them and the file already uses them.
- No magic numbers or strings repeated across files; use constants/config.
- No global mutable state added without a strong reason.

## Naming
Names reveal intent. Booleans read as questions (`isActive`). No abbreviations that are not already used in the codebase.

## Comments
Comment the *why* (constraints, non-obvious decisions, security reasons), not the *what*. Do not add banner comments or restate code. Keep the repository's existing comment density.

## Errors
Use the repository's error contract. Do not swallow exceptions silently; catch at boundaries, log with context (masked), return a safe message. Never use errors for normal control flow.

## Configuration
Read configuration through the project's config layer. Do not read raw environment/server globals deep in business code.

## Changes
- Keep diffs minimal and focused; no unrelated formatting churn.
- Schema, model, validation, docs and tests move together.
- Remove only what your change made unused.

## UI text and i18n
Follow the repository's language policy for user-facing strings and keep them out of logic.

## Review readiness
Self-review the diff before presenting it: no debug output, no stray TODOs, no secrets, no accidental file changes.
