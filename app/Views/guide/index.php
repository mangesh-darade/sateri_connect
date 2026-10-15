<?= $this->extend(! empty($platformGuide) ? 'layouts/platform' : 'layouts/main') ?>

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
.guide-body h1.guide-h { font-size: 1.4rem; border-bottom: 1px solid var(--border); padding-bottom: .55rem; margin-bottom: 1rem; }
.guide-body h2.guide-h { font-size: 1.12rem; margin-top: 1.5rem; }
.guide-body h3.guide-h { font-size: 0.95rem; margin-top: 1.15rem; color: var(--text-muted); font-weight: 600; }
.guide-body .guide-part {
    margin-top: 2rem;
    padding: .55rem .8rem;
    background: #f6f8f7;
    border-radius: 10px;
    font-size: 1.02rem;
    border-bottom: 0;
}
.guide-body .guide-step {
    display: flex;
    align-items: center;
    gap: .7rem;
    margin-top: 1.45rem;
    padding-bottom: .35rem;
    border-bottom: 1px solid var(--border);
}
.guide-step-num {
    flex: 0 0 auto;
    width: 1.65rem;
    height: 1.65rem;
    border-radius: 999px;
    background: #152029;
    color: #fff;
    font-size: .78rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.guide-step-title { line-height: 1.3; }
.guide-body ol { margin: .4rem 0 .8rem; padding-left: 1.35rem; }
.guide-body ol li { margin: .28rem 0; padding-left: .15rem; }
.guide-body ol li::marker { font-weight: 700; color: #152029; }
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
    background: #f7faf8;
    border: 1px solid var(--border);
    border-left: 3px solid #0f766e;
    border-radius: 10px;
    padding: .7rem .95rem;
    margin: .85rem 0;
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
<div class="guide-export-actions">
    <button type="button" class="btn btn-sm btn-outline-secondary" id="guideDownloadPdf">Download PDF</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="guideDownloadDoc">Download DOC</button>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$toc = $toc ?? [];
$guideHtml = $guideHtml ?? '';
$guideType = $guideType ?? 'local';
?>
<div class="page-list">
<?= view('guide/_tabs', ['guideType' => $guideType, 'guideBase' => $guideBase ?? 'guide/', 'guideNav' => $guideNav ?? []]) ?>
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
