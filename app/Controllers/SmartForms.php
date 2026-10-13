<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\SmartFormService;
use App\Models\SmartFormModel;
use App\Models\SmartFormResponseModel;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Admin smart forms, plus the public share page.
 */
class SmartForms extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.view')) {
            return $denied;
        }

        $forms = model(SmartFormModel::class)->orderBy('id', 'DESC')->findAll();
        $counts = [];
        if ($forms !== []) {
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $forms);
            $rows = model(SmartFormResponseModel::class)
                ->select('form_id, COUNT(*) AS total')
                ->whereIn('form_id', $ids)
                ->groupBy('form_id')
                ->findAll();
            foreach ($rows as $row) {
                $counts[(int) $row['form_id']] = (int) $row['total'];
            }
        }

        return $this->render('smart_forms/index', [
            'pageTitle'       => 'Smart Forms',
            'forms'           => $forms,
            'counts'          => $counts,
            'captchaSiteKey'  => SmartFormService::recaptchaSiteKey(),
            'captchaReady'    => SmartFormService::recaptchaReady(),
        ]);
    }

    public function create(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.create')) {
            return $denied;
        }

        return $this->render('smart_forms/form', [
            'pageTitle' => 'New form',
            'form'      => null,
            'fields'    => SmartFormService::normalizeFields([]),
            'responses' => [],
        ]);
    }

    public function saveCaptcha(): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.edit')) {
            return $denied;
        }

        $site = trim((string) $this->request->getPost('recaptcha_site_key'));
        $secret = trim((string) $this->request->getPost('recaptcha_secret_key'));
        if ($site === '' || ($secret === '' && ! SmartFormService::recaptchaReady())) {
            return redirect()->to('/smart-forms')->with('error', 'Paste both the reCAPTCHA site key and secret key.');
        }

        $settings = service('settingsService');
        $settings->set('recaptcha_site_key', $site, 'smart_forms', false);
        if ($secret !== '') {
            $settings->set('recaptcha_secret_key', $secret, 'smart_forms');
        }
        $this->logActivity('update', 'smart_forms', 'Google reCAPTCHA keys saved');

        return redirect()->to('/smart-forms')->with('success', 'reCAPTCHA saved. The I’m not a robot checkbox is on every form.');
    }

    public function store(): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.create')) {
            return $denied;
        }

        try {
            $id = (new SmartFormService())->save($this->formInput());
            $this->logActivity('create', 'smart_forms', 'Smart form created', ['form_id' => $id]);

            return redirect()->to('/smart-forms/' . $id . '/edit')->with('success', 'Form created. Share the link below.');
        } catch (RuntimeException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function edit(int $id): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.view')) {
            return $denied;
        }

        $form = model(SmartFormModel::class)->find($id);
        if (! is_array($form)) {
            return redirect()->to('/smart-forms')->with('error', 'Form not found.');
        }

        $responses = model(SmartFormResponseModel::class)
            ->where('form_id', $id)
            ->orderBy('id', 'DESC')
            ->findAll(20);

        return $this->render('smart_forms/form', [
            'pageTitle' => 'Edit form',
            'form'      => $form,
            'fields'    => model(SmartFormModel::class)->fieldsOf($form),
            'responses' => $responses,
        ]);
    }

    public function update(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.edit')) {
            return $denied;
        }

        try {
            (new SmartFormService())->save($this->formInput(), $id);
            $this->logActivity('update', 'smart_forms', 'Smart form updated', ['form_id' => $id]);

            return redirect()->to('/smart-forms/' . $id . '/edit')->with('success', 'Form saved.');
        } catch (RuntimeException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.delete')) {
            return $denied;
        }

        model(SmartFormModel::class)->delete($id);
        model(SmartFormResponseModel::class)->where('form_id', $id)->delete();
        $this->logActivity('delete', 'smart_forms', 'Smart form deleted', ['form_id' => $id]);

        return redirect()->to('/smart-forms')->with('success', 'Form deleted.');
    }

    public function showPublic(string $slug): string
    {
        $this->connectPublicTenant();
        $form = model(SmartFormModel::class)->findBySlug($slug);
        if ($form === null) {
            return $this->publicPage(null, 'This form does not exist.', false);
        }

        return $this->publicPage($form, '', false);
    }

    public function submitPublic(string $slug): ResponseInterface|string
    {
        $this->connectPublicTenant();
        $form = model(SmartFormModel::class)->findBySlug($slug);
        if ($form === null) {
            return $this->publicPage(null, 'This form does not exist.', false);
        }

        try {
            $result = (new SmartFormService())->submit($form, $this->request->getPost() ?? []);
            $this->logActivity('create', 'smart_forms', 'Smart form response received', [
                'form_id'    => (int) $form['id'],
                'contact_id' => $result['contact_id'],
            ]);

            return $this->publicPage($form, (string) ($form['success_message'] ?? 'Thank you.'), true);
        } catch (RuntimeException $e) {
            return $this->publicPage($form, $e->getMessage(), false);
        } catch (Throwable $e) {
            log_message('error', 'Smart form submit failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->publicPage($form, 'Could not submit the form. Try again.', false);
        }
    }

    private function connectPublicTenant(): void
    {
        $key = strtolower(trim((string) ($this->request->getGet('t') ?: $this->request->getPost('t'))));
        if ($key !== '') {
            \App\Libraries\TenantResolver::ensureFromPublicKey($key);

            return;
        }

        \App\Libraries\TenantResolver::ensureFromSession();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function countryOptions(): array
    {
        try {
            $rows = function_exists('countries_list') ? countries_list() : [];
        } catch (Throwable) {
            $rows = [];
        }

        return $rows !== [] ? $rows : [[
            'name' => 'India', 'iso2' => 'IN', 'dial_code' => '91', 'min_digits' => 10, 'max_digits' => 10,
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function formInput(): array
    {
        $labels = (array) $this->request->getPost('field_label');
        $types  = (array) $this->request->getPost('field_type');
        $keys   = (array) $this->request->getPost('field_key');
        $req    = (array) $this->request->getPost('field_required');
        $fields = [];
        foreach ($labels as $i => $label) {
            $fields[] = [
                'label'    => $label,
                'type'     => $types[$i] ?? 'text',
                'key'      => $keys[$i] ?? '',
                'required' => (string) ($req[$i] ?? '') === '1',
            ];
        }

        return [
            'name'            => $this->request->getPost('name'),
            'slug'            => $this->request->getPost('slug'),
            'status'          => $this->request->getPost('status'),
            'success_message' => $this->request->getPost('success_message'),
            'fields'          => $fields,
        ];
    }

    /**
     * @param array<string, mixed>|null $form
     */
    private function publicPage(?array $form, string $notice, bool $done): string
    {
        return view('smart_forms/public', [
            'title'  => (string) ($form['name'] ?? 'Form'),
            'form'   => $form,
            'fields' => is_array($form) ? model(SmartFormModel::class)->fieldsOf($form) : [],
            'notice'    => $notice,
            'done'      => $done,
            'tenant'     => strtolower(trim((string) ($this->request->getGet('t') ?: $this->request->getPost('t')))),
            'countries'  => $this->countryOptions(),
            'captchaKey' => SmartFormService::recaptchaSiteKey(),
            'captchaReady' => SmartFormService::recaptchaReady(),
        ]);
    }
}
