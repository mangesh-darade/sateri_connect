# Input Validation Rules

## Approach
Validate on the server for every entry point (forms, AJAX, API, CLI args, webhooks, imports, uploads, headers, cookies, third-party responses). Client-side checks are UX only.

## Allowlist
Define what is permitted, reject the rest: type, min/max length, charset/format, numeric range, enum membership, required/optional. Normalize (trim, Unicode, case) before validating.

## Per type
- **Identifiers**: integers or fixed-format strings; cast and verify; scope the lookup to the caller.
- **Emails/phones/URLs**: use framework validators; for URLs also restrict scheme and apply SSRF rules.
- **Dates/numbers**: parse strictly; validate ranges and timezones; money as exact decimals/minor units.
- **Free text**: length-limited; stored raw, encoded at output. Do not "sanitize" by stripping as a substitute for output encoding.
- **Rich HTML**: only through an allowlist sanitizer.
- **File names/paths**: never use client-supplied paths; generate names server-side; reject traversal sequences and null bytes.
- **Sort/filter/column params**: map to an allowlist of known columns.
- **JSON/CSV/XLSX imports**: cap rows, size and columns; neutralize spreadsheet formula injection (`=`, `+`, `-`, `@`) on export.

## Mass assignment
Pick fields explicitly; never bind the whole request to a model.

## Errors
Return field-level messages that do not leak internals. Keep validation rules in one reusable place (model/validator), not duplicated in controllers and JS.

## Tests
Cover boundary values, empty, oversized, wrong type, injection strings, and unicode.
