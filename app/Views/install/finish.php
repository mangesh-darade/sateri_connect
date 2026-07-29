<?= $this->extend('layouts/install') ?>

<?= $this->section('content') ?>
<div class="text-center py-3">
    <div class="mx-auto mb-3 d-flex align-items-center justify-content-center" style="width:72px;height:72px;border-radius:22px;background:linear-gradient(145deg,#9b6af8,#4b3786);color:#fff;font-size:2rem;box-shadow:0 10px 28px rgba(142,83,247,.35)">
        <i class="fas fa-check"></i>
    </div>
    <h2 class="mb-2" style="font-family:var(--font-display);color:var(--wa-ink)">You’re ready</h2>
    <p class="text-muted mx-auto mb-3" style="max-width:36ch">Confirm to finish install and open the sign-in page.</p>
    <div class="alert border text-start mx-auto mb-3" style="max-width:480px;background:var(--wa-mist);border-color:var(--border)!important;color:var(--wa-ink)">
        <ul class="mb-0 ps-3">
            <li>Database migrated and seeded</li>
            <li>Admin account created</li>
            <li>WhatsApp provider (Cheerio / Meta) can be finished later in Settings</li>
        </ul>
    </div>
    <p class="text-muted small mb-4">Remember cron jobs for queue, campaigns, and automations.</p>
    <form action="<?= site_url('install/finish') ?>" method="post">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-wa btn-lg">
            Complete installation <i class="fas fa-sign-in-alt ms-1"></i>
        </button>
    </form>
</div>
<?= $this->endSection() ?>
