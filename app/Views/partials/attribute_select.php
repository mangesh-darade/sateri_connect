<?php
/**
 * Attribute dropdown (same groups as the workflow builder): Attributes page definitions,
 * contact fields, then older keys already saved on contacts.
 *
 * @var string                          $name
 * @var string|null                     $selected
 * @var array<string, array>|null       $attributeDefs
 * @var list<string>|null               $attributeKeys
 * @var string|null                     $emptyLabel
 * @var bool|null                       $noMobile
 * @var string|null                     $class
 */
$selected      = (string) ($selected ?? '');
$attributeDefs = $attributeDefs ?? service('contactAttributes')->definitions();
$attributeKeys = $attributeKeys ?? [];
$noMobile      = $noMobile ?? true;
$core          = ['name' => 'Name', 'mobile' => 'Mobile', 'email' => 'Email', 'country' => 'Country', 'notes' => 'Notes', 'status' => 'Status', 'birthday' => 'Birthday'];
if ($noMobile) {
    unset($core['mobile']);
}
$shown  = array_merge(array_keys($attributeDefs), array_keys($core));
$others = array_values(array_filter(
    array_unique(array_merge($attributeKeys, $selected !== '' ? [$selected] : [])),
    static fn ($k) => $k !== '' && ! in_array($k, $shown, true) && ! ($noMobile && $k === 'mobile')
));
$opt = static fn (string $value, string $label): string => '<option value="' . esc($value, 'attr') . '"' . ($selected === $value ? ' selected' : '') . '>' . esc($label) . '</option>';
?>
<select name="<?= esc($name, 'attr') ?>" class="<?= esc($class ?? 'form-select', 'attr') ?>">
    <option value=""><?= esc($emptyLabel ?? '— Select attribute —') ?></option>
    <?php if ($attributeDefs !== []): ?>
        <optgroup label="Attributes">
            <?php foreach ($attributeDefs as $key => $def): ?>
                <?= $opt((string) $key, (string) $def['label']) ?>
            <?php endforeach; ?>
        </optgroup>
    <?php endif; ?>
    <optgroup label="Contact fields">
        <?php foreach ($core as $key => $label): ?>
            <?php if (! isset($attributeDefs[$key])): ?><?= $opt($key, $label) ?><?php endif; ?>
        <?php endforeach; ?>
    </optgroup>
    <?php if ($others !== []): ?>
        <optgroup label="Other saved fields">
            <?php foreach ($others as $key): ?>
                <?= $opt((string) $key, (string) $key) ?>
            <?php endforeach; ?>
        </optgroup>
    <?php endif; ?>
</select>
