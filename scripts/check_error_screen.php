<?php

declare(strict_types=1);

// Lightweight smoke check (no full CI boot).
require dirname(__DIR__) . '/app/Libraries/ErrorPresenter.php';

if (! function_exists('esc')) {
    function esc($data, string $context = 'html'): string
    {
        return htmlspecialchars((string) $data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

use App\Libraries\ErrorPresenter;

final class FakeDatabaseException extends Exception
{
}

// Classify via message even without CI DatabaseException class.
$exception = new FakeDatabaseException(
    "Unable to connect to the database.\nMain connection [MySQLi]: Unknown database 'apiwass'"
);

$error = ErrorPresenter::present($exception, 500);

if ($error['kind'] !== 'database') {
    fwrite(STDERR, "FAIL kind={$error['kind']}\n");
    exit(1);
}
if (! str_contains($error['message'], 'apiwass')) {
    fwrite(STDERR, "FAIL message={$error['message']}\n");
    exit(1);
}

ob_start();
include dirname(__DIR__) . '/app/Views/errors/html/app_error.php';
$html = (string) ob_get_clean();

if (! str_contains($html, 'error-shell') || ! str_contains($html, 'apiwass') || str_contains($html, 'SYSTEMPATH')) {
    fwrite(STDERR, "FAIL view render\n");
    exit(1);
}

echo "OK error presenter + screen\n";
