# Service Layer Return Contract

Every `app/Libraries/*Service.php` method that performs an operation MUST return this exact shape:

```php
return ['ok' => bool, 'status' => int, 'message' => string];               // minimal
return ['ok' => bool, 'status' => int, 'message' => string, 'data' => []]; // with payload
return ['ok' => bool, 'status' => int, 'message' => string, 'errors' => ['field' => 'reason']]; // validation fail
```

## Rules
- `ok: true` = success, `ok: false` = any failure.
- `status` = HTTP status code (200/201/400/404/422/429/502).
- Validation fail → `ok: false, status: 422, errors: [field => reason]`.
- 3rd-party API fail → `ok: false, status: 502, message: generic`.
- Not found → `ok: false, status: 404`.
- Controller MUST always check `$result['ok']` before using `$result['data']`.
- Never throw exceptions out of a Service into the controller — catch internally, return `ok: false`.
- Services return data arrays; they never call `redirect()`, `view()`, or set flash messages.

## Example — Controller usage
```php
$result = $this->sesService->configure($input);
if (! $result['ok']) {
    return $this->response->setStatusCode($result['status'])->setJSON($result);
}
return $this->response->setJSON($result);
```
