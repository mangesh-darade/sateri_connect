# 3rd Party API Call Pattern

All external API calls (Cheerio, Meta Cloud API, Amazon SES, SendGrid, SMTP) MUST follow this pattern:

## Standard Pattern
```php
try {
    $res = $this->apiClient->someCall($params);
    if (! $res['ok']) {
        log_message('error', '[ServiceName] API call failed: ' . ($res['error'] ?? 'unknown') . ' | params: ' . json_encode($params));
        return ['ok' => false, 'status' => 502, 'message' => 'External service error. Please try again.'];
    }
    return ['ok' => true, 'status' => 200, 'message' => 'Success.', 'data' => $res['decoded']];
} catch (Throwable $e) {
    log_message('error', '[ServiceName] Exception: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    return ['ok' => false, 'status' => 502, 'message' => 'Service unavailable. Please try again.'];
}
```

## Rules
- Always wrap in try-catch with `Throwable`.
- `log_message('error', ...)` with: class name, method, key params (mask secrets), error text.
- **Never** expose raw API error text, AWS error codes, or stack traces to the frontend/response.
- Return generic `'Service unavailable'` to caller. Internal detail goes to logs only.
- Never log API keys, tokens, passwords, or raw JWT. Log only `user_id`, `record_id`, domain, endpoint.
- Set a timeout on all HTTP calls (curl/Guzzle): max 15s for Meta/Cheerio, 30s for SES identity ops.
- Rate-limited response (429) from provider → return `['ok' => false, 'status' => 429, 'message' => 'Rate limit reached. Try again in a moment.']`.
- 401/403 from provider → log credential mismatch, return 502 (never expose auth detail to frontend).

## Existing API Clients (use these, don't create new ones)
- Meta/WhatsApp → `App\Libraries\MetaCloudAPI` / `CheerioDirectAPI`
- Amazon SES → `App\Libraries\Email\SesEmailDriver`
- Cheerio contact sync → `App\Libraries\CheerioSyncService`
- Email send → `App\Libraries\EmailProvider` (facade over all drivers)
