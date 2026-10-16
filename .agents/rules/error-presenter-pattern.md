# Error Presenter & Exception Handling Pattern

## Key Classes
- `App\Libraries\ErrorPresenter` — formats user-facing error pages.
- `App\Libraries\AppExceptionHandler` — global CI4 exception handler (registered in `app/Config/Exceptions.php`).
- Helper: `render_app_error(string $title, string $message, int $httpCode = 500)` — renders the standard error view.

## Rules

### In Controllers (Web)
- Never `echo` raw exception messages or PHP errors to the browser.
- Catch exceptions at the controller boundary and call `render_app_error()`:
```php
try {
    $result = $this->someService->doSomething();
} catch (Throwable $e) {
    log_message('error', '[ControllerName::method] ' . $e->getMessage());
    return render_app_error('Something went wrong', 'Please try again or contact support.', 500);
}
```
- For AJAX endpoints in controllers: return JSON error (do NOT render HTML error view):
```php
} catch (Throwable $e) {
    log_message('error', '[ControllerName::ajaxMethod] ' . $e->getMessage());
    return $this->response->setStatusCode(500)->setJSON(['status' => 'error', 'message' => 'Server error.']);
}
```

### In Services / Libraries
- Services MUST NOT call `render_app_error()` — they return `['ok' => false, ...]` (see service-layer-contract rule).
- Services catch internally and return structured error — never rethrow to controller unless genuinely unrecoverable.

### Logging Standard
```php
log_message('error', '[ClassName::methodName] Error: ' . $e->getMessage() . ' | Context: user_id=' . session('user_id') . ', record_id=' . $id);
```
- Log: class, method, error message, user_id, relevant record IDs.
- Never log: passwords, tokens, AWS keys, raw JWT, full request body with credentials.

### Do NOT
- Never use one-off debug dump pages (raw `var_dump`, `dd()` left in committed code).
- Never show DB error strings, table names, or stack traces in any response (web or API).
- Never rely on CI4 default error page in production — `AppExceptionHandler` overrides it.
