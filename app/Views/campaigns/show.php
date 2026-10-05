<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('campaigns') ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$campaign = $campaign ?? [];
$id = (int) ($campaign['id'] ?? 0);
$total = max(1, (int) ($campaign['total_contacts'] ?? 1));
$sent = (int) ($campaign['sent_count'] ?? 0);
$pct = min(100, (int) round(($sent / $total) * 100));
$isActive = in_array($campaign['status'] ?? '', ['running', 'scheduled', 'queued', 'processing'], true);
?>
<style>
.live-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background-color: #adb5bd;
    display: inline-block;
    transition: all 0.3s ease;
}
.live-dot.live-pulse {
    background-color: #25D366;
    box-shadow: 0 0 0 0 rgba(37, 211, 102, 0.7);
    animation: livePulseAnim 1.8s infinite cubic-bezier(0.66, 0, 0, 1);
}
@keyframes livePulseAnim {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(37, 211, 102, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(37, 211, 102, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(37, 211, 102, 0); }
}
.spin {
    animation: spinAnim 0.8s linear infinite;
}
@keyframes spinAnim {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="dash-panel">
            <div class="panel-head d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <h3 class="mb-0"><?= esc($campaign['name'] ?? 'Campaign') ?></h3>
                    <span id="campaignStatusBadge"><?= view('partials/status_badge', ['status' => $campaign['status'] ?? 'draft']) ?></span>
                    <span id="campaignLiveBadge" class="badge rounded-pill bg-light text-muted border align-middle" style="font-size:0.75rem;padding:4px 9px;">
                        <span class="live-dot <?= $isActive ? 'live-pulse' : '' ?>" id="campaignLiveDot"></span>
                        <span id="campaignLiveText"><?= $isActive ? 'Live updating' : 'Updated' ?></span>
                    </span>
                </div>
                <div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnRefreshCampaign" title="Refresh metrics">
                        <i class="fas fa-sync-alt" id="refreshIcon"></i> Refresh
                    </button>
                </div>
            </div>
            <div class="panel-body">
                <div class="row g-2 mb-3 small text-muted">
                    <div class="col-md-4"><strong class="text-dark">Template</strong><br><?= esc($template['name'] ?? $campaign['template_name'] ?? $campaign['template_id'] ?? '—') ?></div>
                    <div class="col-md-4"><strong class="text-dark">Scheduled</strong><br><?= esc(format_app_datetime($campaign['scheduled_at'] ?? null)) ?></div>
                    <div class="col-md-4"><strong class="text-dark">Started / Done</strong><br><span id="campaignStartedAt"><?= esc(format_app_datetime($campaign['started_at'] ?? null)) ?></span> / <span id="campaignCompletedAt"><?= esc(format_app_datetime($campaign['completed_at'] ?? null)) ?></span></div>
                </div>
                <label class="form-label small text-muted mb-1" id="campaignProgressLabel">Progress · <?= esc((string) $sent) ?> / <?= esc((string) ($campaign['total_contacts'] ?? 0)) ?></label>
                <div class="progress mb-3" style="height:10px;border-radius:999px;background:var(--wa-mist)">
                    <div class="progress-bar" id="campaignProgressBar" style="width:<?= $pct ?>%;background:linear-gradient(90deg,#4b3786,#8e53f7);border-radius:999px"></div>
                </div>
                <div class="row g-2 text-center">
                    <div class="col">
                        <div class="fw-bold" id="metricSent" style="font-family:var(--font-display);color:#4b3786"><?= esc((string) ($campaign['sent_count'] ?? 0)) ?></div>
                        <small class="text-muted">Sent</small>
                    </div>
                    <div class="col">
                        <div class="fw-bold" id="metricDelivered" style="font-family:var(--font-display);color:#8e53f7"><?= esc((string) ($campaign['delivered_count'] ?? 0)) ?></div>
                        <small class="text-muted">Delivered</small>
                    </div>
                    <div class="col">
                        <div class="fw-bold" id="metricRead" style="font-family:var(--font-display);color:#34B7F1"><?= esc((string) ($campaign['read_count'] ?? 0)) ?></div>
                        <small class="text-muted">Read</small>
                    </div>
                    <div class="col">
                        <div class="fw-bold" id="metricFailed" style="font-family:var(--font-display);color:#e25555"><?= esc((string) ($campaign['failed_count'] ?? 0)) ?></div>
                        <small class="text-muted">Failed</small>
                    </div>
                    <div class="col">
                        <div class="fw-bold" id="metricReplies" style="font-family:var(--font-display);color:#f0a202"><?= esc((string) ($campaign['reply_count'] ?? 0)) ?></div>
                        <small class="text-muted">Replies</small>
                    </div>
                </div>
            </div>
            <div class="panel-body border-top pt-3 d-flex flex-wrap gap-2" style="border-color:var(--border)!important">
                <?php if (function_exists('can') && can('campaigns.start')): ?>
                    <?php if (in_array($campaign['status'] ?? '', ['draft', 'scheduled', 'paused'], true)): ?>
                        <form action="<?= site_url('campaigns/' . $id . '/send-now') ?>" method="post" class="d-inline"><?= csrf_field() ?>
                            <button class="btn btn-wa btn-sm" data-confirm="Start sending now?">Send now</button>
                        </form>
                    <?php endif; ?>
                    <?php if (($campaign['status'] ?? '') === 'running'): ?>
                        <form action="<?= site_url('campaigns/' . $id . '/pause') ?>" method="post" class="d-inline"><?= csrf_field() ?>
                            <button class="btn btn-outline-secondary btn-sm">Pause</button>
                        </form>
                    <?php endif; ?>
                    <?php if (($campaign['status'] ?? '') === 'paused'): ?>
                        <form action="<?= site_url('campaigns/' . $id . '/resume') ?>" method="post" class="d-inline"><?= csrf_field() ?>
                            <button class="btn btn-wa btn-sm">Resume</button>
                        </form>
                    <?php endif; ?>
                    <?php if (in_array($campaign['status'] ?? '', ['running', 'paused', 'scheduled'], true)): ?>
                        <form action="<?= site_url('campaigns/' . $id . '/cancel') ?>" method="post" class="d-inline"><?= csrf_field() ?>
                            <button class="btn btn-outline-danger btn-sm" data-confirm="Cancel this campaign?">Cancel</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="dash-panel">
            <div class="panel-head"><h3>Delivery mix</h3></div>
            <div class="panel-body">
                <canvas id="campaignAnalyticsChart" height="220"
                    data-labels='<?= json_encode(['Sent','Delivered','Read','Failed','Replies']) ?>'
                    data-values='<?= json_encode([
                        (int) ($campaign['sent_count'] ?? 0),
                        (int) ($campaign['delivered_count'] ?? 0),
                        (int) ($campaign['read_count'] ?? 0),
                        (int) ($campaign['failed_count'] ?? 0),
                        (int) ($campaign['reply_count'] ?? 0),
                    ]) ?>'></canvas>
            </div>
        </div>
    </div>
</div>

<div class="dash-panel">
    <div class="panel-head"><h3>Recipients</h3></div>
    <div class="panel-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="campaignContactsTable">
                <thead>
                    <tr>
                        <th>Contact</th>
                        <th>Mobile</th>
                        <th>Status</th>
                        <th>Sent</th>
                        <th>Delivered</th>
                        <th>Read (Opened)</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (($recipients ?? $campaign_contacts ?? []) as $r): ?>
                        <tr data-recipient-id="<?= (int) ($r['id'] ?? 0) ?>">
                            <td><?= esc($r['name'] ?? '') ?></td>
                            <td><?= esc($r['mobile'] ?? '') ?></td>
                            <td class="col-status"><?= view('partials/status_badge', ['status' => $r['status'] ?? '']) ?></td>
                            <td class="col-sent text-muted small"><?= esc(! empty($r['sent_at']) ? format_app_datetime($r['sent_at']) : (! empty($r['processed_at']) ? format_app_datetime($r['processed_at']) : '—')) ?></td>
                            <td class="col-delivered text-muted small"><?= esc(! empty($r['delivered_at']) ? format_app_datetime($r['delivered_at']) : '—') ?></td>
                            <td class="col-read text-muted small"><?= esc(! empty($r['read_at']) ? format_app_datetime($r['read_at']) : '—') ?></td>
                            <td class="col-error small text-danger"><?= esc($r['error_message'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(function () {
    var campaignId = <?= $id ?>;
    var currentStatus = <?= json_encode((string) ($campaign['status'] ?? 'draft')) ?>;
    var progressUrl = '<?= site_url('campaigns/' . $id . '/progress') ?>';
    var isPolling = false;
    var pollTimer = null;
    var consecutiveErrors = 0;
    var chartInstance = null;
    window._pollCountCompleted = 0;

    var canvas = document.getElementById('campaignAnalyticsChart');
    if (canvas && window.Chart) {
        var labels = JSON.parse(canvas.getAttribute('data-labels') || '[]');
        var values = JSON.parse(canvas.getAttribute('data-values') || '[]');
        chartInstance = new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: ['#4b3786','#8e53f7','#34B7F1','#e25555','#f0a202'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }

    var dt = null;
    if ($.fn.DataTable) {
        dt = $('#campaignContactsTable').DataTable({ pageLength: 25 });
    }

    function updateUI(data) {
        if (!data) return;

        if (data.status_badge) {
            $('#campaignStatusBadge').html(data.status_badge);
        }
        currentStatus = data.status || currentStatus;

        if (data.started_at && data.started_at !== '—') {
            $('#campaignStartedAt').text(data.started_at);
        }
        if (data.completed_at && data.completed_at !== '—') {
            $('#campaignCompletedAt').text(data.completed_at);
        }

        var sent = data.sent_count || 0;
        var total = data.total_contacts || 0;
        var pct = data.percentage || 0;
        $('#campaignProgressLabel').text('Progress · ' + sent + ' / ' + total);
        $('#campaignProgressBar').css('width', pct + '%');

        $('#metricSent').text(sent);
        $('#metricDelivered').text(data.delivered_count || 0);
        $('#metricRead').text(data.read_count || 0);
        $('#metricFailed').text(data.failed_count || 0);
        $('#metricReplies').text(data.reply_count || 0);

        if (chartInstance) {
            chartInstance.data.datasets[0].data = [
                sent,
                data.delivered_count || 0,
                data.read_count || 0,
                data.failed_count || 0,
                data.reply_count || 0
            ];
            chartInstance.update();
        }

        if (data.recipients && data.recipients.length > 0) {
            $.each(data.recipients, function (_, r) {
                var $row = $('#campaignContactsTable tr[data-recipient-id="' + r.id + '"]');
                if ($row.length) {
                    $row.find('.col-status').html(r.status_badge || r.status);
                    $row.find('.col-sent').text(r.sent_at || '—');
                    $row.find('.col-delivered').text(r.delivered_at || '—');
                    $row.find('.col-read').text(r.read_at || '—');
                    $row.find('.col-error').text(r.error || '');
                }
            });
        }

        var isActive = ['running', 'scheduled', 'queued', 'processing'].indexOf(currentStatus) !== -1;
        if (isActive) {
            $('#campaignLiveDot').addClass('live-pulse');
            $('#campaignLiveText').text('Live updating');
        } else {
            $('#campaignLiveDot').removeClass('live-pulse');
            $('#campaignLiveText').text('Updated');
        }
    }

    function fetchProgress(isManual) {
        if (isPolling) return;
        isPolling = true;

        if (isManual) {
            $('#refreshIcon').addClass('spin');
            $('#btnRefreshCampaign').prop('disabled', true);
        }

        $.ajax({
            url: progressUrl,
            method: 'GET',
            dataType: 'json',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).done(function (res) {
            consecutiveErrors = 0;
            if (res && res.status === 'success' && res.data) {
                updateUI(res.data);
            }
        }).fail(function () {
            consecutiveErrors++;
        }).always(function () {
            isPolling = false;
            if (isManual) {
                setTimeout(function () {
                    $('#refreshIcon').removeClass('spin');
                    $('#btnRefreshCampaign').prop('disabled', false);
                }, 400);
            }
            scheduleNext();
        });
    }

    function scheduleNext() {
        if (pollTimer) clearTimeout(pollTimer);
        if (consecutiveErrors >= 5) return;

        var isActive = ['running', 'scheduled', 'queued', 'processing'].indexOf(currentStatus) !== -1;
        var delay = isActive ? 3000 : 15000;

        if (!isActive && window._pollCountCompleted > 6) {
            return;
        }
        if (!isActive) {
            window._pollCountCompleted = (window._pollCountCompleted || 0) + 1;
        }

        pollTimer = setTimeout(function () {
            if (!document.hidden) {
                fetchProgress(false);
            } else {
                scheduleNext();
            }
        }, delay);
    }

    $('#btnRefreshCampaign').on('click', function () {
        fetchProgress(true);
    });

    scheduleNext();
});
</script>
<?= $this->endSection() ?>
