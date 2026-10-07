<?= $this->extend('layouts/main') ?>

<?php
$isSes      = ! empty($isSes);
$sesReady   = ! empty($sesReady);
$canManage  = function_exists('can') && can('emails.send');
$canSetup   = $isSes && $sesReady && $canManage;
$domains    = $domains ?? [];
$senders    = $senders ?? [];
$fromEmail  = (string) ($fromEmail ?? '');
$fromName   = (string) ($fromName ?? '');
$verified   = count(array_filter($domains, static fn ($d) => ($d['status'] ?? '') === 'verified'));

$statusBadge = static function (?string $status): string {
    $status = $status ?: 'pending';
    $cls    = match ($status) {
        'verified' => 'bg-success-subtle text-success border-success-subtle',
        'failed'   => 'bg-danger-subtle text-danger border-danger-subtle',
        'disabled' => 'bg-secondary-subtle text-secondary border-secondary-subtle',
        default    => 'bg-warning-subtle text-warning-emphasis border-warning-subtle',
    };
    $icon = match ($status) {
        'verified' => 'fa-check-circle',
        'failed'   => 'fa-times-circle',
        default    => 'fa-clock',
    };

    return '<span class="badge border text-capitalize ' . $cls . '"><i class="fas ' . $icon . ' me-1"></i>' . esc($status) . '</span>';
};

$awsStatus = static function (?string $status): string {
    if ($status === null || $status === '') {
        return '<span class="text-muted">—</span>';
    }
    $cls = match ($status) {
        'SUCCESS'             => 'text-success',
        'FAILED', 'NOT_FOUND' => 'text-danger',
        default               => 'text-warning-emphasis',
    };

    return '<span class="small fw-semibold ' . $cls . '">' . esc($status) . '</span>';
};

$steps = [
    ['Connect AWS', 'Access key, secret & region saved', $sesReady],
    ['Add domain', 'Register your domain with SES', $domains !== []],
    ['Publish DNS', 'DKIM, MAIL FROM, SPF & DMARC', $verified > 0],
    ['Default sender', 'From address for all emails', $fromEmail !== ''],
];
?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('settings') ?>#tabEmail" class="btn btn-sm btn-outline-secondary">
    <i class="fas fa-key me-1"></i> Provider &amp; Credentials
</a>
<?php if ($canSetup): ?>
<button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#sesAddDomainModal">
    <i class="fas fa-plus me-1"></i> Add Domain
</button>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="email-settings">

    <?php if (! $isSes): ?>
    <div class="alert alert-warning d-flex align-items-start gap-3 shadow-sm">
        <i class="fas fa-exclamation-triangle mt-1"></i>
        <div class="flex-grow-1">
            <div class="fw-semibold">Active email provider is <?= esc($providerLabel ?? 'SMTP') ?></div>
            <div class="small">Automatic domain setup and DNS records work with <strong>Amazon SES</strong>. Switch the email provider to Amazon SES to use this screen.</div>
        </div>
        <a href="<?= site_url('settings') ?>#tabEmail" class="btn btn-sm btn-warning text-nowrap">Change Provider</a>
    </div>
    <?php elseif (! $sesReady): ?>
    <div class="alert alert-warning d-flex align-items-start gap-3 shadow-sm">
        <i class="fas fa-key mt-1"></i>
        <div class="flex-grow-1">
            <div class="fw-semibold">Amazon SES credentials missing</div>
            <div class="small">Save the AWS Access Key, Secret Key and Region first. Then come back here to add your domain.</div>
        </div>
        <a href="<?= site_url('settings') ?>#tabEmail" class="btn btn-sm btn-warning text-nowrap">Add Credentials</a>
    </div>
    <?php endif; ?>

    <!-- Overview -->
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="es-stat-icon bg-warning-subtle text-warning-emphasis"><i class="fab fa-aws"></i></div>
                    <div class="min-w-0">
                        <div class="text-muted small">Email Provider</div>
                        <div class="fw-bold text-truncate"><?= esc($providerLabel ?? 'SMTP') ?></div>
                        <?php if ($isSes): ?>
                            <div class="small text-muted">Region <code><?= esc($sesRegion ?? '') ?></code> ·
                                <?= $sesReady ? '<span class="text-success">Connected</span>' : '<span class="text-danger">Not connected</span>' ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="es-stat-icon bg-primary-subtle text-primary"><i class="fas fa-globe"></i></div>
                    <div>
                        <div class="text-muted small">Sending Domains</div>
                        <div class="fw-bold fs-5"><?= $verified ?> <span class="text-muted fs-6 fw-normal">/ <?= count($domains) ?> verified</span></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="es-stat-icon bg-info-subtle text-info-emphasis"><i class="fas fa-at"></i></div>
                    <div>
                        <div class="text-muted small">Sender Emails</div>
                        <div class="fw-bold fs-5"><?= count($senders) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="es-stat-icon bg-success-subtle text-success"><i class="fas fa-paper-plane"></i></div>
                    <div class="min-w-0">
                        <div class="text-muted small">Default From</div>
                        <?php if ($fromEmail !== ''): ?>
                            <div class="fw-bold text-truncate"><?= esc($fromName !== '' ? $fromName : $fromEmail) ?></div>
                            <div class="small text-muted text-truncate"><?= esc($fromEmail) ?></div>
                        <?php else: ?>
                            <div class="fw-semibold text-danger small">Not set</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Setup progress -->
    <?php if ($isSes): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="row g-3">
                <?php foreach ($steps as $i => [$label, $hint, $done]): ?>
                <div class="col-sm-6 col-lg-3">
                    <div class="d-flex align-items-center gap-2">
                        <span class="es-step <?= $done ? 'is-done' : '' ?>"><?= $done ? '<i class="fas fa-check"></i>' : ($i + 1) ?></span>
                        <div class="min-w-0">
                            <div class="fw-semibold small"><?= esc($label) ?></div>
                            <div class="text-muted" style="font-size:.75rem"><?= esc($hint) ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Bounce & delivery tracking -->
    <?php $bt = $bounceTracking ?? []; ?>
    <div class="card border-0 shadow-sm mb-3" id="sesBounceCard" data-state="<?= esc($bt['state'] ?? 'not_connected') ?>">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <div class="es-stat-icon bg-danger-subtle text-danger"><i class="fas fa-undo-alt"></i></div>
            <div class="flex-grow-1 min-w-0">
                <div class="d-flex align-items-center gap-2">
                    <h6 class="mb-0 fw-bold">Bounce &amp; Delivery Tracking</h6>
                    <span class="badge border" id="sesBounceBadge"></span>
                </div>
                <div class="small text-muted">Shows Delivered / Bounced / Spam per recipient and stops re-sending to invalid addresses automatically.</div>
                <div class="small mt-1 text-break">Webhook: <code id="sesBounceWebhook"><?= esc($bt['webhook'] ?? '') ?></code></div>
                <?php if (empty($bt['webhook_public'])): ?>
                <div class="small text-warning-emphasis mt-1"><i class="fas fa-exclamation-triangle me-1"></i>This address is not public HTTPS — connect from the live https:// site so Amazon can reach it.</div>
                <?php endif; ?>
                <div class="small mt-1 js-bounce-msg"></div>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-light border" id="sesBounceCheck"><i class="fas fa-sync-alt me-1"></i><span>Check status</span></button>
                <?php if ($canSetup): ?>
                <button type="button" class="btn btn-sm btn-primary" id="sesBounceConnect"><i class="fas fa-plug me-1"></i><span><?= ($bt['state'] ?? '') === 'not_connected' ? 'Connect' : 'Reconnect' ?></span></button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Domains -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
            <div>
                <h6 class="mb-0 fw-bold"><i class="fas fa-globe text-primary me-2"></i>Sending Domains</h6>
                <div class="small text-muted">Each domain needs its DNS records published once. DKIM CNAMEs also verify ownership.</div>
            </div>
            <?php if ($canSetup): ?>
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#sesAddDomainModal"><i class="fas fa-plus me-1"></i> Add Domain</button>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php if ($domains === []): ?>
                <div class="text-center py-5 px-3">
                    <div class="es-empty-icon mx-auto mb-3"><i class="fas fa-globe"></i></div>
                    <div class="fw-semibold">No sending domain yet</div>
                    <div class="small text-muted mb-3">Add your domain (e.g. <code>yourbrand.com</code>) to get the DNS records for verification.</div>
                    <?php if ($canSetup): ?>
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#sesAddDomainModal"><i class="fas fa-plus me-1"></i> Add Domain</button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Domain</th>
                            <th>Status</th>
                            <th>Domain / DKIM</th>
                            <th>MAIL FROM</th>
                            <th>Last Checked</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($domains as $d): ?>
                        <?php $ver = (array) ($d['dns_records']['verification'] ?? []); ?>
                        <tr data-sender='<?= esc(json_encode($d), 'attr') ?>'>
                            <td class="ps-3">
                                <div class="fw-semibold"><?= esc($d['domain']) ?></div>
                                <?php if (! empty($d['mail_from_domain'])): ?>
                                    <div class="small text-muted">MAIL FROM: <?= esc($d['mail_from_domain']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= $statusBadge($d['status'] ?? null) ?></td>
                            <td><?= $awsStatus($ver['domain_status'] ?? null) ?> <span class="text-muted">/</span> <?= $awsStatus($ver['dkim_status'] ?? null) ?></td>
                            <td><?= $awsStatus($ver['mail_from_status'] ?? null) ?></td>
                            <td class="small text-muted"><?= esc($d['last_checked_at'] ?? '—') ?></td>
                            <td class="text-end pe-3 text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-dark em-ses-dns" title="View DNS records"><i class="fas fa-list me-1"></i><span>DNS</span></button>
                                <?php if ($canManage && $isSes): ?>
                                <button type="button" class="btn btn-sm btn-outline-success em-ses-check" data-domain="<?= esc($d['domain'], 'attr') ?>"><i class="fas fa-sync-alt me-1"></i><span>Check</span></button>
                                <?php endif; ?>
                                <?php if ($canManage): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger em-ses-delete" data-id="<?= (int) $d['id'] ?>" data-label="<?= esc($d['domain'], 'attr') ?>"
                                        data-confirm-text="Removes it from this app only. The identity stays in your AWS account." title="Remove"><i class="fas fa-trash"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Senders -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
            <div>
                <h6 class="mb-0 fw-bold"><i class="fas fa-at text-primary me-2"></i>Sender Emails</h6>
                <div class="small text-muted">From addresses on your domains. The default one is used for all outgoing emails.</div>
            </div>
            <?php if ($canSetup && $domains !== []): ?>
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#sesAddSenderModal"><i class="fas fa-plus me-1"></i> Add Sender</button>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php if ($senders === []): ?>
                <div class="text-center py-5 px-3">
                    <div class="es-empty-icon mx-auto mb-3"><i class="fas fa-at"></i></div>
                    <div class="fw-semibold">No sender email yet</div>
                    <div class="small text-muted mb-3"><?= $domains === [] ? 'Add a domain first, then add From addresses like hello@yourdomain.com.' : 'Add a From address such as hello@' . esc($domains[0]['domain']) . '.' ?></div>
                    <?php if ($canSetup && $domains !== []): ?>
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#sesAddSenderModal"><i class="fas fa-plus me-1"></i> Add Sender</button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">From Name</th>
                            <th>Email</th>
                            <th>Domain</th>
                            <th>Status</th>
                            <th>Default</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($senders as $s): ?>
                        <?php $isDefault = strcasecmp((string) ($s['email'] ?? ''), $fromEmail) === 0; ?>
                        <tr>
                            <td class="ps-3 fw-semibold"><?= esc($s['name']) ?></td>
                            <td><?= esc($s['email']) ?></td>
                            <td class="small text-muted"><?= esc($s['domain'] ?? '—') ?></td>
                            <td><?= $statusBadge($s['status'] ?? null) ?></td>
                            <td>
                                <?php if ($isDefault): ?>
                                    <span class="badge bg-primary"><i class="fas fa-star me-1"></i>Default</span>
                                <?php elseif ($canManage && $isSes): ?>
                                    <button type="button" class="btn btn-sm btn-link p-0 em-ses-default" data-id="<?= (int) $s['id'] ?>">Make default</button>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-3">
                                <?php if ($canManage): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger em-ses-delete" data-id="<?= (int) $s['id'] ?>" data-label="<?= esc($s['email'], 'attr') ?>" title="Remove"><i class="fas fa-trash"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($canSetup): ?>
<!-- Add domain -->
<div class="modal fade" id="sesAddDomainModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="sesIdentityForm" novalidate>
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-globe text-primary me-2"></i>Add Sending Domain</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">We register the domain with Amazon SES and show the DNS records (DKIM, MAIL FROM, SPF, DMARC) to add at your DNS provider.</p>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Domain <span class="text-danger">*</span></label>
                    <input type="text" name="domain" class="form-control" placeholder="yourbrand.com" required>
                    <div class="form-text">Only the domain — no http:// or www.</div>
                </div>
                <div class="row g-2">
                    <div class="col-sm-6 mb-3">
                        <label class="form-label small fw-semibold">From Email <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="email" name="email" class="form-control" placeholder="hello@yourbrand.com">
                    </div>
                    <div class="col-sm-6 mb-3">
                        <label class="form-label small fw-semibold">From Name <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" name="name" class="form-control" placeholder="Your Brand" maxlength="120">
                    </div>
                </div>
                <div class="mb-1">
                    <label class="form-label small fw-semibold">DMARC Policy</label>
                    <select name="dmarc_policy" class="form-select">
                        <option value="none" selected>none — monitor only (recommended to start)</option>
                        <option value="quarantine">quarantine — failing mail goes to spam</option>
                        <option value="reject">reject — failing mail is blocked</option>
                    </select>
                </div>
                <div class="small mt-2 js-ses-msg" data-base-class="small mt-2 js-ses-msg"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-arrow-right me-1"></i> Continue &amp; Get DNS Records</button>
            </div>
        </form>
    </div>
</div>

<!-- Add sender -->
<div class="modal fade" id="sesAddSenderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="sesSenderForm" novalidate>
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-at text-primary me-2"></i>Add Sender Email</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">From Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="Your Brand" maxlength="120" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">From Email <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control" placeholder="hello@<?= esc($domains[0]['domain'] ?? 'yourbrand.com', 'attr') ?>" required>
                    <div class="form-text">Must be on one of your domains:
                        <?= implode(', ', array_map(static fn ($d) => '<code>' . esc($d['domain']) . '</code>', $domains)) ?>
                    </div>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_default" id="sesSenderDefault" value="1" <?= $fromEmail === '' ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="sesSenderDefault">Use as default From address for all emails</label>
                </div>
                <div class="small mt-2 js-ses-msg" data-base-class="small mt-2 js-ses-msg"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Sender</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?= view('partials/ses_dns_modal', ['canManage' => $canManage && $isSes]) ?>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .email-settings .es-stat-icon { width: 42px; height: 42px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
    .email-settings .es-step { width: 30px; height: 30px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: .8rem; background: #F3F4F6; color: #6B7280; flex-shrink: 0; }
    .email-settings .es-step.is-done { background: var(--bs-success); color: #fff; }
    .email-settings .es-empty-icon { width: 56px; height: 56px; border-radius: 50%; background: #F3F4F6; color: #9CA3AF; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; }
</style>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset_url('assets/js/email-identities.js') ?>"></script>
<?= $this->endSection() ?>
