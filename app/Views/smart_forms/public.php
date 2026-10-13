<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<main class="container py-5" style="max-width: 520px">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h4 mb-3"><?= esc($title) ?></h1>
            <?php if ($notice !== ''): ?>
                <div class="alert <?= $done ? 'alert-success' : 'alert-danger' ?>"><?= esc($notice) ?></div>
            <?php endif; ?>
            <?php if (is_array($form) && ! $done && (string) ($form['status'] ?? '') === 'active'): ?>
                <form method="post" action="<?= esc(site_url('f/' . $form['slug']) . ($tenant !== '' ? '?t=' . rawurlencode($tenant) : '')) ?>">
                    <?= csrf_field() ?>
                    <?php if ($tenant !== ''): ?>
                        <input type="hidden" name="t" value="<?= esc($tenant) ?>">
                    <?php endif; ?>
                    <?php foreach ($fields as $field): ?>
                        <?php $key = (string) ($field['key'] ?? ''); ?>
                        <div class="mb-3">
                            <label class="form-label" for="f-<?= esc($key) ?>"><?= esc((string) ($field['label'] ?? $key)) ?></label>
                            <?php if (($field['type'] ?? '') === 'mobile'): ?>
                                <div class="input-group">
                                    <select class="form-select" id="countryCodeSelect" name="country_code" style="max-width: 9.5rem" required>
                                        <?php foreach ($countries as $country): ?>
                                            <option value="<?= esc((string) $country['dial_code']) ?>"
                                                    data-min="<?= (int) ($country['min_digits'] ?? 10) ?>"
                                                    data-max="<?= (int) ($country['max_digits'] ?? 10) ?>"
                                                    data-name="<?= esc((string) ($country['name'] ?? '')) ?>"
                                                    <?= (string) $country['dial_code'] === '91' ? 'selected' : '' ?>>
                                                +<?= esc((string) $country['dial_code']) ?> <?= esc((string) ($country['iso2'] ?? '')) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input class="form-control" id="f-<?= esc($key) ?>" name="f[<?= esc($key) ?>]" type="text" inputmode="tel"
                                           placeholder="10 digits" <?= ! empty($field['required']) ? 'required' : '' ?>
                                           value="<?= esc(old('f.' . $key, '')) ?>">
                                </div>
                                <div class="form-text" id="mobileDigitHint"></div>
                            <?php else: ?>
                            <input class="form-control" id="f-<?= esc($key) ?>" name="f[<?= esc($key) ?>]"
                                   type="<?= ($field['type'] ?? '') === 'email' ? 'email' : 'text' ?>"
                                   <?= ! empty($field['required']) ? 'required' : '' ?>
                                   value="<?= esc(old('f.' . $key, '')) ?>">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($captchaReady)): ?>
                        <div class="alert alert-warning">This form cannot be submitted until Google reCAPTCHA is saved in Smart Forms.</div>
                    <?php else: ?>
                        <div class="mb-3">
                            <div class="g-recaptcha" data-sitekey="<?= esc($captchaKey) ?>"></div>
                        </div>
                        <button class="btn btn-success w-100" type="submit">Submit</button>
                    <?php endif; ?>
                </form>
                <?php if (! empty($captchaReady)): ?>
                <script src="https://www.google.com/recaptcha/api.js" async defer></script>
                <script>
                (function () {
                    var select = document.getElementById('countryCodeSelect');
                    var mobile = document.getElementById('f-mobile');
                    var hint = document.getElementById('mobileDigitHint');
                    if (!select || !mobile || !hint) return;

                    function rule() {
                        var opt = select.options[select.selectedIndex];
                        var min = parseInt(opt.getAttribute('data-min'), 10) || 10;
                        var max = parseInt(opt.getAttribute('data-max'), 10) || 10;
                        var label = min === max ? (min + ' digits') : (min + '–' + max + ' digits');
                        mobile.placeholder = label;
                        if (!String(mobile.value || '').replace(/\D/g, '')) {
                            hint.textContent = '';
                            hint.className = 'form-text';
                        }
                        return { min: min, max: max, dial: String(select.value || ''), label: label };
                    }

                    function localDigits() {
                        var r = rule();
                        var val = String(mobile.value || '').replace(/\D/g, '');
                        if (r.dial && val.indexOf(r.dial) === 0 && (val.length - r.dial.length) >= r.min && (val.length - r.dial.length) <= r.max) {
                            val = val.substring(r.dial.length);
                            mobile.value = val;
                        }
                        return { digits: val, rule: r };
                    }

                    function show(ok, text) {
                        hint.className = 'form-text ' + (ok ? 'text-success' : 'text-danger');
                        hint.textContent = text;
                        mobile.classList.toggle('is-invalid', !ok);
                        mobile.classList.toggle('is-valid', ok);
                    }

                    function check() {
                        var got = localDigits();
                        if (!got.digits) {
                            rule();
                            mobile.classList.remove('is-invalid', 'is-valid');
                            return true;
                        }
                        var len = got.digits.length;
                        var r = got.rule;
                        if (len < r.min || len > r.max) {
                            var exp = r.min === r.max ? r.min + ' digits' : r.min + ' to ' + r.max + ' digits';
                            show(false, '+' + r.dial + ' needs ' + exp + '. You entered ' + len + '.');
                            return false;
                        }
                        show(true, 'Valid: +' + r.dial + ' ' + got.digits);
                        return true;
                    }

                    select.addEventListener('change', check);
                    mobile.addEventListener('input', check);
                    mobile.form.addEventListener('submit', function (e) {
                        if (!check()) {
                            e.preventDefault();
                            return;
                        }
                        if (window.grecaptcha && !grecaptcha.getResponse()) {
                            e.preventDefault();
                            hint.className = 'form-text text-danger';
                            hint.textContent = 'Please confirm you are not a robot.';
                        }
                    });
                    rule();
                })();
                </script>
                <?php endif; ?>
            <?php elseif (! is_array($form)): ?>
                <p class="text-muted mb-0">This link is not valid.</p>
            <?php elseif (! $done): ?>
                <p class="text-muted mb-0">This form is paused.</p>
            <?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>
