<?= $this->extend('layouts/install') ?>

<?= $this->section('content') ?>
<div class="text-center py-2">
    <div class="mx-auto mb-3 d-flex align-items-center justify-content-center" style="width:64px;height:64px;border-radius:18px;background:var(--wa-mist);color:var(--wa-teal);font-size:1.5rem">
        <i class="fas fa-database"></i>
    </div>
    <h2 class="mb-2" style="font-family:var(--font-display);color:var(--wa-ink)">Run migrations</h2>
    <p class="text-muted mx-auto mb-3" style="max-width:40ch">
        Creates tables and seeds roles, permissions, settings, and default keyword replies.
    </p>
</div>

<div class="alert border mb-4" style="background:var(--wa-mist);border-color:var(--border)!important;color:var(--wa-ink)">
    <i class="fas fa-info-circle me-1"></i>
    Users, contacts, campaigns, messages, queue, automations, and webhooks tables will be created.
</div>

<form action="<?= site_url('install/migrate') ?>" method="post">
    <?= csrf_field() ?>
    <div class="d-flex justify-content-between">
        <a href="<?= site_url('install/database') ?>" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
        <button type="submit" class="btn btn-wa">
            Run migrations &amp; seed <i class="fas fa-play ms-1"></i>
        </button>
    </div>
</form>
<?= $this->endSection() ?>
