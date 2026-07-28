<?php

declare(strict_types=1);

/**
 * Shared branded error screen shell.
 *
 * Expected: $error array from App\Libraries\ErrorPresenter
 *
 * @var array<string, mixed> $error
 */

use App\Libraries\ErrorPresenter;

if (! isset($error) || ! is_array($error)) {
    $error = ErrorPresenter::manual([
        'title'    => isset($title) ? (string) $title : 'Something went wrong',
        'headline' => isset($title) ? (string) $title : 'We hit a snag',
        'message'  => isset($message) ? (string) $message : 'Please try again.',
        'technical'=> isset($message) ? (string) $message : '',
        'code'     => (int) ($code ?? 500),
        'file'     => (string) ($file ?? ''),
        'line'     => (int) ($line ?? 0),
    ], (int) ($code ?? 500));
}

$code     = (int) ($error['code'] ?? 500);
$title    = (string) ($error['title'] ?? 'Error');
$headline = (string) ($error['headline'] ?? $title);
$message  = (string) ($error['message'] ?? '');
$hint     = (string) ($error['hint'] ?? '');
$icon     = (string) ($error['icon'] ?? 'fa-triangle-exclamation');
$actions  = is_array($error['actions'] ?? null) ? $error['actions'] : [];
$showDetails = ! empty($error['show_details']);
$technical   = (string) ($error['technical'] ?? '');
$exceptionClass = (string) ($error['exception_class'] ?? '');
$file = (string) ($error['file'] ?? '');
$line = (int) ($error['line'] ?? 0);
$kind = (string) ($error['kind'] ?? 'generic');
$appName = 'Sateri Connect';
try {
    if (function_exists('setting')) {
        $appName = (string) setting('app_name', $appName);
    }
} catch (Throwable) {
    // ignore
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= esc($code) ?> — <?= esc($title) ?> | <?= esc($appName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Sora:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css">
    <style>
        :root {
            --wa-green: #25D366;
            --wa-ink: #042f2a;
            --wa-teal: #075E54;
            --surface: #ffffff;
            --text: #14332e;
            --muted: #5a726c;
            --border: rgba(7, 94, 84, 0.14);
            --radius: 18px;
        }
        * { box-sizing: border-box; }
        body {
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.25rem;
            font-family: 'DM Sans', system-ui, sans-serif;
            color: var(--text);
            background:
                radial-gradient(900px 520px at 12% 0%, rgba(37, 211, 102, 0.22), transparent 55%),
                radial-gradient(700px 420px at 100% 100%, rgba(18, 140, 126, 0.18), transparent 50%),
                linear-gradient(165deg, #f3f7f5 0%, #e8f3ef 45%, #f7faf8 100%);
        }
        .error-shell {
            width: 100%;
            max-width: 560px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: 0 12px 40px rgba(4, 47, 42, 0.08);
            overflow: hidden;
        }
        .error-top {
            padding: 1.35rem 1.5rem 1.1rem;
            background:
                linear-gradient(145deg, rgba(232, 243, 239, 0.95), rgba(255,255,255,0.4));
            border-bottom: 1px solid var(--border);
        }
        .error-brand {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            color: var(--wa-teal);
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            margin-bottom: 0.85rem;
        }
        .error-mark {
            width: 2.4rem;
            height: 2.4rem;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            background: linear-gradient(145deg, #2be072, #128C7E);
            box-shadow: 0 8px 18px rgba(18, 140, 126, 0.28);
            margin-bottom: 0.85rem;
            font-size: 1.05rem;
        }
        .error-code {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.75rem;
            font-weight: 650;
            color: var(--wa-teal);
            background: #e8f3ef;
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 0.2rem 0.65rem;
            margin-bottom: 0.65rem;
        }
        h1 {
            font-family: 'Sora', system-ui, sans-serif;
            font-size: clamp(1.25rem, 2.5vw, 1.55rem);
            font-weight: 700;
            letter-spacing: -0.03em;
            margin: 0 0 0.45rem;
            color: var(--wa-ink);
            line-height: 1.25;
        }
        .error-message {
            margin: 0;
            color: var(--muted);
            font-size: 0.95rem;
            line-height: 1.5;
        }
        .error-body { padding: 1.1rem 1.5rem 1.45rem; }
        .error-hint {
            display: flex;
            gap: 0.55rem;
            align-items: flex-start;
            padding: 0.7rem 0.85rem;
            margin: 0 0 1rem;
            border-radius: 12px;
            background: #fff8f0;
            border: 1px solid rgba(192, 106, 26, 0.2);
            color: #7a4a12;
            font-size: 0.84rem;
            line-height: 1.45;
        }
        .error-hint i { margin-top: 0.15rem; color: #c06a1a; }
        .error-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .btn-err {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            border-radius: 999px;
            padding: 0.55rem 1.05rem;
            font-size: 0.875rem;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid var(--border);
            color: var(--wa-ink);
            background: #fff;
            cursor: pointer;
        }
        .btn-err-primary {
            border: 0;
            color: var(--wa-ink);
            background: linear-gradient(180deg, #2be072, #25D366);
            box-shadow: 0 6px 16px rgba(37, 211, 102, 0.28);
        }
        .btn-err:hover { filter: brightness(0.98); }
        .error-details {
            margin-top: 1rem;
            border-top: 1px dashed var(--border);
            padding-top: 0.85rem;
        }
        .error-details summary {
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--wa-teal);
            user-select: none;
        }
        .error-details pre {
            margin: 0.65rem 0 0;
            padding: 0.75rem 0.85rem;
            border-radius: 10px;
            background: #042f2a;
            color: #e8f3ef;
            font-size: 0.75rem;
            line-height: 1.45;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .error-meta {
            margin-top: 0.55rem;
            font-size: 0.75rem;
            color: var(--muted);
        }
        .error-kind-database .error-mark { background: linear-gradient(145deg, #34b7f1, #075E54); }
        .error-kind-permission .error-mark { background: linear-gradient(145deg, #f59e0b, #b45309); }
        .error-kind-not_found .error-mark { background: linear-gradient(145deg, #94a3b8, #475569); }
    </style>
</head>
<body>
    <main class="error-shell error-kind-<?= esc($kind, 'attr') ?>" role="alert">
        <div class="error-top">
            <div class="error-brand"><i class="fab fa-whatsapp"></i> <?= esc($appName) ?></div>
            <div class="error-mark" aria-hidden="true"><i class="fas <?= esc($icon, 'attr') ?>"></i></div>
            <div class="error-code">Error <?= esc((string) $code) ?></div>
            <h1><?= esc($headline) ?></h1>
            <p class="error-message"><?= esc($message) ?></p>
        </div>
        <div class="error-body">
            <?php if ($hint !== ''): ?>
                <div class="error-hint">
                    <i class="fas fa-lightbulb" aria-hidden="true"></i>
                    <span><?= esc($hint) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($actions !== []): ?>
                <div class="error-actions">
                    <?php foreach ($actions as $action): ?>
                        <?php
                        $label = (string) ($action['label'] ?? 'Continue');
                        $url   = (string) ($action['url'] ?? '#');
                        $primary = ! empty($action['primary']);
                        ?>
                        <a class="btn-err<?= $primary ? ' btn-err-primary' : '' ?>" href="<?= esc($url, 'attr') ?>"><?= esc($label) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($showDetails && ($technical !== '' || $exceptionClass !== '')): ?>
                <details class="error-details">
                    <summary>Technical details (development only)</summary>
                    <pre><?= esc($technical !== '' ? $technical : $exceptionClass) ?></pre>
                    <?php if ($file !== ''): ?>
                        <div class="error-meta"><?= esc(basename($file)) ?>:<?= esc((string) $line) ?></div>
                    <?php endif; ?>
                </details>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
