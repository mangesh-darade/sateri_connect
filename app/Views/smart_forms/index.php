<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php if (function_exists('can') && can('contacts.create')): ?>
    <a href="<?= site_url('smart-forms/create') ?>" class="btn btn-wa btn-sm"><i class="fas fa-plus me-1"></i> New form</a>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-list">
    <div class="page-hint">
        <i class="fas fa-info-circle" aria-hidden="true"></i>
        <span>Share a link. When someone submits, their mobile is saved as a contact and any <strong>New form response</strong> workflow runs. This does not record WhatsApp opt-in. Every public form asks visitors to tick Google’s “I’m not a robot” box.</span>
    </div>
    <div class="card">
        <div class="card-header"><h2 class="card-title mb-0">Google reCAPTCHA</h2></div>
        <div class="card-body">
            <p class="text-muted small">Use the checkbox type (reCAPTCHA v2, “I’m not a robot”) from the <a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener">Google reCAPTCHA admin</a>. Add this site as <code>localhost</code>.</p>
            <form method="post" action="<?= site_url('smart-forms/captcha') ?>" class="row g-3">
                <?= csrf_field() ?>
                <div class="col-md-6">
                    <label class="form-label" for="recaptchaSite">Site key</label>
                    <input class="form-control" id="recaptchaSite" name="recaptcha_site_key" value="<?= esc($captchaSiteKey) ?>" autocomplete="off" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="recaptchaSecret">Secret key</label>
                    <input class="form-control" id="recaptchaSecret" name="recaptcha_secret_key" type="password" autocomplete="new-password" placeholder="<?= $captchaReady ? 'Saved. Paste a new key to replace it.' : '' ?>" <?= $captchaReady ? '' : 'required' ?>>
                </div>
                <div class="col-12">
                    <button class="btn btn-wa btn-sm" type="submit">Save captcha</button>
                    <?php if ($captchaReady): ?>
                        <span class="text-success small ms-2">Checkbox is on.</span>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h2 class="card-title mb-0">Smart Forms</h2></div>
        <div class="card-body p-0">
            <?php if (empty($hasForms)): ?>
                <div class="page-hint m-3">No forms yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 w-100" id="smartFormsTable">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Link</th>
                                <th>Status</th>
                                <th>Responses</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(function () {
    var esc = AppDT.esc;
    AppDT.table('#smartFormsTable', {
        url: <?= json_encode(site_url('smart-forms/data')) ?>,
        order: [[0, 'asc']],
        emptyText: 'No forms found',
        columns: [
            {data: 'name', render: function (v, t) { return t === 'display' ? esc(v) : v; }},
            {data: null, render: function (r, t) { return t === 'display' ? '<a href="' + esc(r.url) + '" target="_blank" rel="noopener">' + esc(r.slug) + '</a>' : r.slug; }},
            {data: 'status', render: function (v, t) { return t === 'display' ? esc(v) : v; }},
            {data: 'responses'},
            {data: null, orderable: false, className: 'text-end', render: function (r) {
                return '<a class="btn btn-sm btn-outline-secondary" href="' + esc(r.edit_url) + '">Edit</a>';
            }}
        ]
    });
});
</script>
<?= $this->endSection() ?>
