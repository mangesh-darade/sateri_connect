<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <meta name="csrf-header" content="<?= csrf_header() ?>">
    <title><?= esc($title ?? 'Installer') ?> | WhatsApp Automation Platform</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body class="install-page">
<?php
$steps = [
    'welcome'      => 'Welcome',
    'requirements' => 'Requirements',
    'database'     => 'Database',
    'migrate'      => 'Migrate',
    'admin'        => 'Admin',
    'cheerio'      => 'WhatsApp API',
    'finish'       => 'Finish',
];
$uri = uri_string();
$currentStep = $step ?? 'welcome';
if (str_contains($uri, 'requirements')) {
    $currentStep = 'requirements';
} elseif (str_contains($uri, 'migrate')) {
    $currentStep = 'migrate';
} elseif (str_contains($uri, 'database')) {
    $currentStep = 'database';
} elseif (str_contains($uri, 'admin')) {
    $currentStep = 'admin';
} elseif (str_contains($uri, 'cheerio') || str_contains($uri, 'meta')) {
    $currentStep = 'cheerio';
} elseif (str_contains($uri, 'finish')) {
    $currentStep = 'finish';
} elseif ($uri === 'install' || str_ends_with($uri, '/install')) {
    $currentStep = 'welcome';
}
$stepKeys = array_keys($steps);
$currentIndex = array_search($currentStep, $stepKeys, true);
if ($currentIndex === false) {
    $currentIndex = 0;
}
?>
<div class="install-wrapper">
    <div class="install-header">
        <div class="mark"><i class="fab fa-whatsapp"></i></div>
        <h1>WhatsApp Automation Platform</h1>
        <p>Installation wizard · step <?= (int) $currentIndex + 1 ?> of <?= count($steps) ?></p>
    </div>

    <div class="install-steps mb-4">
        <ul class="nav nav-pills nav-fill">
            <?php foreach ($steps as $key => $label): ?>
                <?php
                $idx = array_search($key, $stepKeys, true);
                $cls = 'nav-link disabled';
                if ($idx < $currentIndex) {
                    $cls = 'nav-link done';
                } elseif ($idx === $currentIndex) {
                    $cls = 'nav-link active';
                }
                ?>
                <li class="nav-item">
                    <span class="<?= $cls ?>">
                        <span class="step-num"><?= $idx + 1 ?></span>
                        <span class="d-none d-md-inline"><?= esc($label) ?></span>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="card install-card">
        <div class="card-body p-4 p-md-5">
            <?= view('partials/alerts') ?>
            <?= $this->renderSection('content') ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    window.APP = {
        baseUrl: <?= json_encode(rtrim(site_url(), '/')) ?>,
        csrfToken: <?= json_encode(csrf_hash()) ?>,
        csrfHeader: <?= json_encode(csrf_header()) ?>,
        csrfName: <?= json_encode(csrf_token()) ?>
    };
</script>
<script src="<?= base_url('assets/js/app.js') ?>"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
