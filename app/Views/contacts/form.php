<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('contacts') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="fas fa-arrow-left me-1"></i> Back to contacts
</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$contact = $contact ?? [];
$isEdit = ! empty($contact['id']);
$action = $isEdit ? site_url('contacts/' . (int) $contact['id']) : site_url('contacts');
$getVal = static function (string $key, $default = '') use ($contact) {
    return esc(old($key) ?? ($contact[$key] ?? $default));
};
$selectedTags = old('tag_ids') ?? ($selectedTags ?? ($contact['tag_ids'] ?? []));
if (! is_array($selectedTags)) {
    $selectedTags = [];
}
$attributeDefs = $attributeDefs ?? [];
$cf = $contact['custom_fields'] ?? [];
if (is_string($cf)) {
    $decoded = json_decode($cf, true);
    $cf = is_array($decoded) ? $decoded : [];
}
if (! is_array($cf)) {
    $cf = [];
}
$oldKeys = old('attr_key');
$oldVals = old('attr_value');
if (is_array($oldKeys)) {
    $cf = [];
    foreach ($oldKeys as $i => $k) {
        $cf[(string) $k] = is_array($oldVals) ? (string) ($oldVals[$i] ?? '') : '';
    }
}
$extraFields = array_filter($cf, static fn ($v, $k) => ! str_starts_with((string) $k, '_') && ! isset($attributeDefs[$k]), ARRAY_FILTER_USE_BOTH);
$isCreate    = empty($contact['id']);

$countries = $countries ?? countries_list();
$rawMobile = old('mobile') ?? ($contact['mobile'] ?? '');
$detected = model(\App\Models\CountryModel::class)->detectFromFullPhone((string) $rawMobile);
$selectedDialCode = (string) (old('country_code') ?? ($detected['dial_code'] !== '' ? $detected['dial_code'] : '91'));
$localMobileVal   = $detected['local_number'] !== '' ? $detected['local_number'] : preg_replace('/\D+/', '', (string) $rawMobile);
if ($selectedDialCode !== '' && str_starts_with($localMobileVal, $selectedDialCode) && strlen($localMobileVal) > strlen($selectedDialCode)) {
    $localMobileVal = substr($localMobileVal, strlen($selectedDialCode));
}
?>

<style>
.compact-contact-container {
    width: 100%;
}
.compact-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.compact-card-header {
    padding: 0.85rem 1.25rem;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.compact-card-body {
    padding: 1.15rem 1.25rem;
}
.compact-card-footer {
    padding: 0.75rem 1.25rem;
    background: #f8fafc;
    border-top: 1px solid #f1f5f9;
    border-bottom-left-radius: 10px;
    border-bottom-right-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.compact-label {
    font-size: 0.8125rem;
    font-weight: 600;
    color: #334155;
    margin-bottom: 0.25rem;
}
.compact-input {
    font-size: 0.875rem;
    padding: 0.4rem 0.65rem;
    border-radius: 6px;
    border: 1px solid #cbd5e1;
}
.compact-input:focus {
    border-color: #25d366;
    box-shadow: 0 0 0 2px rgba(37, 211, 102, 0.15);
}
.group-dropdown-row {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.45rem 0.75rem;
    cursor: pointer;
    user-select: none;
    border-radius: 4px;
    font-size: 0.82rem;
    color: #1e293b;
    transition: background-color 0.12s ease;
    white-space: normal;
}
.group-dropdown-row:hover {
    background-color: #f1f5f9;
}
.group-dropdown-row.is-selected {
    background-color: #ecfdf5;
    color: #065f46;
    font-weight: 600;
}
.group-dropdown-row .group-checkbox {
    width: 16px;
    height: 16px;
    margin: 0 !important;
    padding: 0 !important;
    float: none !important;
    position: static !important;
    accent-color: #10b981;
    flex-shrink: 0;
    cursor: pointer;
}
.sub-divider {
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    margin: 1rem 0 0.6rem;
    padding-bottom: 0.3rem;
    border-bottom: 1px dashed #e2e8f0;
}
.attr-collapse-toggle {
    cursor: pointer;
    user-select: none;
    transition: background 0.15s ease;
    border-radius: 6px;
    padding: 0.4rem 0.65rem;
    margin: 0.75rem 0 0.35rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
}
.attr-collapse-toggle:hover {
    background: #f1f5f9;
}
.attr-collapse-toggle .collapse-icon {
    transition: transform 0.2s ease;
}
.attr-collapse-toggle[aria-expanded="true"] .collapse-icon {
    transform: rotate(180deg);
}
.content-header {
    padding-top: 0.35rem !important;
    padding-bottom: 0.25rem !important;
}
.page-intro-crumb {
    margin-bottom: 0.1rem !important;
    font-size: 0.72rem !important;
}
.page-header-row {
    margin-bottom: 0 !important;
}
.page-header-row h1 {
    font-size: 1.15rem !important;
    line-height: 1.2 !important;
}
.content {
    padding-top: 0.25rem !important;
}
</style>

<div class="compact-contact-container">
    <form action="<?= $action ?>" method="post" id="contactCompactForm" autocomplete="off">
        <?= csrf_field() ?>

        <!-- Hidden Defaults -->
        <input type="hidden" name="wa_opt_in" value="1">
        <input type="hidden" name="wa_opt_in_source" value="direct_input">
        <input type="hidden" name="assigned_to" value="<?= esc((string) ($contact['assigned_to'] ?? '')) ?>">

        <div class="compact-card">
            <!-- Body -->
            <div class="compact-card-body pt-3">
                <div class="d-flex align-items-center justify-content-between mb-2 pb-1 border-bottom">
                    <span class="small fw-semibold text-dark">
                        <i class="fas fa-id-card text-success me-1"></i> Contact Information
                    </span>
                    <span class="text-muted" style="font-size:0.75rem;">
                        <span class="text-danger">*</span> Required fields
                    </span>
                </div>
                <div class="row g-2">
                    <!-- Name & Mobile -->
                    <div class="col-md-6">
                        <label class="compact-label">Full Name</label>
                        <input type="text" name="name" class="form-control compact-input" value="<?= $getVal('name') ?>" maxlength="150" placeholder="e.g. Rahul Sharma">
                    </div>
                    <div class="col-md-6">
                        <label class="compact-label d-flex justify-content-between align-items-center">
                            <span>WhatsApp Mobile <span class="text-danger">*</span></span>
                            <span class="text-muted fw-normal" id="mobileDigitHint" style="font-size:0.75rem;">10 digits for India</span>
                        </label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light text-success px-2"><i class="fab fa-whatsapp"></i></span>
                            <select name="country_code" id="countryCodeSelect" class="form-select compact-input fw-semibold" style="max-width: 155px; border-top-right-radius: 0; border-bottom-right-radius: 0;" required>
                                <?php foreach ($countries as $c): ?>
                                    <option value="<?= esc($c['dial_code']) ?>" 
                                            data-min="<?= (int)$c['min_digits'] ?>" 
                                            data-max="<?= (int)$c['max_digits'] ?>" 
                                            data-iso="<?= esc($c['iso2']) ?>"
                                            data-name="<?= esc($c['name']) ?>"
                                            <?= ($selectedDialCode === (string)$c['dial_code']) ? 'selected' : '' ?>>
                                        +<?= esc($c['dial_code']) ?> (<?= esc($c['iso2']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="mobile" id="mobileInput" class="form-control compact-input fw-semibold" value="<?= esc($localMobileVal) ?>" required maxlength="25" placeholder="e.g. 9876543210" inputmode="tel">
                        </div>
                        <div id="mobileFeedback" class="small mt-1" style="font-size:0.75rem; display:none;"></div>
                    </div>

                    <!-- Email & Country -->
                    <div class="col-md-6">
                        <label class="compact-label">Email Address</label>
                        <input type="email" name="email" class="form-control compact-input" value="<?= $getVal('email') ?>" placeholder="name@example.com">
                    </div>
                    <div class="col-md-3 col-6">
                        <label class="compact-label">Country</label>
                        <input type="text" name="country" id="countryNameInput" class="form-control compact-input" value="<?= $getVal('country', ($detected['country']['name'] ?? 'India')) ?>" maxlength="80">
                    </div>
                    <div class="col-md-3 col-6">
                        <label class="compact-label">Status</label>
                        <select name="status" class="form-select compact-input py-1">
                            <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'blocked' => 'Blocked'] as $st => $stLabel): ?>
                                <option value="<?= $st ?>" <?= ($contact['status'] ?? 'active') === $st ? 'selected' : '' ?>><?= $stLabel ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Birthday -->
                    <div class="col-md-4 col-sm-6">
                        <label class="compact-label">Birthday</label>
                        <input type="date" name="birthday" class="form-control compact-input py-1" value="<?= $getVal('birthday') ?>">
                    </div>

                    <!-- Customer Groups -->
                    <div class="col-md-4 col-sm-6">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <label class="compact-label mb-0">Customer Groups / Lists</label>
                            <a href="<?= site_url('customer-groups') ?>" target="_blank" class="small text-decoration-none" style="font-size:0.75rem;">+ New Group</a>
                        </div>
                        <?php if (empty($tags)): ?>
                            <div class="text-muted small py-1" style="font-size:0.78rem;">
                                No groups yet. <a href="<?= site_url('customer-groups') ?>" target="_blank">Create group</a>
                            </div>
                        <?php else: ?>
                            <div class="dropdown custom-multi-select" id="groupDropdownWrap">
                                <button class="form-select text-start d-flex justify-content-between align-items-center compact-input w-100 py-1" type="button" id="groupDropdownBtn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="min-height: 33px;">
                                    <span class="text-truncate" id="groupDropdownText">Select Customer Groups</span>
                                </button>
                                <div class="dropdown-menu p-2 shadow-sm w-100" aria-labelledby="groupDropdownBtn" style="max-height: 280px; overflow-y: auto; z-index: 1050;">
                                    <div class="p-1 mb-1 border-bottom">
                                        <input type="text" id="searchGroupFilter" class="form-control form-control-sm py-1 px-2" placeholder="Search groups..." autocomplete="off">
                                    </div>
                                    <div id="groupsList">
                                        <?php foreach ($tags as $tag): ?>
                                            <?php $isTagSelected = in_array($tag['id'], $selectedTags, false); ?>
                                            <label class="dropdown-item group-dropdown-row <?= $isTagSelected ? 'is-selected' : '' ?>" for="group_tag_<?= (int) $tag['id'] ?>">
                                                <input type="checkbox" class="group-checkbox" name="tag_ids[]" id="group_tag_<?= (int) $tag['id'] ?>" value="<?= (int) $tag['id'] ?>" data-name="<?= esc($tag['name'], 'attr') ?>" <?= $isTagSelected ? 'checked' : '' ?>>
                                                <span class="group-name text-truncate"><?= esc($tag['name']) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Notes -->
                    <div class="col-12 mt-2">
                        <label class="compact-label">Internal Notes</label>
                        <textarea name="notes" class="form-control compact-input" rows="2" placeholder="Optional background or preference notes..."><?= $getVal('notes') ?></textarea>
                    </div>

                    <!-- Custom Attributes (collapsible) -->
                    <?php if ($attributeDefs !== [] || $extraFields !== []): ?>
                        <?php
                        $hasCustomValues = false;
                        foreach ($attributeDefs as $k => $d) {
                            if (isset($cf[$k]) && trim((string) $cf[$k]) !== '') {
                                $hasCustomValues = true;
                                break;
                            }
                        }
                        if (! $hasCustomValues && ! empty($extraFields)) {
                            foreach ($extraFields as $k => $v) {
                                if (trim((string) $v) !== '') {
                                    $hasCustomValues = true;
                                    break;
                                }
                            }
                        }
                        $isExpanded = $hasCustomValues;
                        ?>
                        <div class="col-12 mt-2">
                            <div class="attr-collapse-toggle d-flex align-items-center justify-content-between"
                                 data-bs-toggle="collapse"
                                 data-bs-target="#customAttributesCollapse"
                                 aria-expanded="<?= $isExpanded ? 'true' : 'false' ?>"
                                 aria-controls="customAttributesCollapse">
                                <span class="d-inline-flex align-items-center gap-2 small fw-bold text-dark">
                                    <i class="fas fa-sliders-h text-primary"></i>
                                    <span>Custom Attributes</span>
                                    <span class="badge bg-light text-muted border fw-normal" style="font-size:0.7rem;">Optional</span>
                                    <i class="fas fa-chevron-down text-muted collapse-icon" style="font-size:0.72rem;"></i>
                                </span>
                                <?php if (function_exists('can') && can('contacts.view')): ?>
                                    <a href="<?= site_url('attributes') ?>" class="text-muted fw-normal" target="_blank" style="font-size:0.72rem; text-decoration:none;" onclick="event.stopPropagation();">
                                        <i class="fas fa-cog me-1"></i> Manage
                                    </a>
                                <?php endif; ?>
                            </div>

                            <div class="collapse <?= $isExpanded ? 'show' : '' ?> pt-1" id="customAttributesCollapse">
                                <div class="row g-2">
                                    <?php foreach ($attributeDefs as $key => $def): ?>
                                        <?php
                                        $attrVal = $cf[$key] ?? '';
                                        $attrVal = is_scalar($attrVal) ? (string) $attrVal : json_encode($attrVal);
                                        $type    = (string) $def['type'];
                                        $default = (string) ($def['default_value'] ?? '');
                                        $inputId = 'attr_' . $key;
                                        ?>
                                        <div class="col-md-6">
                                            <label class="compact-label small text-muted" for="<?= esc($inputId, 'attr') ?>"><?= esc($def['label']) ?></label>
                                            <input type="hidden" name="attr_key[]" value="<?= esc($key, 'attr') ?>">
                                            <?php if ($type === 'dropdown' || $type === 'boolean'): ?>
                                                <?php
                                                $options = $type === 'boolean' ? ['Yes', 'No'] : (array) $def['options'];
                                                if ($attrVal !== '' && ! in_array($attrVal, $options, true)) {
                                                    $options[] = $attrVal;
                                                }
                                                ?>
                                                <select name="attr_value[]" id="<?= esc($inputId, 'attr') ?>" class="form-select compact-input py-1">
                                                    <option value=""><?= $isCreate && $default !== '' ? esc('Default: ' . $default) : '—' ?></option>
                                                    <?php foreach ($options as $opt): ?>
                                                        <option value="<?= esc($opt, 'attr') ?>"<?= $attrVal === (string) $opt ? ' selected' : '' ?>><?= esc($opt) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php else: ?>
                                                <input name="attr_value[]" id="<?= esc($inputId, 'attr') ?>" class="form-control compact-input"
                                                    type="<?= $type === 'date' ? 'date' : 'text' ?>"
                                                    <?= $type === 'number' ? 'inputmode="decimal"' : '' ?>
                                                    value="<?= esc($attrVal, 'attr') ?>"
                                                    placeholder="<?= esc($isCreate && $default !== '' ? 'Default: ' . $default : '', 'attr') ?>">
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <?php if ($extraFields !== []): ?>
                                    <div id="attrRows" class="mt-2">
                                        <?php foreach ($extraFields as $k => $v): ?>
                                            <div class="row g-2 align-items-center mb-1 attr-row">
                                                <div class="col-4">
                                                    <input type="text" name="attr_key[]" class="form-control-plaintext small text-muted py-0" value="<?= esc((string) $k) ?>" readonly>
                                                </div>
                                                <div class="col-7">
                                                    <input type="text" name="attr_value[]" class="form-control compact-input" value="<?= esc(is_scalar($v) ? (string) $v : json_encode($v)) ?>" placeholder="Value">
                                                </div>
                                                <div class="col-1 text-end">
                                                    <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2 btn-remove-attr" title="Remove">&times;</button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

            <!-- Footer Action Bar -->
            <div class="compact-card-footer d-flex justify-content-between align-items-center">
                <div>
                    <?php if ($isEdit && !empty($contact['id']) && (!function_exists('can') || can('contacts.delete'))): ?>
                        <button type="button" class="btn btn-sm btn-outline-danger" data-confirm-delete data-url="<?= site_url('contacts/' . (int) $contact['id'] . '/delete') ?>" data-title="Delete Contact?" data-text="Are you sure you want to delete this contact?">
                            <i class="fas fa-trash-alt me-1"></i> Delete Contact
                        </button>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= site_url('contacts') ?>" class="btn btn-sm btn-outline-secondary px-3">
                        Cancel
                    </a>
                    <button type="submit" class="btn btn-wa btn-sm px-4">
                        <i class="fas fa-save me-1"></i> <?= $isEdit ? 'Update Contact' : 'Save Contact' ?>
                    </button>
                </div>
            </div>
        </div>

    </form>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function ($) {
    'use strict';

    function updateDropdownText() {
        var $checked = $('.group-checkbox:checked');
        if ($checked.length === 0) {
            $('#groupDropdownText').html('<span class="text-muted">Select Customer Groups</span>');
        } else if ($checked.length <= 2) {
            var names = [];
            $checked.each(function () {
                names.push($(this).data('name') || $(this).next('.group-name').text().trim());
            });
            $('#groupDropdownText').html('<span class="fw-medium text-dark">' + names.join(', ') + '</span>');
        } else {
            $('#groupDropdownText').html('<span class="badge bg-success me-1">' + $checked.length + ' selected</span> <span class="fw-medium text-dark">' + $checked.first().data('name') + ' +' + ($checked.length - 1) + ' more</span>');
        }
    }
    updateDropdownText();

    // Group checkbox multi-selection toggle
    $(document).on('change', '.group-checkbox', function () {
        var isChecked = $(this).prop('checked');
        $(this).closest('.group-dropdown-row').toggleClass('is-selected', isChecked);
        updateDropdownText();
    });

    // Instant search filter inside dropdown
    $('#searchGroupFilter').on('input', function (e) {
        e.stopPropagation();
        var q = $(this).val().toLowerCase().trim();
        $('#groupsList .group-dropdown-row').each(function () {
            var name = $(this).find('.group-name').text().toLowerCase();
            $(this).toggle(name.indexOf(q) !== -1);
        });
    });

    // Prevent dropdown from closing when clicking in search input
    $('#searchGroupFilter').on('click keydown', function (e) {
        e.stopPropagation();
    });

    // Remove custom attribute row
    $('#attrRows').on('click', '.btn-remove-attr', function () {
        $(this).closest('.attr-row').remove();
    });

    // On Load: Keep collapsed by default; only expand if any attribute field has a value
    var hasAttrValue = false;
    $('#customAttributesCollapse').find('input[name="attr_value[]"], select[name="attr_value[]"]').each(function () {
        if ($.trim($(this).val()) !== '') {
            hasAttrValue = true;
            return false;
        }
    });

    if (hasAttrValue) {
        $('#customAttributesCollapse').addClass('show');
        $('.attr-collapse-toggle').attr('aria-expanded', 'true');
    } else {
        $('#customAttributesCollapse').removeClass('show');
        $('.attr-collapse-toggle').attr('aria-expanded', 'false');
    }

    // Dynamic Country Code and digit length validation
    function updateCountryRule() {
        var $opt = $('#countryCodeSelect option:selected');
        var min = parseInt($opt.data('min'), 10) || 10;
        var max = parseInt($opt.data('max'), 10) || 10;
        var cName = $opt.data('name') || '';
        var dial = $opt.val();

        var expectedStr = (min === max) ? (min + ' digits') : (min + '–' + max + ' digits');
        $('#mobileDigitHint').text(expectedStr + ' (' + cName + ')');

        if (!$('#countryNameInput').val() || $('#countryNameInput').data('auto-synced')) {
            $('#countryNameInput').val(cName).data('auto-synced', true);
        }

        validateMobileInput();
    }

    function validateMobileInput() {
        var $opt = $('#countryCodeSelect option:selected');
        var min = parseInt($opt.data('min'), 10) || 10;
        var max = parseInt($opt.data('max'), 10) || 10;
        var val = $('#mobileInput').val().replace(/\D/g, '');
        var $fb = $('#mobileFeedback');

        if (!val) {
            $fb.hide();
            $('#mobileInput').removeClass('is-invalid is-valid');
            return;
        }

        // If user typed dial code in mobileInput (e.g. 919876543210 or 09876543210), auto clean
        var dial = String($opt.val());
        if (val.indexOf(dial) === 0 && val.length > dial.length && (val.length - dial.length) >= min) {
            val = val.substring(dial.length);
            $('#mobileInput').val(val);
        } else if (val.indexOf('0') === 0 && val.length > min) {
            val = val.replace(/^0+/, '');
            $('#mobileInput').val(val);
        }

        var len = val.length;
        if (len < min || len > max) {
            var exp = (min === max) ? (min + ' digits required') : (min + ' to ' + max + ' digits required');
            $fb.removeClass('text-success').addClass('text-danger').text('Invalid length: ' + len + ' entered (' + exp + ')').show();
            $('#mobileInput').addClass('is-invalid').removeClass('is-valid');
        } else {
            $fb.removeClass('text-danger').addClass('text-success').text('Valid: +' + dial + ' ' + val).show();
            $('#mobileInput').removeClass('is-invalid').addClass('is-valid');
        }
    }

    $('#countryCodeSelect').on('change', updateCountryRule);
    $('#mobileInput').on('input', validateMobileInput);
    $('#countryNameInput').on('input', function () {
        $(this).data('auto-synced', false);
    });

    updateCountryRule();
})(jQuery);
</script>
<?= $this->endSection() ?>
