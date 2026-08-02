<?= $this->extend('layouts/main') ?>

<?= $this->section('styles') ?>
<style>
.guide-layout { display: flex; gap: 1.25rem; align-items: flex-start; }
.guide-toc {
    width: 250px;
    flex-shrink: 0;
    position: sticky;
    top: 1rem;
    max-height: calc(100vh - 140px);
    overflow-y: auto;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1rem;
    box-shadow: var(--shadow-sm);
}
.guide-toc h6 {
    font-family: var(--font-display);
    font-size: .68rem;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--text-muted);
    margin-bottom: .65rem;
}
.guide-toc a {
    display: block;
    font-size: .84rem;
    color: var(--text);
    text-decoration: none;
    padding: .35rem .45rem;
    border-radius: 8px;
    border-left: 2px solid transparent;
    transition: background .15s, color .15s;
}
.guide-toc a:hover { background: var(--wa-mist); color: var(--wa-teal); }
.guide-toc a.lvl-2 { padding-left: .9rem; font-size: .78rem; color: var(--text-muted); }
.guide-body {
    flex: 1;
    min-width: 0;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1.5rem 1.75rem 2.25rem;
    box-shadow: var(--shadow-sm);
}
.guide-body .guide-h { scroll-margin-top: 80px; color: var(--wa-ink); font-family: var(--font-display); letter-spacing: -0.03em; }
.guide-body h1.guide-h { font-size: 1.55rem; border-bottom: 2px solid var(--wa-green); padding-bottom: .45rem; }
.guide-body h2.guide-h { font-size: 1.2rem; margin-top: 1.75rem; }
.guide-body h3.guide-h { font-size: 1.05rem; margin-top: 1.25rem; }
.guide-figure {
    margin: 1rem 0 1.25rem;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: .65rem;
    text-align: center;
}
.guide-shot {
    max-height: 420px;
    width: auto;
    border-radius: 8px;
    cursor: zoom-in;
}
.guide-figure figcaption {
    margin-top: .45rem;
    font-size: .8rem;
    color: var(--text-muted);
}
.guide-code {
    background: var(--wa-ink);
    color: #e8f3ef;
    border-radius: var(--radius-sm);
    padding: .9rem 1.1rem;
    font-size: .85rem;
    overflow-x: auto;
}
.guide-callout {
    background: var(--wa-mist);
    border-left: 4px solid var(--wa-green);
    border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
    padding: .75rem 1.1rem;
    margin: 1rem 0;
}
.guide-callout p { margin: 0; }
.guide-checklist { list-style: none; padding-left: 0; }
.guide-checklist li { margin: .35rem 0; }
.guide-checklist input { margin-right: .4rem; }
.guide-hr { border-top: 1px dashed var(--border); margin: 1.5rem 0; }
.guide-table th { background: var(--surface-2); white-space: nowrap; }
.guide-switch-header {
    display: inline-flex;
    gap: .35rem;
    padding: .2rem;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 999px;
}
.guide-switch-header a {
    border-radius: 999px;
    padding: .3rem .85rem;
    font-size: .8rem;
    font-weight: 600;
    text-decoration: none;
    color: var(--text-muted);
}
.guide-switch-header a.active {
    background: var(--wa-green);
    color: #fff;
}
.guide-export-actions { display: inline-flex; gap: .5rem; flex-wrap: wrap; }
.guide-export-actions .btn { white-space: nowrap; }
@media (max-width: 991px) {
    .guide-layout { flex-direction: column; }
    .guide-toc { width: 100%; position: static; max-height: 220px; }
}
</style>
<?= $this->endSection() ?>

<?= $this->section('header_actions') ?>
<?php
$guideType = $guideType ?? 'local';
$guideNav = $guideNav ?? [
    ['type' => 'local', 'label' => 'Local'],
    ['type' => 'production', 'label' => 'Production'],
];
?>
<div class="guide-switch guide-switch-header">
    <?php foreach ($guideNav as $nav): ?>
        <?php $navType = (string) ($nav['type'] ?? 'local'); ?>
        <a href="<?= site_url('guide/' . $navType) ?>" class="<?= $guideType === $navType ? 'active' : '' ?>">
            <?= esc($nav['label'] ?? $navType) ?>
        </a>
    <?php endforeach; ?>
</div>
<div class="guide-export-actions">
    <button type="button" class="btn btn-sm btn-outline-secondary" id="guideDownloadPdf">Download PDF</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="guideDownloadDoc">Download DOC</button>
</div>
<a href="<?= site_url('guide/automations') ?>" class="btn btn-sm btn-outline-secondary">Automations guide</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$toc = $toc ?? [];
$guideHtml = $guideHtml ?? '';
$guideType = $guideType ?? 'local';
?>
<div class="page-list">
<div class="guide-layout">
    <aside class="guide-toc d-none d-md-block">
        <h6>On this page</h6>
        <?php foreach ($toc as $item): ?>
            <a class="lvl-<?= (int) ($item['level'] ?? 1) ?>" href="#<?= esc($item['id'] ?? '', 'attr') ?>">
                <?= esc($item['text'] ?? '') ?>
            </a>
        <?php endforeach; ?>
        <?php if ($toc === []): ?>
            <span class="text-muted small">No sections</span>
        <?php endif; ?>
    </aside>
    <article class="guide-body">
        <?= $guideHtml ?>
    </article>
</div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/html2pdf.js@0.10.1/dist/html2pdf.bundle.min.js"></script>
<script>
(function () {
    const article = document.querySelector('.guide-body');
    const pdfBtn = document.getElementById('guideDownloadPdf');
    const docBtn = document.getElementById('guideDownloadDoc');
    const guideType = <?= json_encode($guideType) ?>;
    const guideTitle = <?= json_encode((string) ($title ?? 'Guide')) ?>;

    if (!article) {
        return;
    }

    const slugify = (value) => String(value || 'guide')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '') || 'guide';

    const fileBase = slugify(guideTitle || guideType || 'guide');

    if (pdfBtn) {
        pdfBtn.addEventListener('click', async function () {
            const original = pdfBtn.innerHTML;
            pdfBtn.disabled = true;
            pdfBtn.textContent = 'Preparing PDF...';
            try {
                await html2pdf().set({
                    margin: [10, 10, 10, 10],
                    filename: fileBase + '.pdf',
                    image: { type: 'jpeg', quality: 0.98 },
                    html2canvas: { scale: 2, useCORS: true },
                    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                    pagebreak: { mode: ['css', 'legacy'] }
                }).from(article).save();
            } finally {
                pdfBtn.disabled = false;
                pdfBtn.innerHTML = original;
            }
        });
    }

    if (docBtn) {
        docBtn.addEventListener('click', function () {
            const docHtml = `
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>${guideTitle}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #222; }
        img { max-width: 100%; height: auto; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; vertical-align: top; }
        pre { background: #f5f5f5; padding: 12px; white-space: pre-wrap; }
        code { font-family: Consolas, monospace; }
        blockquote { border-left: 4px solid #2a9d8f; margin: 1rem 0; padding: .5rem 1rem; background: #f4fbfa; }
    </style>
</head>
<body>
    <h1>${guideTitle}</h1>
    ${article.innerHTML}
</body>
</html>`;

            const blob = new Blob(['\ufeff', docHtml], { type: 'application/msword' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = fileBase + '.doc';
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        });
    }
})();
</script>
<?= $this->endSection() ?>
