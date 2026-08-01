<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use Throwable;

/**
 * Maps exceptions to a friendly, reusable error-screen payload.
 * Use from AppExceptionHandler, controllers, or views — never dump raw CI debug UI.
 */
class ErrorPresenter
{
    /**
     * @return array{
     *     code: int,
     *     kind: string,
     *     title: string,
     *     headline: string,
     *     message: string,
     *     hint: string,
     *     icon: string,
     *     exception_class: string,
     *     technical: string,
     *     file: string,
     *     line: int,
     *     show_details: bool,
     *     home_url: string,
     *     actions: list<array{label: string, url: string, primary?: bool}>
     * }
     */
    public static function present(Throwable $exception, int $statusCode = 500): array
    {
        $kind = self::classify($exception);
        $copy = self::copyForKind($kind, $exception, $statusCode);
        $showDetails = \defined('ENVIRONMENT') && ENVIRONMENT !== 'production';

        return [
            'code'             => $statusCode > 0 ? $statusCode : 500,
            'kind'             => $kind,
            'title'            => $copy['title'],
            'headline'         => $copy['headline'],
            'message'          => $copy['message'],
            'hint'             => $copy['hint'],
            'icon'             => $copy['icon'],
            'exception_class'  => $exception::class,
            'technical'        => self::technicalSummary($exception),
            'file'             => $exception->getFile(),
            'line'             => $exception->getLine(),
            'show_details'     => $showDetails,
            'home_url'         => self::safeHomeUrl(),
            'actions'          => $copy['actions'],
        ];
    }

    /**
     * Manual / controller-driven error screen (no Throwable).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function manual(array $overrides = [], int $statusCode = 500): array
    {
        $base = [
            'code'             => $statusCode,
            'kind'             => (string) ($overrides['kind'] ?? 'generic'),
            'title'            => (string) ($overrides['title'] ?? 'Something went wrong'),
            'headline'         => (string) ($overrides['headline'] ?? 'We hit a snag'),
            'message'          => (string) ($overrides['message'] ?? 'Please try again in a moment. If it keeps happening, contact your administrator.'),
            'hint'             => (string) ($overrides['hint'] ?? ''),
            'icon'             => (string) ($overrides['icon'] ?? 'fa-triangle-exclamation'),
            'exception_class'  => (string) ($overrides['exception_class'] ?? ''),
            'technical'        => (string) ($overrides['technical'] ?? ''),
            'file'             => (string) ($overrides['file'] ?? ''),
            'line'             => (int) ($overrides['line'] ?? 0),
            'show_details'     => (bool) ($overrides['show_details'] ?? (\defined('ENVIRONMENT') && ENVIRONMENT !== 'production')),
            'home_url'         => (string) ($overrides['home_url'] ?? self::safeHomeUrl()),
            'actions'          => $overrides['actions'] ?? [
                ['label' => 'Go home', 'url' => self::safeHomeUrl(), 'primary' => true],
                ['label' => 'Try again', 'url' => 'javascript:location.reload()', 'primary' => false],
            ],
        ];

        return array_merge($base, array_intersect_key($overrides, $base));
    }

    public static function classify(Throwable $exception): string
    {
        if ($exception instanceof PageNotFoundException) {
            return 'not_found';
        }

        if ($exception instanceof DatabaseException || self::looksLikeDatabaseError($exception)) {
            return 'database';
        }

        if ($exception instanceof HTTPException) {
            return 'http';
        }

        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'permission') || str_contains($message, 'forbidden') || str_contains($message, 'unauthorized')) {
            return 'permission';
        }

        return 'generic';
    }

    /**
     * @return array{title: string, headline: string, message: string, hint: string, icon: string, actions: list<array{label: string, url: string, primary?: bool}>}
     */
    protected static function copyForKind(string $kind, Throwable $exception, int $statusCode): array
    {
        $home = self::safeHomeUrl();

        return match ($kind) {
            'database' => [
                'title'    => 'Database unavailable',
                'headline' => 'Unable to connect to the database',
                'message'  => self::databaseMessage($exception),
                'hint'     => 'Confirm the database exists in MySQL/MariaDB, then check hostname, username, password, and the subdomain mapping in Config\\Database.',
                'icon'     => 'fa-database',
                'actions'  => [
                    ['label' => 'Go home', 'url' => $home, 'primary' => true],
                    ['label' => 'Open settings', 'url' => self::safeUrl('settings'), 'primary' => false],
                    ['label' => 'Try again', 'url' => 'javascript:location.reload()', 'primary' => false],
                ],
            ],
            'not_found' => [
                'title'    => 'Page not found',
                'headline' => '404 — Page not found',
                'message'  => 'The page you requested could not be found. It may have been moved or the link is incorrect.',
                'hint'     => '',
                'icon'     => 'fa-compass',
                'actions'  => [
                    ['label' => 'Go home', 'url' => $home, 'primary' => true],
                ],
            ],
            'permission' => [
                'title'    => 'Access denied',
                'headline' => 'You do not have access',
                'message'  => 'Your account does not have permission for this action. Ask an administrator if you need access.',
                'hint'     => '',
                'icon'     => 'fa-lock',
                'actions'  => [
                    ['label' => 'Go home', 'url' => $home, 'primary' => true],
                ],
            ],
            'http' => [
                'title'    => 'Request error',
                'headline' => 'The request could not be completed',
                'message'  => $statusCode >= 500
                    ? 'The server could not process this request right now.'
                    : 'Something was wrong with this request. Please go back and try again.',
                'hint'     => '',
                'icon'     => 'fa-globe',
                'actions'  => [
                    ['label' => 'Go home', 'url' => $home, 'primary' => true],
                    ['label' => 'Try again', 'url' => 'javascript:location.reload()', 'primary' => false],
                ],
            ],
            default => [
                'title'    => 'Something went wrong',
                'headline' => 'We hit a snag',
                'message'  => 'An unexpected error stopped this page from loading. Please try again in a moment.',
                'hint'     => 'If this keeps happening, share the reference details below with your administrator.',
                'icon'     => 'fa-triangle-exclamation',
                'actions'  => [
                    ['label' => 'Go home', 'url' => $home, 'primary' => true],
                    ['label' => 'Try again', 'url' => 'javascript:location.reload()', 'primary' => false],
                ],
            ],
        };
    }

    protected static function databaseMessage(Throwable $exception): string
    {
        $raw = $exception->getMessage();
        $dbName = null;

        if (preg_match("/Unknown database ['`]([^'`]+)['`]/i", $raw, $m)) {
            $dbName = $m[1];
        }

        if ($dbName !== null) {
            return 'The database "' . $dbName . '" was not found on the server. Create it, or update the tenant database name for this subdomain.';
        }

        if (stripos($raw, 'access denied') !== false) {
            return 'Database login failed. The username or password for this tenant connection is incorrect.';
        }

        if (stripos($raw, 'refused') !== false || stripos($raw, 'timed out') !== false) {
            return 'The database server is not reachable. Check that MySQL/MariaDB is running and the hostname/port are correct.';
        }

        // An unmapped subdomain leaves the credentials blank, which otherwise
        // surfaces as a generic connection failure on every screen.
        if (! SubdomainDatabase::isTenantConfigured()) {
            return 'This site is running on the subdomain "' . SubdomainDatabase::resolve()
                . '", which has no database mapped yet. Add a case for it in Config\\Database::applyBySubdomain().';
        }

        return 'The application could not open a database connection. Check that the database exists and credentials are correct.';
    }

    protected static function looksLikeDatabaseError(Throwable $exception): bool
    {
        $haystack = strtolower($exception::class . ' ' . $exception->getMessage());

        return str_contains($haystack, 'database')
            || str_contains($haystack, 'mysqli')
            || str_contains($haystack, 'sqlstate')
            || str_contains($haystack, 'unknown database');
    }

    protected static function technicalSummary(Throwable $exception): string
    {
        $parts = [
            $exception::class,
            trim($exception->getMessage()),
        ];

        $file = $exception->getFile();
        $line = $exception->getLine();
        if ($file !== '') {
            $parts[] = basename($file) . ':' . $line;
        }

        return implode("\n", array_filter($parts));
    }

    protected static function safeHomeUrl(): string
    {
        return self::safeUrl('dashboard');
    }

    protected static function safeUrl(string $path): string
    {
        try {
            if (function_exists('site_url')) {
                return site_url($path);
            }
        } catch (Throwable) {
            // Fall through — error pages must not throw again.
        }

        $base = '/';
        try {
            if (function_exists('base_url')) {
                $base = rtrim((string) base_url(), '/') . '/';
            }
        } catch (Throwable) {
            $base = '/';
        }

        return $base . ltrim($path, '/');
    }
}
