<?= $this->extend('layouts/main') ?>

<?= $this->section('styles') ?>
<style>
    .soc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: .9rem; }
    .soc-card { position: relative; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface); overflow: hidden; transition: box-shadow .18s ease, transform .18s ease; }
    .soc-card:hover { box-shadow: var(--shadow); transform: translateY(-2px); }
    .soc-thumb { position: relative; aspect-ratio: 16 / 10; background: #0f172a; cursor: pointer; display: flex; align-items: center; justify-content: center; overflow: hidden; }
    .soc-thumb img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
    .soc-thumb-fallback { color: rgba(255, 255, 255, .35); font-size: 1.8rem; }
    .soc-play { position: absolute; inset: 0; margin: auto; width: 2.6rem; height: 2.6rem; border-radius: 50%; background: rgba(0, 0, 0, .55); color: #fff; display: flex; align-items: center; justify-content: center; font-size: .9rem; transition: transform .15s ease, background .15s ease; }
    .soc-thumb:hover .soc-play { transform: scale(1.1); background: rgba(225, 29, 72, .9); }
    .soc-dur { position: absolute; right: .4rem; bottom: .4rem; background: rgba(0, 0, 0, .7); color: #fff; font-size: .7rem; padding: .1rem .35rem; border-radius: .3rem; }
    .soc-used { position: absolute; left: .4rem; bottom: .4rem; }
    .soc-pick { position: absolute; left: .45rem; top: .45rem; background: rgba(255, 255, 255, .92); border-radius: .35rem; padding: .15rem .3rem; line-height: 1; cursor: pointer; box-shadow: 0 1px 3px rgba(0, 0, 0, .2); }
    .soc-pick input { margin: 0; cursor: pointer; }
    .soc-meta { padding: .55rem .65rem .65rem; }
    .soc-name { font-size: .8125rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .soc-card.is-selected { border-color: var(--brand-500); box-shadow: 0 0 0 2px rgba(225, 29, 72, .25); }
    .soc-row-file { display: inline-flex; align-items: center; gap: .6rem; color: inherit; }
    .soc-mini { position: relative; width: 52px; height: 34px; border-radius: .35rem; background: #0f172a; color: rgba(255, 255, 255, .4); display: inline-flex; align-items: center; justify-content: center; overflow: hidden; flex: 0 0 auto; }
    .soc-mini img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
    .soc-pv-stage { background: #0b1220; min-height: 280px; display: flex; align-items: center; justify-content: center; }
    .soc-ai-review { max-height: 460px; overflow: auto; border: 1px solid var(--border); border-radius: var(--radius-sm, .5rem); background: var(--surface-2, #fafafa); padding: .5rem; }
    .soc-ai-file { padding: .75rem; margin-bottom: .6rem; background: var(--surface, #fff); border: 1px solid var(--border); border-radius: var(--radius-sm, .5rem); }
    .soc-ai-file:last-child { margin-bottom: 0; }
    .soc-ai-thumb { width: 64px; height: 44px; border-radius: .4rem; background: #0f172a; object-fit: cover; flex: 0 0 auto; }
    .soc-ai-about { font-size: .78rem; color: var(--bs-secondary-color, #6c757d); }
    .soc-ai-pf { padding-top: .6rem; margin-top: .6rem; border-top: 1px dashed var(--border); }
    .soc-ai-pf textarea { font-size: .8125rem; }
    .soc-count { font-size: .72rem; color: #6c757d; text-align: right; }
    .soc-count.is-over { color: #dc3545; font-weight: 600; }
    .soc-pvc { border: 1px solid var(--border); border-radius: .6rem; background: #fff; overflow: hidden; font-size: .78rem; }
    .soc-pvc-h { display: flex; align-items: center; gap: .5rem; padding: .5rem .6rem; }
    .soc-pvc-av { width: 1.7rem; height: 1.7rem; border-radius: 50%; background: var(--brand-500); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: .75rem; flex: 0 0 auto; }
    .soc-pvc img { width: 100%; max-height: 190px; object-fit: cover; display: block; background: #0f172a; }
    .soc-pvc-b { padding: .5rem .6rem .6rem; white-space: pre-wrap; word-break: break-word; line-height: 1.35; max-height: 150px; overflow: auto; }
    .soc-tag { color: #1d4ed8; }
    .soc-pvc-t { padding: .45rem .6rem 0; font-weight: 700; }
    .soc-pv-stage video, .soc-pv-stage img { max-width: 100%; max-height: 72vh; display: block; }
    .soc-pv-stage video { width: 100%; background: #000; }
    @media (max-width: 575.98px) { .soc-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .6rem; } }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $canEdit = function_exists('can') && can('social.create'); ?>
<div class="page-list" data-social-library>

<?php if (! $driveReady): ?>
    <div class="alert alert-warning">Google Drive is not set up on this platform yet. Ask the administrator to add the Google service account.</div>
<?php else: ?>
    <div class="page-hint">
        <i class="fas fa-info-circle" aria-hidden="true"></i>
        <span>Share your Google Drive folder (Viewer access) with <strong><?= esc($serviceEmail) ?></strong>, then paste the folder link below.</span>
    </div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header"><h2 class="card-title mb-0">Google Drive folders</h2></div>
    <div class="card-body py-3">
        <?php if ($canEdit && $driveReady): ?>
            <form class="row g-2 mb-3" id="socFolderForm">
                <div class="col-12 col-md-9"><input class="form-control form-control-sm" name="folder" placeholder="https://drive.google.com/drive/folders/..." required></div>
                <div class="col-12 col-md-3"><button class="btn btn-wa btn-sm w-100" type="submit">Add folder</button></div>
            </form>
        <?php endif; ?>
        <?php if ($folders === []): ?>
            <p class="text-muted mb-0">No folders added yet.</p>
        <?php else: ?>
            <ul class="list-group list-group-flush">
            <?php foreach ($folders as $f): ?>
                <li class="list-group-item d-flex flex-wrap align-items-center gap-2 px-0">
                    <i class="fab fa-google-drive text-muted"></i>
                    <span class="me-auto"><?= esc((string) $f['folder_name']) ?>
                        <span class="text-muted small ms-2">Last scan: <?= esc((string) ($f['last_scanned_at'] ?: 'never')) ?></span></span>
                    <a class="btn btn-outline-secondary btn-sm" href="https://drive.google.com/drive/folders/<?= esc(rawurlencode((string) $f['folder_id']), 'attr') ?>" target="_blank" rel="noopener">Open in Drive</a>
                    <?php if ($canEdit): ?>
                        <button class="btn btn-outline-secondary btn-sm" data-soc-scan="<?= (int) $f['id'] ?>">Scan for new files</button>
                        <button class="btn btn-outline-danger btn-sm" data-soc-remove="<?= (int) $f['id'] ?>">Remove</button>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php
$items = [];
foreach ($assets as $a) {
    $isVideo = str_starts_with((string) $a['mime'], 'video/');
    $issues  = (array) ($a['validation']['issues'] ?? []);
    $dur     = $a['duration_sec'] ? (int) round((float) $a['duration_sec']) : 0;
    $items[] = [
        'id'      => (int) $a['id'],
        'type'    => $isVideo ? 'video' : 'image',
        'name'    => (string) $a['filename'],
        'details' => trim(($a['width'] ? (int) $a['width'] . '×' . (int) $a['height'] : '')
            . ($dur ? ' · ' . $dur . 's' : '') . ' · ' . number_format((int) $a['size_bytes'] / 1048576, 1) . ' MB', ' ·'),
        'tips'    => $issues,
        'used'    => (int) $a['used_count'] > 0,
        'drive'   => 'https://drive.google.com/file/d/' . rawurlencode((string) $a['drive_file_id']) . '/view',
        'file'    => site_url('social/library/file/' . (int) $a['id']),
        'thumb'   => site_url('social/library/thumb/' . (int) $a['id']),
    ];
}
$fmtDur = static fn (int $s): string => intdiv($s, 60) . ':' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT);
?>
<div class="card" id="socMediaCard">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <h2 class="card-title mb-0 me-auto">Media (<span id="socMediaCount"><?= count($assets) ?></span>)</h2>
        <?php if ($assets !== []): ?>
            <select class="form-select form-select-sm w-auto" id="socTypeFilter" aria-label="Filter by type">
                <option value="">All files</option>
                <option value="image">Photos</option>
                <option value="video">Videos</option>
            </select>
            <label class="small mb-0 d-flex align-items-center gap-1" title="Select all shown files"><input type="checkbox" id="socSelAllG"> Select all</label>
            <div class="btn-group btn-group-sm" role="group" aria-label="View">
                <button type="button" class="btn btn-outline-secondary" data-soc-view="grid" title="Grid view"><i class="fas fa-table-cells-large"></i></button>
                <button type="button" class="btn btn-outline-secondary" data-soc-view="list" title="List view"><i class="fas fa-list"></i></button>
            </div>
        <?php endif; ?>
        <?php if ($canEdit): ?>
            <button class="btn btn-wa btn-sm" id="socOpenSchedule" disabled>Schedule selected (<span id="socSelCount">0</span>)</button>
        <?php endif; ?>
    </div>
    <div class="card-body py-3">
        <?php if ($assets === []): ?>
            <p class="text-muted mb-0">Add a folder to see your videos and images here.</p>
        <?php else: ?>
            <script type="application/json" id="socMediaData"><?= json_encode($items, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

            <!-- Grid view -->
            <div class="soc-grid" id="socGrid">
                <?php foreach ($items as $it): ?>
                    <div class="soc-card" data-type="<?= $it['type'] ?>" data-id="<?= $it['id'] ?>">
                        <div class="soc-thumb" data-soc-preview="<?= $it['id'] ?>" role="button" tabindex="0" aria-label="Preview <?= esc($it['name'], 'attr') ?>">
                            <img src="<?= esc($it['thumb'], 'attr') ?>" alt="" loading="lazy" onerror="this.remove()">
                            <i class="fas fa-<?= $it['type'] === 'video' ? 'video' : 'image' ?> soc-thumb-fallback" aria-hidden="true"></i>
                            <?php if ($it['type'] === 'video'): ?><span class="soc-play"><i class="fas fa-play"></i></span><?php endif; ?>
                            <?php if ($it['type'] === 'video' && preg_match('/(\d+)s/', $it['details'], $m)): ?><span class="soc-dur"><?= $fmtDur((int) $m[1]) ?></span><?php endif; ?>
                            <?php if ($it['used']): ?><span class="badge bg-secondary soc-used">Scheduled</span><?php endif; ?>
                        </div>
                        <label class="soc-pick" title="Select"><input type="checkbox" class="soc-asset-g" value="<?= $it['id'] ?>" <?= $it['used'] ? 'disabled' : '' ?> aria-label="Select <?= esc($it['name'], 'attr') ?>"></label>
                        <div class="soc-meta">
                            <div class="soc-name" title="<?= esc($it['name'], 'attr') ?>"><a href="<?= esc($it['drive'], 'attr') ?>" target="_blank" rel="noopener" title="Open in Google Drive"><?= esc($it['name']) ?></a></div>
                            <div class="small text-muted"><?= esc($it['details']) ?></div>
                            <div class="mt-1">
                                <?php if ($it['tips'] === []): ?>
                                    <span class="badge bg-success"><?= $it['type'] === 'video' ? 'Reel ready' : 'OK' ?></span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark" title="<?= esc(implode(' ', $it['tips']), 'attr') ?>"><?= count($it['tips']) ?> tip<?= count($it['tips']) > 1 ? 's' : '' ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- List view -->
            <div class="table-responsive d-none" id="socList">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr>
                        <th style="width:32px"><input type="checkbox" id="socSelAll" aria-label="Select all"></th>
                        <th>File</th><th>Details</th><th>Check</th><th>Used</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($items as $it): ?>
                        <tr data-type="<?= $it['type'] ?>" data-id="<?= $it['id'] ?>">
                            <td><input type="checkbox" class="soc-asset" value="<?= $it['id'] ?>" <?= $it['used'] ? 'disabled' : '' ?> aria-label="Select <?= esc($it['name'], 'attr') ?>"></td>
                            <td>
                                <a href="#" class="soc-row-file text-decoration-none" data-soc-preview="<?= $it['id'] ?>">
                                    <span class="soc-mini"><img src="<?= esc($it['thumb'], 'attr') ?>" alt="" loading="lazy" onerror="this.remove()"><i class="fas fa-<?= $it['type'] === 'video' ? 'video' : 'image' ?>"></i></span>
                                    <span><?= esc($it['name']) ?></span>
                                    <?php if ($it['type'] === 'video'): ?><i class="fas fa-circle-play text-muted ms-1" title="Play"></i><?php endif; ?>
                                </a>
                            </td>
                            <td class="small text-muted"><?= esc($it['details']) ?></td>
                            <td class="small">
                                <?php if ($it['tips'] === []): ?>
                                    <span class="badge bg-success"><?= $it['type'] === 'video' ? 'Reel ready' : 'OK' ?></span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark" title="<?= esc(implode(' ', $it['tips']), 'attr') ?>"><?= count($it['tips']) ?> tip<?= count($it['tips']) > 1 ? 's' : '' ?></span>
                                    <span class="text-muted"><?= esc($it['tips'][0]) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= $it['used'] ? '<span class="badge bg-secondary">Scheduled</span>' : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- Preview / player -->
<div class="modal fade" id="socPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-truncate" id="socPvTitle">Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0 soc-pv-stage" id="socPvStage"></div>
            <div class="modal-footer justify-content-between">
                <div class="small">
                    <span class="text-muted" id="socPvDetails"></span>
                    <span id="socPvCheck" class="ms-2"></span>
                </div>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-outline-secondary" id="socPvPrev" aria-label="Previous"><i class="fas fa-chevron-left"></i></button>
                    <span class="btn btn-outline-secondary disabled" id="socPvPos">1 / 1</span>
                    <button type="button" class="btn btn-outline-secondary" id="socPvNext" aria-label="Next"><i class="fas fa-chevron-right"></i></button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($canEdit): ?>
<div class="modal fade" id="socScheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <form class="modal-content" id="socScheduleForm" data-ai-ready="<?= ! empty($aiReady) ? '1' : '0' ?>">
            <div class="modal-header"><h5 class="modal-title">Schedule <span id="socModalCount">0</span> post(s)</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small mb-1">Post to</label>
                    <?php if ($accounts === []): ?>
                        <div class="text-muted small">No accounts connected. <a href="<?= site_url('social/accounts') ?>">Connect an account</a> first.</div>
                    <?php endif; ?>
                    <?php foreach ($accounts as $acc): ?>
                        <?php if ($acc['status'] !== 'active') { continue; } ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="accounts[]" value="<?= (int) $acc['id'] ?>" id="socAcc<?= (int) $acc['id'] ?>" data-platform="<?= esc((string) $acc['platform'], 'attr') ?>">
                            <label class="form-check-label" for="socAcc<?= (int) $acc['id'] ?>"><i class="fab fa-<?= esc($acc['platform']) ?> me-1"></i><?= esc((string) $acc['name']) ?> <span class="text-muted small">(<?= esc(ucfirst((string) $acc['platform'])) ?>)</span></label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="border rounded p-2 mb-3 d-none" id="socWaBox">
                    <div class="small fw-semibold mb-2"><i class="fab fa-whatsapp me-1"></i> WhatsApp broadcast</div>
                    <div class="form-text mb-2">Sent as a normal campaign at the scheduled time. Only approved templates, opted-in contacts and your wallet balance apply. WhatsApp has no official Status posting, so this is a message broadcast.</div>
                    <div class="row g-2">
                        <div class="col-12 col-md-6">
                            <label class="form-label small mb-1" for="socWaTemplate">Approved template</label>
                            <select class="form-select form-select-sm" id="socWaTemplate">
                                <option value="">Choose a template</option>
                                <?php foreach ($templates as $t): ?>
                                    <option value="<?= (int) $t['id'] ?>"><?= esc((string) $t['name']) ?> (<?= esc((string) $t['language']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small mb-1" for="socWaTags">Send to customer groups</label>
                            <select class="form-select form-select-sm" id="socWaTags" multiple size="3">
                                <?php foreach ($tags as $tg): ?>
                                    <option value="<?= (int) $tg['id'] ?>"><?= esc((string) $tg['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="socWaAll"><label class="form-check-label small" for="socWaAll">Send to all contacts instead</label></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" id="socWaCaption"><label class="form-check-label small" for="socWaCaption">Use the post caption as template variable {{1}}</label></div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label small mb-1" for="socStart">Start date</label>
                        <input type="date" class="form-control form-control-sm" name="start_date" id="socStart" required>
                    </div>
                    <div class="col-12 col-md-8">
                        <label class="form-label small mb-1" for="socTimes">Posting times each day (comma separated)</label>
                        <input class="form-control form-control-sm" id="socTimes" value="10:00" placeholder="10:00, 18:00">
                        <div class="form-text">One file per time slot. 20 files at 1 time a day fills 20 days.</div>
                    </div>
                </div>
                <div class="border rounded p-2 mb-3">
                    <label class="form-label small mb-1" for="socAiTopic"><i class="fas fa-magic me-1"></i> AI captions <span class="text-muted fw-normal">(<?= esc($aiLabel) ?>)</span></label>
                    <div class="row g-2">
                        <div class="col-12"><input class="form-control form-control-sm" id="socAiTopic" maxlength="600" placeholder="Optional: what are these posts about? (helps the AI)"></div>
                        <div class="col-6 col-md-3">
                            <select class="form-select form-select-sm" id="socAiLang" aria-label="Language">
                                <option value="English">English</option><option value="Hindi">Hindi</option><option value="Marathi">Marathi</option><option value="Hinglish">Hinglish</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <select class="form-select form-select-sm" id="socAiTone" aria-label="Tone">
                                <option value="friendly">Friendly</option><option value="professional">Professional</option><option value="funny">Funny</option><option value="inspiring">Inspiring</option><option value="urgent">Urgent</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6"><button type="button" class="btn btn-outline-secondary btn-sm w-100" id="socAiAll"><i class="fas fa-wand-magic-sparkles me-1"></i> Write a caption for every file</button></div>
                    </div>
                    <?php if (empty($aiReady)): ?>
                        <div class="alert alert-warning py-1 px-2 small mt-2 mb-0">The AI is not set up yet. <a href="<?= site_url('social/setup') ?>#soc-ai">Add its key on Social Setup</a> (Gemini uses the key in Settings &gt; AI).</div>
                    <?php endif; ?>
                    <div class="form-text" id="socAiProgress">The AI looks at each file's preview and writes one caption per platform you ticked above. You can edit them before scheduling.</div>
                    <div class="soc-ai-review d-none mt-2" id="socAiReview"></div>
                    <details class="mt-2">
                        <summary class="small text-muted">Or get 3 ideas for one shared caption</summary>
                        <div class="row g-2 mt-1">
                            <div class="col-8">
                                <select class="form-select form-select-sm" id="socAiPlatform" aria-label="Platform">
                                    <option value="instagram">Instagram</option><option value="facebook">Facebook</option><option value="youtube">YouTube</option><option value="linkedin">LinkedIn</option>
                                </select>
                            </div>
                            <div class="col-4"><button type="button" class="btn btn-outline-secondary btn-sm w-100" id="socAiGo">Suggest</button></div>
                        </div>
                        <div class="list-group list-group-flush mt-2" id="socAiOptions"></div>
                    </details>
                    <details class="mt-2">
                        <summary class="small text-muted">Plan a Reel / video: script with hook and scenes</summary>
                        <div class="row g-2 mt-1">
                            <div class="col-6 col-md-4">
                                <select class="form-select form-select-sm" id="socScPlatform" aria-label="Platform">
                                    <option value="instagram">Instagram Reel</option><option value="youtube">YouTube Short</option><option value="facebook">Facebook</option><option value="linkedin">LinkedIn</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-4">
                                <select class="form-select form-select-sm" id="socScSeconds" aria-label="Length">
                                    <option value="15">About 15 seconds</option><option value="30" selected>About 30 seconds</option><option value="45">About 45 seconds</option><option value="60">About 60 seconds</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-4"><button type="button" class="btn btn-outline-secondary btn-sm w-100" id="socScGo">Write script</button></div>
                        </div>
                        <div class="form-text">Uses the topic, language and tone above.</div>
                        <div class="mt-2 d-none" id="socScResult"></div>
                    </details>
                </div>
                <div class="mb-3">
                    <label class="form-label small mb-1" for="socCaption">Shared caption <span class="text-muted">(used for files without their own caption; {title} is the file name)</span></label>
                    <textarea class="form-control form-control-sm" name="caption" id="socCaption" rows="3" maxlength="2000"></textarea>
                    <div class="input-group input-group-sm mt-1">
                        <select class="form-select" id="socRwAction" aria-label="Change the caption">
                            <option value="shorter">Make it shorter</option>
                            <option value="longer">Make it longer</option>
                            <option value="hook">Stronger first line</option>
                            <option value="fix">Fix spelling and grammar</option>
                            <option value="tone">Change tone (uses the tone above)</option>
                            <option value="translate">Translate (uses the language above)</option>
                        </select>
                        <button type="button" class="btn btn-outline-secondary" id="socRwGo"><i class="fas fa-wand-magic-sparkles me-1"></i> Rewrite with AI</button>
                    </div>
                </div>
                <div class="mb-1">
                    <label class="form-label small mb-1" for="socTags">Hashtags</label>
                    <input class="form-control form-control-sm" name="hashtags" id="socTags" maxlength="500" placeholder="#brand #reels">
                </div>
                <div class="small text-muted" id="socPreview"></div>
            </div>
            <div class="modal-footer"><button class="btn btn-wa btn-sm" type="submit">Schedule</button></div>
        </form>
    </div>
</div>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/social.js') ?>"></script>
<?= $this->endSection() ?>
