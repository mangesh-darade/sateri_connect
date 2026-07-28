<?php
$flashSuccess = session()->getFlashdata('success');
$flashError   = session()->getFlashdata('error');
$flashWarning = session()->getFlashdata('warning');
$flashInfo    = session()->getFlashdata('info');
$flashErrors  = session()->getFlashdata('errors');
?>

<?php if ($flashSuccess): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= esc(is_array($flashSuccess) ? implode(' ', $flashSuccess) : $flashSuccess) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($flashError): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= esc(is_array($flashError) ? implode(' ', $flashError) : $flashError) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($flashWarning): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <?= esc(is_array($flashWarning) ? implode(' ', $flashWarning) : $flashWarning) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($flashInfo): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <?= esc(is_array($flashInfo) ? implode(' ', $flashInfo) : $flashInfo) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (! empty($flashErrors) && is_array($flashErrors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <ul class="mb-0 ps-3">
            <?php foreach ($flashErrors as $err): ?>
                <li><?= esc(is_array($err) ? implode(' ', $err) : $err) ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (! empty($validation) && is_object($validation) && method_exists($validation, 'listErrors')): ?>
    <?= $validation->listErrors('list') ?>
<?php elseif (! empty($errors) && is_array($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $err): ?>
                <li><?= esc(is_array($err) ? implode(' ', $err) : $err) ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
