<?php
/**
 * Attribute dropdown: attributes defined on Contacts → Attributes, then core contact fields.
 *
 * @var string                          $name
 * @var string|null                     $selected
 * @var array<string, array>|null       $attributeDefs
 * @var string|null                     $emptyLabel
 * @var bool|null                       $noMobile
 * @var string|null                     $class
 */
$selected      = (string) ($selected ?? '');
$attributeDefs = $attributeDefs ?? service('contactAttributes')->definitions();
$noMobile      = $noMobile ?? true;
$core          = ['name' => 'Name', 'mobile' => 'Mobile', 'email' => 'Email', 'country' => 'Country', 'notes' => 'Notes', 'status' => 'Status', 'birthday' => 'Birthday', 'source' => 'Source'];
if ($noMobile) {
    unset($core['mobile']);
}
$shown = array_merge(array_keys($attributeDefs), array_keys($core));
$opt = static fn (string $value, string $label): string => '<option value="' . esc($value, 'attr') . '"' . ($selected === $value ? ' selected' : '') . '>' . esc($label) . '</option>';
?>
<select name="<?= esc($name, 'attr') ?>" class="<?= esc($class ?? 'form-select', 'attr') ?>">
    <option value=""><?= esc($emptyLabel ?? '— Select attribute —') ?></option>
    <?php if ($attributeDefs !== []): ?>
        <optgroup label="Attributes">
            <?php foreach ($attributeDefs as $key => $def): ?>
                <?php if ((string) $key === 'source') { continue; } // listed under Contact fields ?>
                <?= $opt((string) $key, (string) $def['label']) ?>
            <?php endforeach; ?>
        </optgroup>
    <?php endif; ?>
    <optgroup label="Contact fields">
        <?php foreach ($core as $key => $label): ?>
            <?php if (! isset($attributeDefs[$key]) || $key === 'source'): ?><?= $opt($key, $label) ?><?php endif; ?>
        <?php endforeach; ?>
    </optgroup>
    <?php if ($selected !== '' && ! in_array($selected, $shown, true)): ?>
        <?= $opt($selected, $selected) ?>
    <?php endif; ?>
</select>
