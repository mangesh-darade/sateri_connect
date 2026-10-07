<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Libraries\Email\SesEmailDriver;
use App\Models\EmailSenderModel;
use Throwable;

/**
 * Amazon SES sending-identity onboarding: create domain / email identity, custom MAIL FROM,
 * and produce the DNS records (DKIM CNAME, SPF, MAIL FROM MX, DMARC) the client must publish.
 *
 * State is persisted in `email_senders` (provider = 'ses'): one `domain` row + optional `sender` row.
 */
class SesIdentityService
{
    public const PROVIDER = 'ses';

    protected const SPF_VALUE      = 'v=spf1 include:amazonses.com ~all';
    protected const DMARC_POLICIES = ['none', 'quarantine', 'reject'];

    protected SesEmailDriver $ses;
    protected EmailSenderModel $senders;
    protected SettingsService $settings;

    public function __construct(?SesEmailDriver $ses = null, ?SettingsService $settings = null)
    {
        $this->settings = $settings ?? new SettingsService();
        $this->ses      = $ses ?? new SesEmailDriver($this->settings);
        $this->senders  = model(EmailSenderModel::class);
    }

    /**
     * Initiate configuration of a new sending domain (+ optional From email/name).
     *
     * @param array<string, mixed> $input domain, email, name, mail_from_subdomain, dmarc_policy, dmarc_report_email
     *
     * @return array{ok: bool, status: int, message: string, data?: array<string, mixed>, errors?: array<string, string>}
     */
    public function configure(array $input): array
    {
        if ($denied = $this->requireCredentials()) {
            return $denied;
        }

        $email  = strtolower(trim((string) ($input['email'] ?? '')));
        $domain = $this->normalizeDomain((string) ($input['domain'] ?? ''));
        if ($domain === '' && $email !== '' && str_contains($email, '@')) {
            $domain = $this->normalizeDomain(substr($email, strrpos($email, '@') + 1));
        }

        $name       = trim((string) ($input['name'] ?? ''));
        $subdomain  = strtolower(trim((string) ($input['mail_from_subdomain'] ?? 'bounce')));
        $policy     = strtolower(trim((string) ($input['dmarc_policy'] ?? 'none')));
        $dmarcEmail = strtolower(trim((string) ($input['dmarc_report_email'] ?? '')));

        $errors = [];
        if (! $this->isValidDomain($domain)) {
            $errors['domain'] = 'A valid domain is required (e.g. example.com).';
        }
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (preg_match('/^[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?$/', $subdomain) !== 1) {
            $errors['mail_from_subdomain'] = 'Use a single DNS label such as "bounce" or "mail".';
        }
        if (! in_array($policy, self::DMARC_POLICIES, true)) {
            $errors['dmarc_policy'] = 'Allowed: none, quarantine, reject.';
        }
        if ($dmarcEmail !== '' && ! filter_var($dmarcEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['dmarc_report_email'] = 'Enter a valid email address.';
        }
        if ($name !== '' && mb_strlen($name) > 120) {
            $errors['name'] = 'Name must be 120 characters or fewer.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'status' => 422, 'message' => 'Validation failed.', 'errors' => $errors];
        }

        // 1. Domain identity (Easy DKIM — the DKIM CNAMEs also verify the domain).
        $create = ['EmailIdentity' => $domain];
        $configSet = trim((string) $this->settings->get('ses_configuration_set', ''));
        if ($configSet !== '') {
            $create['ConfigurationSetName'] = $configSet;
        }
        $res = $this->ses->apiRequest('POST', '/v2/email/identities', $create);
        if (! $res['ok'] && ! $this->isAlreadyExists($res)) {
            return ['ok' => false, 'status' => $this->httpStatusFor($res), 'message' => $res['error']];
        }

        // 2. Custom MAIL FROM domain (SPF alignment for DMARC). Never overwrite an existing one unless asked.
        $mailFrom = $subdomain . '.' . $domain;
        $warnings = [];
        $existingMailFrom = '';
        if ($this->isAlreadyExists($res) && trim((string) ($input['mail_from_subdomain'] ?? '')) === '') {
            $current = $this->ses->apiRequest('GET', '/v2/email/identities/' . rawurlencode($domain));
            $existingMailFrom = $current['ok'] ? (string) ($current['decoded']['MailFromAttributes']['MailFromDomain'] ?? '') : '';
        }
        if ($existingMailFrom !== '') {
            $mailFrom = $existingMailFrom;
        } else {
            $mf = $this->ses->apiRequest('PUT', '/v2/email/identities/' . rawurlencode($domain) . '/mail-from', [
                'MailFromDomain'      => $mailFrom,
                'BehaviorOnMxFailure' => 'USE_DEFAULT_VALUE',
            ]);
            if (! $mf['ok']) {
                $warnings[] = 'Custom MAIL FROM not set: ' . $mf['error'];
            }
        }

        // 3. From email outside this domain needs its own (inbox link) verification.
        $emailNeedsVerification = $email !== '' && ! $this->emailBelongsToDomain($email, $domain);
        if ($emailNeedsVerification) {
            $er = $this->ses->apiRequest('POST', '/v2/email/identities', ['EmailIdentity' => $email]);
            if (! $er['ok'] && ! $this->isAlreadyExists($er)) {
                $warnings[] = 'Email identity not created: ' . $er['error'];
            }
        }

        // 4. Persist local rows.
        $domainRow = $this->findDomainRow($domain);
        $rowData   = [
            'type'             => 'domain',
            'provider'         => self::PROVIDER,
            'name'             => $domain,
            'domain'           => $domain,
            'email'            => $email !== '' ? $email : null,
            'mail_from_domain' => $mailFrom,
            'status'           => 'pending',
            'notes'            => json_encode([
                'dmarc_policy'       => $policy,
                'dmarc_report_email' => $dmarcEmail !== '' ? $dmarcEmail : 'dmarc@' . $domain,
                'from_name'          => $name,
            ]),
        ];
        $domainId = $domainRow
            ? (int) $domainRow['id']
            : (int) $this->senders->insert($rowData, true);
        if ($domainRow) {
            $this->senders->update($domainId, $rowData);
        }

        if ($email !== '') {
            $this->upsertSenderRow($email, $name !== '' ? $name : (string) $this->settings->get('app_name', $email), $domain);
        }

        log_activity('email_identity_configured', 'emails', 'Started Amazon SES setup for ' . $domain, [
            'domain'           => $domain,
            'email'            => $email ?: null,
            'mail_from_domain' => $mailFrom,
            'sender_id'        => $domainId,
        ]);

        // 5. Fetch DKIM tokens + statuses and build DNS records.
        $view = $this->refresh($domain);
        if (! $view['ok']) {
            return $view;
        }
        if ($warnings !== []) {
            $view['data']['warnings'] = $warnings;
        }
        $view['status']  = 201;
        $view['message'] = 'Domain registered with Amazon SES. Add the DNS records below, then call the status endpoint to verify.';

        return $view;
    }

    /**
     * Re-read SES verification status, rebuild DNS records (with live DNS check), and persist.
     *
     * @return array{ok: bool, status: int, message: string, data?: array<string, mixed>}
     */
    public function refresh(string $domain, bool $liveDnsCheck = true): array
    {
        $domain = $this->normalizeDomain($domain);
        $row    = $this->findDomainRow($domain);
        if ($row === null) {
            return ['ok' => false, 'status' => 404, 'message' => 'Domain is not configured. Call POST /api/v1/email/identities first.'];
        }
        if ($denied = $this->requireCredentials()) {
            return $denied;
        }

        $res = $this->ses->apiRequest('GET', '/v2/email/identities/' . rawurlencode($domain));
        if (! $res['ok']) {
            return ['ok' => false, 'status' => $this->httpStatusFor($res), 'message' => $res['error']];
        }

        $identity = (array) $res['decoded'];
        $dkim     = (array) ($identity['DkimAttributes'] ?? []);
        $mailFrom = (array) ($identity['MailFromAttributes'] ?? []);
        $notes    = json_decode((string) ($row['notes'] ?? ''), true) ?: [];

        $mailFromDomain = (string) ($mailFrom['MailFromDomain'] ?? $row['mail_from_domain'] ?? '');
        $records = $this->buildDnsRecords(
            $domain,
            (array) ($dkim['Tokens'] ?? []),
            $mailFromDomain,
            (string) ($dkim['Status'] ?? 'PENDING'),
            (string) ($mailFrom['MailFromDomainStatus'] ?? 'PENDING'),
            (string) ($notes['dmarc_policy'] ?? 'none'),
            (string) ($notes['dmarc_report_email'] ?? 'dmarc@' . $domain)
        );
        if ($liveDnsCheck) {
            $records = $this->checkLiveDns($records);
        }

        $verification = [
            'domain_status'        => (string) ($identity['VerificationStatus'] ?? ($identity['VerifiedForSendingStatus'] ?? false ? 'SUCCESS' : 'PENDING')),
            'verified_for_sending' => (bool) ($identity['VerifiedForSendingStatus'] ?? false),
            'dkim_status'          => (string) ($dkim['Status'] ?? 'PENDING'),
            'dkim_signing_enabled' => (bool) ($dkim['SigningEnabled'] ?? false),
            'mail_from_domain'     => $mailFromDomain,
            'mail_from_status'     => (string) ($mailFrom['MailFromDomainStatus'] ?? 'PENDING'),
        ];

        $email = (string) ($row['email'] ?? '');
        if ($email !== '' && ! $this->emailBelongsToDomain($email, $domain)) {
            $er = $this->ses->apiRequest('GET', '/v2/email/identities/' . rawurlencode($email));
            $verification['email_status'] = $er['ok']
                ? (string) ($er['decoded']['VerificationStatus'] ?? (! empty($er['decoded']['VerifiedForSendingStatus']) ? 'SUCCESS' : 'PENDING'))
                : 'NOT_FOUND';
        } elseif ($email !== '') {
            $verification['email_status'] = $verification['verified_for_sending'] ? 'SUCCESS' : 'PENDING';
        }

        $status    = $this->localStatus($verification);
        $oldStatus = (string) ($row['status'] ?? 'pending');
        $now       = date('Y-m-d H:i:s');

        $this->senders->update((int) $row['id'], [
            'status'           => $status,
            'mail_from_domain' => $mailFromDomain ?: null,
            'dns_records'      => [
                'region'       => $this->ses->getRegion(),
                'verification' => $verification,
                'records'      => $records,
                'checked_at'   => $now,
            ],
            'last_checked_at'  => $now,
        ]);
        if ($email !== '') {
            $this->senders->where('type', 'sender')->where('provider', self::PROVIDER)->where('email', $email)
                ->set(['status' => ($verification['email_status'] ?? '') === 'SUCCESS' ? 'verified' : $status, 'last_checked_at' => $now])
                ->update();
        }

        if ($oldStatus !== $status) {
            log_activity('email_identity_status_changed', 'emails', sprintf('Amazon SES domain %s is now %s', $domain, $status), [
                'domain' => $domain,
                'from'   => $oldStatus,
                'to'     => $status,
            ]);
        }

        return [
            'ok'      => true,
            'status'  => 200,
            'message' => $status === 'verified' ? 'Domain is verified and ready to send.' : 'Verification pending. Publish the required DNS records; AWS checks automatically (up to 72 hours).',
            'data'    => $this->present($this->senders->find((int) $row['id'])),
        ];
    }

    /**
     * Stored DNS records only (no AWS call). Use refresh() for live status.
     *
     * @return array{ok: bool, status: int, message: string, data?: array<string, mixed>}
     */
    public function dnsRecords(string $domain, bool $refresh = true): array
    {
        if ($refresh) {
            $view = $this->refresh($domain);
            if (! $view['ok']) {
                return $view;
            }
        }

        $row = $this->findDomainRow($this->normalizeDomain($domain));
        if ($row === null) {
            return ['ok' => false, 'status' => 404, 'message' => 'Domain is not configured.'];
        }

        $data = $this->present($row);

        return [
            'ok'      => true,
            'status'  => 200,
            'message' => 'DNS records to publish at your DNS provider.',
            'data'    => [
                'domain'      => $data['domain'],
                'status'      => $data['status'],
                'dns_records' => $data['dns_records'],
                'next_steps'  => $data['next_steps'],
            ],
        ];
    }

    /**
     * Add (or update) a From address on an already-configured domain.
     *
     * @param array<string, mixed> $input email, name, purpose (transactional|marketing), is_default
     *
     * @return array{ok: bool, status: int, message: string, data?: array<string, mixed>, errors?: array<string, string>}
     */
    public function addSender(array $input): array
    {
        $email   = strtolower(trim((string) ($input['email'] ?? '')));
        $name    = trim((string) ($input['name'] ?? ''));
        $purpose = EmailSenderModel::normalizePurpose((string) ($input['purpose'] ?? ''));

        $errors = [];
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($name === '') {
            $errors['name'] = 'From name is required.';
        } elseif (mb_strlen($name) > 120) {
            $errors['name'] = 'Name must be 120 characters or fewer.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'status' => 422, 'message' => 'Validation failed.', 'errors' => $errors];
        }

        $domainRow = $this->findDomainRowForEmail($email);
        if ($domainRow === null) {
            $domain = substr($email, (int) strrpos($email, '@') + 1);

            return [
                'ok'      => false,
                'status'  => 422,
                'message' => 'Domain ' . $domain . ' is not set up yet. Add the domain first, then add sender emails on it.',
                'errors'  => ['email' => 'Domain not configured.'],
            ];
        }

        $existing = $this->senders->where('type', 'sender')->where('email', $email)->first();
        if ($existing && ! empty($existing['is_default'])
            && EmailSenderModel::normalizePurpose($existing['purpose'] ?? null) !== $purpose) {
            return [
                'ok'      => false,
                'status'  => 422,
                'message' => $email . ' is the default ' . self::purposeLabel(EmailSenderModel::normalizePurpose($existing['purpose'] ?? null)) . ' sender. Make another sender the default first, then change its use.',
                'errors'  => ['purpose' => 'Default sender — cannot change use.'],
            ];
        }

        $this->upsertSenderRow($email, $name, (string) $domainRow['domain'], $purpose);
        $sender = $this->senders->where('type', 'sender')->where('email', $email)->first();
        $this->senders->update((int) $sender['id'], ['status' => $domainRow['status'] === 'verified' ? 'verified' : 'pending']);

        log_activity('email_sender_saved', 'emails', 'Saved ' . self::purposeLabel($purpose) . ' sender ' . $name . ' <' . $email . '>', [
            'sender_id' => (int) $sender['id'],
            'domain'    => $domainRow['domain'],
            'purpose'   => $purpose,
        ]);

        if (! empty($input['is_default'])) {
            return $this->setDefaultSender((int) $sender['id']);
        }

        return ['ok' => true, 'status' => 201, 'message' => 'Sender saved.', 'data' => $this->senders->find((int) $sender['id'])];
    }

    /**
     * Mark a sender as the default for its purpose: Primary → all system/automation mail,
     * Promotional → campaigns, bulk sends and drips.
     *
     * @return array{ok: bool, status: int, message: string, data?: array<string, mixed>}
     */
    public function setDefaultSender(int $id): array
    {
        $sender = $this->senders->find($id);
        if (! $sender || ($sender['type'] ?? '') !== 'sender' || empty($sender['email'])) {
            return ['ok' => false, 'status' => 404, 'message' => 'Sender not found.'];
        }

        $purpose     = EmailSenderModel::normalizePurpose($sender['purpose'] ?? null);
        $isMarketing = $purpose === EmailSenderModel::PURPOSE_MARKETING;

        $this->senders->where('type', 'sender')->where('purpose', $purpose)->set(['is_default' => 0])->update();
        $this->senders->update($id, ['is_default' => 1]);
        $this->settings->setSesConfig($isMarketing
            ? ['marketing_from_email' => $sender['email'], 'marketing_from_name' => $sender['name']]
            : ['from_email' => $sender['email'], 'from_name' => $sender['name']]);

        log_activity('email_sender_default', 'emails', 'Default ' . self::purposeLabel($purpose) . ' sender set to ' . $sender['email'], [
            'sender_id' => $id,
            'purpose'   => $purpose,
        ]);

        $message = $isMarketing
            ? 'Default Promotional sender updated. Campaigns, bulk sends and drips will now go from ' . $sender['email'] . '.'
            : 'Default Primary sender updated. System, automation and test emails will now go from ' . $sender['email'] . '.';

        return ['ok' => true, 'status' => 200, 'message' => $message, 'data' => $this->senders->find($id)];
    }

    /**
     * Drop the per-purpose From setting when its default sender is deleted.
     * Promotional falls back to the Primary sender; Primary is kept so sending never breaks.
     *
     * @param array<string, mixed> $sender
     */
    public function forgetDefaultSender(array $sender): void
    {
        if (empty($sender['is_default']) || EmailSenderModel::normalizePurpose($sender['purpose'] ?? null) !== EmailSenderModel::PURPOSE_MARKETING) {
            return;
        }

        $this->settings->setSesConfig(['marketing_from_email' => '', 'marketing_from_name' => '']);
    }

    public static function purposeLabel(string $purpose): string
    {
        return $purpose === EmailSenderModel::PURPOSE_MARKETING ? 'Promotional' : 'Primary';
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findDomainRowForEmail(string $email): ?array
    {
        $rows = $this->senders->where('type', 'domain')->where('provider', self::PROVIDER)->findAll(200);

        foreach ($rows as $row) {
            if ($this->emailBelongsToDomain($email, (string) $row['domain'])) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listIdentities(): array
    {
        $rows = $this->senders->where('provider', self::PROVIDER)->where('type', 'domain')->orderBy('id', 'DESC')->findAll(200);

        return array_map(fn (array $row) => $this->present($row, false), $rows);
    }

    /**
     * @param list<string> $tokens
     *
     * @return list<array<string, mixed>>
     */
    protected function buildDnsRecords(
        string $domain,
        array $tokens,
        string $mailFrom,
        string $dkimStatus,
        string $mailFromStatus,
        string $dmarcPolicy,
        string $dmarcEmail
    ): array {
        $region  = $this->ses->getRegion();
        $records = [];

        foreach (array_values($tokens) as $i => $token) {
            $records[] = $this->record(
                'dkim_' . ($i + 1),
                'DKIM / Domain verification',
                'CNAME',
                $token . '._domainkey.' . $domain,
                $token . '.dkim.amazonses.com',
                $domain,
                true,
                $dkimStatus,
                'Proves you own the domain and signs every email (DKIM). All 3 CNAMEs are required.'
            );
        }

        if ($mailFrom !== '') {
            $records[] = $this->record(
                'mail_from_mx',
                'Custom MAIL FROM',
                'MX',
                $mailFrom,
                'feedback-smtp.' . $region . '.amazonses.com',
                $domain,
                true,
                $mailFromStatus,
                'Routes bounces back to SES for your MAIL FROM subdomain.',
                10
            );
            $records[] = $this->record(
                'mail_from_spf',
                'SPF',
                'TXT',
                $mailFrom,
                self::SPF_VALUE,
                $domain,
                true,
                $mailFromStatus,
                'SPF for the MAIL FROM subdomain — gives SPF alignment for DMARC.'
            );
        }

        $records[] = $this->record(
            'root_spf',
            'SPF',
            'TXT',
            $domain,
            self::SPF_VALUE,
            $domain,
            false,
            null,
            'Recommended. If an SPF record already exists, add "include:amazonses.com" to it — never publish two SPF records.'
        );

        $records[] = $this->record(
            'dmarc',
            'DMARC',
            'TXT',
            '_dmarc.' . $domain,
            sprintf('v=DMARC1; p=%s; rua=mailto:%s; fo=1', $dmarcPolicy, $dmarcEmail),
            $domain,
            true,
            null,
            'Required by Gmail / Yahoo for bulk senders. Start with p=none, move to quarantine/reject after monitoring.'
        );

        return $records;
    }

    /**
     * @return array<string, mixed>
     */
    protected function record(
        string $key,
        string $category,
        string $type,
        string $name,
        string $value,
        string $domain,
        bool $required,
        ?string $awsStatus,
        string $purpose,
        ?int $priority = null
    ): array {
        $host = $name === $domain ? '@' : (string) preg_replace('/\.' . preg_quote($domain, '/') . '$/', '', $name);

        $row = [
            'key'        => $key,
            'category'   => $category,
            'type'       => $type,
            'name'       => $name,
            'host'       => $host,
            'value'      => $value,
            'ttl'        => 3600,
            'required'   => $required,
            'aws_status' => $awsStatus,
            'purpose'    => $purpose,
        ];
        if ($priority !== null) {
            $row['priority'] = $priority;
        }

        return $row;
    }

    /**
     * Best-effort public DNS lookup so the client sees which records are already live.
     *
     * @param list<array<string, mixed>> $records
     *
     * @return list<array<string, mixed>>
     */
    protected function checkLiveDns(array $records): array
    {
        foreach ($records as &$r) {
            $r['dns_found'] = null;
            try {
                $name  = (string) $r['name'];
                $value = strtolower(rtrim((string) $r['value'], '.'));

                switch ($r['type']) {
                    case 'CNAME':
                        $found = @dns_get_record($name, DNS_CNAME) ?: [];
                        $r['dns_found'] = $this->anyMatch($found, static fn ($x) => strtolower(rtrim((string) ($x['target'] ?? ''), '.')) === $value);
                        break;

                    case 'MX':
                        $found = @dns_get_record($name, DNS_MX) ?: [];
                        $r['dns_found'] = $this->anyMatch($found, static fn ($x) => strtolower(rtrim((string) ($x['target'] ?? ''), '.')) === $value);
                        break;

                    case 'TXT':
                        $found  = @dns_get_record($name, DNS_TXT) ?: [];
                        $prefix = str_starts_with($value, 'v=dmarc1') ? 'v=dmarc1' : 'v=spf1';
                        $r['dns_found'] = $this->anyMatch($found, static function ($x) use ($prefix) {
                            $txt = strtolower((string) ($x['txt'] ?? ''));

                            return str_starts_with($txt, $prefix)
                                && ($prefix === 'v=dmarc1' || str_contains($txt, 'include:amazonses.com'));
                        });
                        break;
                }
            } catch (Throwable) {
                $r['dns_found'] = null;
            }
        }
        unset($r);

        return $records;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    protected function anyMatch(array $rows, callable $fn): bool
    {
        foreach ($rows as $row) {
            if ($fn($row)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $verification
     */
    protected function localStatus(array $verification): string
    {
        if ($verification['verified_for_sending'] || $verification['domain_status'] === 'SUCCESS') {
            return 'verified';
        }
        if (in_array($verification['domain_status'], ['FAILED'], true) || $verification['dkim_status'] === 'FAILED') {
            return 'failed';
        }

        return 'pending';
    }

    /**
     * @param array<string, mixed>|null $row
     *
     * @return array<string, mixed>
     */
    protected function present(?array $row, bool $withRecords = true): array
    {
        $row   = $row ?? [];
        $dns   = is_array($row['dns_records'] ?? null) ? $row['dns_records'] : [];
        $notes = json_decode((string) ($row['notes'] ?? ''), true) ?: [];
        $email = (string) ($row['email'] ?? '');

        $out = [
            'id'               => (int) ($row['id'] ?? 0),
            'domain'           => (string) ($row['domain'] ?? ''),
            'email'            => $email !== '' ? $email : null,
            'name'             => (string) ($notes['from_name'] ?? '') ?: null,
            'provider'         => self::PROVIDER,
            'region'           => (string) ($dns['region'] ?? $this->ses->getRegion()),
            'status'           => (string) ($row['status'] ?? 'pending'),
            'mail_from_domain' => $row['mail_from_domain'] ?? null,
            'verification'     => $dns['verification'] ?? null,
            'last_checked_at'  => $row['last_checked_at'] ?? null,
            'created_at'       => $row['created_at'] ?? null,
        ];

        if ($withRecords) {
            $out['dns_records'] = $dns['records'] ?? [];
            $out['next_steps']  = $this->nextSteps($out);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $view
     *
     * @return list<string>
     */
    protected function nextSteps(array $view): array
    {
        if ($view['status'] === 'verified') {
            $steps = ['Domain verified. Add a sender email in Settings → Email Settings and mark it as default, then send a test email.'];
            if (($view['verification']['mail_from_status'] ?? '') !== 'SUCCESS' && ! empty($view['mail_from_domain'])) {
                $steps[] = 'Add the MX + SPF TXT records for ' . $view['mail_from_domain'] . ' so bounces use your own domain (better DMARC alignment). Sending already works meanwhile.';
            }
            if (! empty($view['email']) && ($view['verification']['email_status'] ?? '') !== 'SUCCESS') {
                $steps[] = 'Open the verification email AWS sent to ' . $view['email'] . ' and click the link.';
            }

            return $steps;
        }

        $steps = [
            'Log in to your DNS provider (GoDaddy, Cloudflare, Route 53, ...) for ' . $view['domain'] . '.',
            'Add every record marked "required": true (use "host" if your provider appends the domain automatically).',
            'Wait for DNS to propagate (usually minutes, up to 72 hours), then click "Check Verification" (API: GET /api/v1/email/identities/' . $view['domain'] . ').',
        ];
        if (! empty($view['email']) && ! $this->emailBelongsToDomain((string) $view['email'], (string) $view['domain'])) {
            $steps[] = 'AWS sent a verification link to ' . $view['email'] . ' — click it to verify that address.';
        }

        return $steps;
    }

    /**
     * @return array{ok: bool, status: int, message: string}|null
     */
    protected function requireCredentials(): ?array
    {
        $cfg = $this->settings->getSesConfig();
        if (trim($cfg['access_key']) === '' || trim($cfg['secret_key']) === '') {
            return ['ok' => false, 'status' => 400, 'message' => 'Amazon SES is not configured. Save the AWS Access Key, Secret Key and Region in Settings → Email → Amazon SES first.'];
        }

        return null;
    }

    protected function upsertSenderRow(string $email, string $name, string $domain, ?string $purpose = null): void
    {
        $existing = $this->senders->where('type', 'sender')->where('email', $email)->first();
        $data     = [
            'type'     => 'sender',
            'purpose'  => $purpose ?? EmailSenderModel::normalizePurpose($existing['purpose'] ?? null),
            'provider' => self::PROVIDER,
            'name'     => mb_substr($name, 0, 191),
            'email'    => $email,
            'domain'   => $domain,
            'status'   => $existing['status'] ?? 'pending',
        ];

        $existing
            ? $this->senders->update((int) $existing['id'], $data)
            : $this->senders->insert($data);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findDomainRow(string $domain): ?array
    {
        if ($domain === '') {
            return null;
        }

        return $this->senders->where('type', 'domain')->where('provider', self::PROVIDER)->where('domain', $domain)->first();
    }

    public function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = (string) preg_replace('#^[a-z]+://#', '', $domain);
        $domain = explode('/', $domain, 2)[0];

        return rtrim($domain, '.');
    }

    protected function isValidDomain(string $domain): bool
    {
        return strlen($domain) <= 253
            && preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) === 1;
    }

    protected function emailBelongsToDomain(string $email, string $domain): bool
    {
        $emailDomain = strtolower(substr($email, (int) strrpos($email, '@') + 1));

        return $emailDomain === $domain || str_ends_with($emailDomain, '.' . $domain);
    }

    /**
     * @param array{status: int, decoded: mixed} $res
     */
    protected function isAlreadyExists(array $res): bool
    {
        $raw = (is_array($res['decoded']) ? json_encode($res['decoded']) : '') . ' ' . ($res['error'] ?? '');

        // SES v2 answers AlreadyExistsException as HTTP 400 "Email identity X already exist."
        return $res['status'] === 409 || preg_match('/AlreadyExists|already exist/i', $raw) === 1;
    }

    /**
     * @param array{status: int} $res
     */
    protected function httpStatusFor(array $res): int
    {
        return match (true) {
            $res['status'] === 0                         => 502,
            in_array($res['status'], [401, 403], true)   => 502,
            $res['status'] === 404                       => 404,
            $res['status'] === 429                       => 429,
            $res['status'] >= 400 && $res['status'] < 500 => 422,
            default                                      => 502,
        };
    }
}
