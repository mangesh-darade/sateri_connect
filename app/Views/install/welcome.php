<?= $this->extend('layouts/install') ?>

<?= $this->section('content') ?>
<div class="text-center py-2">
    <h2 class="mb-2" style="font-family:var(--font-display);color:var(--wa-ink);letter-spacing:-0.03em">Welcome aboard</h2>
    <p class="text-muted mx-auto mb-4" style="max-width:42ch">
        This wizard sets up the database, admin account, and WhatsApp provider (Cheerio or Meta) — then you’re ready to message.
    </p>
    <ul class="list-unstyled text-start mx-auto mb-4" style="max-width:380px">
        <li class="mb-2 d-flex align-items-center gap-2"><span class="badge rounded-pill" style="background:var(--wa-mist);color:var(--wa-teal)">1</span> Cheerio or Meta WhatsApp API</li>
        <li class="mb-2 d-flex align-items-center gap-2"><span class="badge rounded-pill" style="background:var(--wa-mist);color:var(--wa-teal)">2</span> Campaigns, chat, automations &amp; reports</li>
        <li class="mb-2 d-flex align-items-center gap-2"><span class="badge rounded-pill" style="background:var(--wa-mist);color:var(--wa-teal)">3</span> Role-based team access</li>
    </ul>
    <a href="<?= site_url('install/requirements') ?>" class="btn btn-wa btn-lg">
        Get started <i class="fas fa-arrow-right ms-1"></i>
    </a>
</div>
<?= $this->endSection() ?>
