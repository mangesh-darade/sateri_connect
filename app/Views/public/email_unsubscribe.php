<?php
/**
 * Public unsubscribe page (no layout / session).
 *
 * @var string $state      confirm | done | invalid | error
 * @var string $email
 * @var string $signature
 * @var string $tenant
 * @var int    $campaignId
 * @var string $sender
 */
$state  = $state ?? 'invalid';
$sender = trim((string) ($sender ?? ''));
$from   = $sender !== '' ? esc($sender) : 'this sender';

$titles = [
    'confirm' => 'Unsubscribe from emails?',
    'done'    => 'You have been unsubscribed',
    'error'   => 'Something went wrong',
    'invalid' => 'This link is invalid or has expired',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= esc($titles[$state] ?? $titles['invalid']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light d-flex align-items-center min-vh-100">
<div class="container" style="max-width: 520px;">
    <div class="card shadow-sm border-0 rounded-4 text-center p-4 p-md-5 bg-white">
        <h3 class="fw-bold mb-3"><?= esc($titles[$state] ?? $titles['invalid']) ?></h3>

        <?php if ($state === 'confirm'): ?>
            <p class="text-muted mb-4">
                <strong><?= esc($email) ?></strong> will no longer receive marketing emails from <?= $from ?>.
            </p>
            <form method="post" action="<?= esc(site_url('emails/unsubscribe'), 'attr') ?>">
                <input type="hidden" name="email" value="<?= esc($email, 'attr') ?>">
                <input type="hidden" name="sig" value="<?= esc($signature, 'attr') ?>">
                <input type="hidden" name="t" value="<?= esc($tenant, 'attr') ?>">
                <?php if ((int) $campaignId > 0): ?>
                    <input type="hidden" name="cid" value="<?= (int) $campaignId ?>">
                <?php endif; ?>
                <button type="submit" class="btn btn-danger px-4">Yes, unsubscribe me</button>
            </form>
            <p class="small text-muted mt-3 mb-0">Changed your mind? Just close this tab.</p>
        <?php elseif ($state === 'done'): ?>
            <p class="text-muted mb-4">
                <strong><?= esc($email) ?></strong> will no longer receive marketing emails from <?= $from ?>.
            </p>
            <p class="small text-muted mb-0">You can close this tab safely.</p>
        <?php elseif ($state === 'error'): ?>
            <p class="text-muted mb-0">We could not process your request right now. Please try again in a few minutes.</p>
        <?php else: ?>
            <p class="text-muted mb-0">
                We could not verify this unsubscribe link. To stop receiving emails, please reply to the email
                you received and ask <?= $from ?> to remove you, or use the unsubscribe link in a newer email.
            </p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
