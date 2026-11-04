<?php
// Counts line (also returned by Settings::cartRemindersData for live refresh).
?>
<div class="d-flex flex-wrap gap-3 small">
        <?php foreach (['open' => 'Waiting', 'reminded' => 'Reminded', 'recovered' => 'Recovered', 'skipped' => 'Skipped'] as $k => $label): ?>
            <span><span class="text-muted"><?= $label ?></span> <strong><?= (int) $cartOverview['counts'][$k] ?></strong></span>
        <?php endforeach; ?>
        <span><span class="text-muted">Recovered value</span> <strong><?= esc(number_format((float) $cartOverview['recovered_amount'], 2)) ?></strong></span>
    </div>
