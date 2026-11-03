# File Upload and Download Security Rules

## Upload
- Require authentication and the relevant permission before accepting any file.
- Enforce an allowlist of extensions **and** detected MIME type (server-side content sniffing, not the client-provided type). Enforce per-file and per-request size limits.
- Generate the stored filename server-side (random). Never trust the original name, path or extension. Keep the original only as display metadata, encoded on output.
- Disallow executable/script types (server-side scripts, shell, `.htaccess`-style config, HTML/SVG when served from the app origin unless sanitized).
- Re-encode images when possible to strip active content and metadata.
- Scan for malware where the deployment supports it.
- Store outside the web root, or in a location configured to never execute code and to serve with `nosniff` and a fixed `Content-Type`. Verify the web server deny rules exist for the upload directory.
- Namespace storage by tenant/account and keep that scoping on retrieval.

## Download / serving
- Serve through an authorized handler for private files; check ownership at the object level.
- Set `Content-Disposition` and `X-Content-Type-Options: nosniff`. Do not reflect user-controlled filenames into headers unescaped.
- Resolve paths with `realpath` and confirm they stay inside the intended directory (block `../`, symlinks, null bytes).
- Signed, expiring URLs for temporary public access; never permanent public links to private data.

## Imports (CSV/XLSX/JSON)
Limit size, rows and columns; parse in a memory-safe way; validate each row; do not execute or evaluate cell content; neutralize formula injection on export.

## Cleanup
Delete orphaned and temporary files on a schedule. Remove files when their owning record or tenant is deleted.

## Logging
Log who uploaded what and when (ID, size, type) without logging file content.
