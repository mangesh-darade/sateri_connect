# Filter / Auth Pattern

## Available Filters (`app/Filters/`)
| Filter | Purpose | Applied via |
|---|---|---|
| `AuthFilter` | Session login check, redirect to `/login` | `$routes->group(..., ['filter' => 'auth'])` |
| `PermissionFilter` | RBAC permission gate | Route group or per-route |
| `ApiAuthFilter` | Bearer token / X-API-Key + tenant resolution | All `/api/v1/*` routes |
| `CsrfFilter` | CSRF validation on POST | Auto on all non-API POST |
| `RateLimitFilter` | IP-based attempt throttle | Login, OTP, public endpoints |
| `PlatformAuthFilter` | Platform-level admin auth | Platform/admin routes |
| `InstallFilter` | Redirect to install if not set up | App bootstrap |

## Rules
- Every protected web controller route MUST be inside an `auth` filter group — never check `session()->get('user_id')` manually in the controller.
- Permission check in controller: use `can('permission.slug')` helper — never raw DB query for permissions.
- New API endpoint → must be under `/api/v1/` prefix with `ApiAuthFilter` applied in `Routes.php`. Never create an API endpoint without auth.
- Never put `RateLimitFilter` logic inside a controller — apply it at the route level.
- Webhook routes (`/webhooks/*`) → use `WebhookValidator` HMAC check at the top of the controller method, NOT a filter (payload must be read raw before CI4 parses it).
- `ApiAuthFilter` already resolves the tenant — do not re-resolve in API controllers.

## Permission Gate Usage
```php
// In controller method:
if (! can('emails.send')) {
    return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'Forbidden.']);
}
```

## New Route Registration Pattern
```php
// Web (protected)
$routes->group('module', ['filter' => 'auth', 'namespace' => 'App\Controllers'], function($routes) {
    $routes->get('/', 'MyController::index');
    $routes->post('store', 'MyController::store');
});

// API
$routes->group('api/v1/module', ['filter' => 'api-auth', 'namespace' => 'App\Controllers\Api'], function($routes) {
    $routes->get('/', 'MyApiController::index');
});
```
