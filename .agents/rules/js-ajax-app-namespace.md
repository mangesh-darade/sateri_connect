# JS / AJAX APP Namespace Standard

All JavaScript in this project uses the `APP.*` global namespace defined in `public/assets/js/`.

## Rules

### AJAX Calls
- Always use `$.ajax()` — never `fetch()` or raw XMLHttpRequest.
- Every AJAX call MUST have: `beforeSend` (disable button/show loader) + `complete` (re-enable/hide loader) + `error` callback.
- Use `APP.csrf()` or pass the CSRF token from the meta tag — never hardcode it.
- Check `response.status === 'success'` (not HTTP status) for app-level result.
- On 401 response → redirect to login (handled globally via `$.ajaxSetup` in base layout — do not re-implement per page).

### Toast / Feedback
- Use `APP.toast('message', 'success|error|warning|info')` — never `alert()`, `console.log` visible to user, or raw Bootstrap alert clone per page.

### Inline JS
- No inline `<script>` blocks with business logic in views. Put reusable helpers in `public/assets/js/`.
- Page-specific JS goes in `<?= $this->section('scripts') ?>` block at view bottom.
- Never redeclare `APP.*` functions per page — extend or call existing helpers.

### Standard AJAX Template
```js
$.ajax({
    url: BASE_URL + 'module/action',
    method: 'POST',
    data: { <?= csrf_token() ?>: <?= csrf_hash() ?>, field: value },
    beforeSend: function() { btn.prop('disabled', true); },
    success: function(res) {
        if (res.status === 'success') {
            APP.toast(res.message, 'success');
        } else {
            APP.toast(res.message || 'Something went wrong.', 'error');
        }
    },
    error: function(xhr) {
        APP.toast('Request failed. Please try again.', 'error');
    },
    complete: function() { btn.prop('disabled', false); }
});
```
