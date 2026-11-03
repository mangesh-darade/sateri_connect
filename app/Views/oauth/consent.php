<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<div class="auth-card-head">
    <h2>Connect <?= esc($client) ?></h2>
    <p class="auth-desc">Allow <strong><?= esc($client) ?></strong> to use this workspace as <strong><?= esc($user) ?></strong>?</p>
</div>
<p class="small text-muted">It will be able to read and send WhatsApp messages, manage contacts, templates, campaigns, workflows and social posts, with your permissions. You can revoke it any time under Settings → API Keys.</p>
<form action="<?= site_url('oauth/authorize') ?>" method="post" class="auth-form">
    <?= csrf_field() ?>
    <input type="hidden" name="req" value="<?= esc($req, 'attr') ?>">
    <button type="submit" name="decision" value="allow" class="btn btn-wa auth-submit"><i class="fas fa-plug me-1"></i> Allow</button>
    <button type="submit" name="decision" value="deny" class="btn btn-outline-secondary w-100 mt-2">Deny</button>
</form>
<?= $this->endSection() ?>
