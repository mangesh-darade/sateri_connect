<?php
/** @var list<array<string,mixed>> $campaigns */
/** @var list<array<string,mixed>> $builders */
/** @var bool $canSend */
/** @var bool $isCheerio */
$campaigns = $campaigns ?? [];
$builders = $builders ?? [];
$canSend = ! empty($canSend);
$isCheerio = ! empty($isCheerio);
?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="dash-panel">
            <div class="panel-head"><h3>HTML email campaign</h3></div>
            <div class="panel-body">
                <?php if (! $canSend): ?>
                    <p class="text-muted small mb-0">Need <code>emails.send</code> to create campaigns.</p>
                <?php else: ?>
                <form id="campaignForm" class="em-form">
                    <input type="hidden" name="id" id="camp_id" value="">
                    <div class="mb-2">
                        <label class="form-label">Campaign name</label>
                        <input type="text" name="name" id="camp_name" class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Subject</label>
                        <input type="text" name="subject" id="camp_subject" class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Use builder (optional)</label>
                        <select name="builder_id" id="camp_builder" class="form-select form-select-sm">
                            <option value="">— Custom HTML —</option>
                            <?php foreach ($builders as $b): ?>
                                <option value="<?= (int) $b['id'] ?>"
                                    data-cheerio="<?= esc($b['cheerio_builder_id'] ?? '', 'attr') ?>">
                                    <?= esc($b['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Cheerio Builder ID</label>
                        <input type="text" name="cheerio_builder_id" id="camp_cheerio_id" class="form-control form-control-sm">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">HTML body</label>
                        <textarea name="html_content" id="camp_html" class="form-control form-control-sm font-monospace" rows="7"></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Mode</label>
                        <select name="mode" id="camp_mode" class="form-select form-select-sm">
                            <option value="recipients">Recipients list</option>
                            <?php if ($isCheerio): ?>
                            <option value="label">Cheerio label</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="mb-2" id="campRecipientsWrap">
                        <label class="form-label">Recipients (comma / line)</label>
                        <textarea name="recipients" id="camp_recipients" class="form-control form-control-sm" rows="3"></textarea>
                    </div>
                    <div class="mb-3 d-none" id="campLabelWrap">
                        <label class="form-label">Cheerio label name</label>
                        <input type="text" name="label_name" id="camp_label" class="form-control form-control-sm">
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-wa btn-sm">Save draft</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="campReset">Reset</button>
                    </div>
                    <div class="em-msg mt-2 small" id="campMsg"></div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="dash-panel">
            <div class="panel-head d-flex justify-content-between">
                <h3>Campaigns</h3>
                <?php if ($canSend): ?>
                <div>
                    <a href="<?= site_url('emails/send') ?>" class="btn btn-xs btn-outline-secondary">Single</a>
                    <a href="<?= site_url('emails/bulk') ?>" class="btn btn-xs btn-outline-secondary">Bulk</a>
                </div>
                <?php endif; ?>
            </div>
            <div class="panel-body p-0">
                <?php if ($campaigns === []): ?>
                    <div class="activity-empty py-4">No HTML campaigns yet.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 em-table">
                        <thead><tr><th>Name</th><th>Status</th><th>Sent</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($campaigns as $c): ?>
                            <?php
                            if (! is_array($c)) {
                                continue;
                            }
                            $campaignId = (int) ($c['id'] ?? 0);
                            $campaignName = (string) ($c['name'] ?? ('Campaign #' . $campaignId));
                            $campaignSubject = (string) ($c['subject'] ?? '');
                            $campaignStatus = (string) ($c['status'] ?? 'draft');
                            ?>
                            <tr>
                                <td>
                                    <strong><?= esc($campaignName) ?></strong>
                                    <div class="text-muted small"><?= esc($campaignSubject !== '' ? $campaignSubject : 'No subject') ?></div>
                                </td>
                                <td><span class="badge text-bg-secondary"><?= esc($campaignStatus) ?></span></td>
                                <td><?= (int) ($c['sent_count'] ?? 0) ?> / fail <?= (int) ($c['failed_count'] ?? 0) ?></td>
                                <td class="text-end text-nowrap">
                                    <?php if ($canSend && $campaignId > 0 && in_array($campaignStatus, ['draft', 'failed'], true)): ?>
                                        <button type="button" class="btn btn-xs btn-wa em-send-camp" data-id="<?= $campaignId ?>">Send</button>
                                    <?php endif; ?>
                                    <?php if ($canSend && $campaignId > 0): ?>
                                        <button type="button" class="btn btn-xs btn-outline-danger em-del-camp" data-id="<?= $campaignId ?>">Del</button>
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
</div>
