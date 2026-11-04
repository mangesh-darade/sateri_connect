<?php
// Status alerts (also returned by Settings::cartRemindersData for live refresh).
?>
<?php foreach (($cartHealth ?? []) as $h): ?>
    <div class="alert alert-<?= esc($h['level'], 'attr') ?> py-2 small mb-2" role="alert"><?= esc($h['message']) ?></div>
<?php endforeach; ?>
