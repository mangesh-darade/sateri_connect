<?php
$selectedTimezone = (string) ($selectedTimezone ?? 'UTC');
$timezoneOptions = $timezoneOptions ?? [];
?>
<select name="app_timezone" class="form-select js-timezone-select">
    <?php foreach ($timezoneOptions as $group => $options): ?>
        <optgroup label="<?= esc($group) ?>">
            <?php foreach ($options as $option): ?>
                <option value="<?= esc($option['value']) ?>" data-keywords="<?= esc($option['keywords'] ?? '') ?>" <?= $selectedTimezone === $option['value'] ? 'selected' : '' ?>>
                    <?= esc($option['label']) ?>
                </option>
            <?php endforeach; ?>
        </optgroup>
    <?php endforeach; ?>
</select>
