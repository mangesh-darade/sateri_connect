<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Models\ContactModel;
use App\Models\TagModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * REST API v1 Contacts Controller.
 * Handles customer creation, updates, tag assignments, and search for external integrations.
 */
class ContactsController extends BaseV1Controller
{
    /**
     * Create or update a customer contact.
     * POST /api/v1/contacts/upsert
     *
     * Body payload (JSON):
     * {
     *   "phone": "+919876543210",
     *   "name": "Mangesh Darade",
     *   "email": "mangesh@example.com",
     *   "tags": ["VIP Customer", "Website Lead"],
     *   "custom_attributes": {
     *     "city": "Pune",
     *     "order_total": "1500"
     *   }
     * }
     */
    public function upsert(): ResponseInterface
    {
        $input = $this->getJsonPayload();

        $rawPhone = (string) ($input['phone'] ?? $input['mobile'] ?? '');
        $phone    = preg_replace('/[^\d+]/', '', trim($rawPhone));

        if ($phone === '' || strlen(preg_replace('/\D/', '', $phone)) < 7) {
            return $this->respondValidationError([
                'phone' => 'A valid mobile number is required (with country code, e.g. +919876543210).',
            ]);
        }

        $contactModel = model(ContactModel::class);
        $wasCreated   = false;

        $name   = trim(strip_tags((string) ($input['name'] ?? '')));
        $email  = trim((string) ($input['email'] ?? ''));
        $notes  = trim(strip_tags((string) ($input['notes'] ?? '')));

        if ($name !== '' && mb_strlen($name) > 255) {
            return $this->respondValidationError(['name' => 'Name cannot exceed 255 characters.']);
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->respondValidationError(['email' => 'Invalid email address format.']);
        }

        if ($notes !== '' && mb_strlen($notes) > 5000) {
            return $this->respondValidationError(['notes' => 'Notes cannot exceed 5000 characters.']);
        }

        // Handle custom attributes (limit to max 50 keys to prevent resource exhaustion)
        $rawCustom = $input['custom_attributes'] ?? $input['custom_fields'] ?? [];
        $customFields = [];
        if (is_array($rawCustom)) {
            $count = 0;
            foreach ($rawCustom as $k => $v) {
                if (++$count > 50) {
                    break;
                }
                $cleanKey = substr(trim(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $k)), 0, 100);
                if ($cleanKey === '') {
                    continue;
                }
                $cleanVal = is_scalar($v) ? substr(trim((string) $v), 0, 5000) : substr(json_encode($v), 0, 5000);
                $customFields[$cleanKey] = $cleanVal;
            }
        }

        try {
            $contact = $contactModel->findOrCreateForChannel('whatsapp', $phone, [
                'name'   => $name !== '' ? $name : null,
                'mobile' => $phone,
            ], $wasCreated);

            $contactId = (int) $contact['id'];
            $updates   = [];

            if ($name !== '' && $contact['name'] !== $name) {
                $updates['name'] = $name;
            }
            if ($email !== '' && ($contact['email'] ?? '') !== $email) {
                $updates['email'] = $email;
            }
            if ($notes !== '' && ($contact['notes'] ?? '') !== $notes) {
                $updates['notes'] = $notes;
            }

            // Merge custom fields safely
            if ($customFields !== []) {
                $existingFields = [];
                if (! empty($contact['custom_fields'])) {
                    $decoded = is_string($contact['custom_fields']) ? json_decode($contact['custom_fields'], true) : $contact['custom_fields'];
                    if (is_array($decoded)) {
                        $existingFields = $decoded;
                    }
                }
                $merged = array_merge($existingFields, $customFields);
                $updates['custom_fields'] = json_encode($merged, JSON_UNESCAPED_UNICODE);
            }

            if ($updates !== []) {
                $contactModel->update($contactId, $updates);
                $contact = array_merge($contact, $updates);
            }

            // Handle Tags (max 20 tags, sanitized)
            $tagsInput = $input['tags'] ?? [];
            if (is_string($tagsInput)) {
                $tagsInput = array_map('trim', explode(',', $tagsInput));
            }

            $assignedTags = [];
            if (is_array($tagsInput) && $tagsInput !== []) {
                $tagModel = model(TagModel::class);
                $db = \Config\Database::connect();
                $tagCount = 0;

                foreach ($tagsInput as $tagName) {
                    if (++$tagCount > 20) {
                        break;
                    }
                    $tagName = trim(strip_tags((string) $tagName));
                    $tagName = substr(preg_replace('/[^\p{L}\p{N}\s_\-]/u', '', $tagName), 0, 50);
                    if ($tagName === '') {
                        continue;
                    }

                    // Find or create tag
                    $tag = $tagModel->where('name', $tagName)->first();
                    if (! $tag) {
                        $tagId = $tagModel->insert(['name' => $tagName, 'color' => '#10b981']);
                        $tag = is_numeric($tagId) ? $tagModel->find((int) $tagId) : null;
                    }

                    if ($tag && ! empty($tag['id'])) {
                        $tId = (int) $tag['id'];
                        // Attach to contact if not already attached
                        $exists = $db->table('contact_tags')
                            ->where('contact_id', $contactId)
                            ->where('tag_id', $tId)
                            ->countAllResults();

                        if ($exists === 0) {
                            $db->table('contact_tags')->insert([
                                'contact_id' => $contactId,
                                'tag_id'     => $tId,
                            ]);
                        }
                        $assignedTags[] = $tag['name'];
                    }
                }
            }

            // Trigger Automation Engine Events
            try {
                $autoEngine = service('automationEngine');
                $event = $wasCreated ? 'contact_created' : 'contact_updated';
                $autoEngine->processTrigger($event, [
                    'contact_id' => $contactId,
                    'phone'      => $phone,
                    'name'       => $contact['name'] ?? '',
                    'tags'       => $assignedTags,
                    'attributes' => $customFields,
                ]);
            } catch (Throwable $e) {
                log_message('error', 'Automation trigger failed on contact upsert: ' . $e->getMessage());
            }

            // Handle WhatsApp Consent (Opt-in / Opt-out)
            $consentService = service('whatsAppConsent');
            if (array_key_exists('opt_in', $input) || array_key_exists('wa_opt_in', $input)) {
                $rawOptIn = $input['opt_in'] ?? $input['wa_opt_in'];
                $shouldOptIn = filter_var($rawOptIn, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($shouldOptIn === null && is_numeric($rawOptIn)) {
                    $shouldOptIn = ((int) $rawOptIn) === 1;
                }

                $source = (string) ($input['opt_in_source'] ?? $input['source'] ?? 'api');

                if ($shouldOptIn === true) {
                    $consentService->optIn($contactId, $source);
                } elseif ($shouldOptIn === false) {
                    $consentService->optOut($contactId, $source);
                }
            } elseif ($wasCreated && ! empty($input['opt_in_source'])) {
                $consentService->optIn($contactId, (string) $input['opt_in_source']);
            }

            // Reload fresh contact state after consent changes
            $contact = $contactModel->find($contactId) ?? $contact;

            $currentTags = model(TagModel::class)->getForContact($contactId);

            $responseData = [
                'id'            => $contactId,
                'phone'         => $contact['mobile'] ?? $phone,
                'name'          => $contact['name'] ?? '',
                'email'         => $contact['email'] ?? '',
                'status'        => $contact['status'] ?? 'active',
                'opt_in'        => (int) ($contact['wa_opt_in'] ?? 0) === 1,
                'opt_in_source' => $contact['wa_opt_in_source'] ?? null,
                'opt_in_at'     => $contact['wa_opt_in_at'] ?? null,
                'is_opted_out'  => ! empty($contact['wa_opted_out_at']),
                'tags'          => array_column($currentTags, 'name'),
                'custom_fields' => ! empty($contact['custom_fields']) ? (is_string($contact['custom_fields']) ? json_decode($contact['custom_fields'], true) : $contact['custom_fields']) : (object) [],
                'created_at'    => $contact['created_at'] ?? date('Y-m-d H:i:s'),
                'is_new'        => $wasCreated,
            ];

            $msg = $wasCreated ? 'Customer contact created successfully.' : 'Customer contact updated successfully.';
            $status = $wasCreated ? 201 : 200;

            return $this->respondSuccess($responseData, $msg, $status);
        } catch (Throwable $e) {
            log_message('error', 'API Contacts::upsert error: ' . $e->getMessage());

            return $this->respondError('Failed to save customer contact: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Retrieve single contact details.
     * GET /api/v1/contacts/{idOrPhone}
     */
    public function show(string $idOrPhone): ResponseInterface
    {
        $idOrPhone = trim($idOrPhone);
        if ($idOrPhone === '' || mb_strlen($idOrPhone) > 50) {
            return $this->respondError('Invalid contact identifier.', 400);
        }

        $contactModel = model(ContactModel::class);

        $contact = null;
        if (ctype_digit($idOrPhone)) {
            $contact = $contactModel->find((int) $idOrPhone);
        }

        if (! $contact) {
            $cleanPhone = preg_replace('/[^\d+]/', '', $idOrPhone);
            $contact = $contactModel->findByMobile($cleanPhone);
        }

        if (! $contact) {
            return $this->respondError('Contact not found.', 404);
        }

        $contactId = (int) $contact['id'];
        $tags = model(TagModel::class)->getForContact($contactId);

        $data = [
            'id'            => $contactId,
            'phone'         => $contact['mobile'] ?? '',
            'name'          => $contact['name'] ?? '',
            'email'         => $contact['email'] ?? '',
            'status'        => $contact['status'] ?? 'active',
            'opt_in'        => (int) ($contact['wa_opt_in'] ?? 0) === 1,
            'opt_in_source' => $contact['wa_opt_in_source'] ?? null,
            'opt_in_at'     => $contact['wa_opt_in_at'] ?? null,
            'is_opted_out'  => ! empty($contact['wa_opted_out_at']),
            'opted_out_at'  => $contact['wa_opted_out_at'] ?? null,
            'tags'          => array_column($tags, 'name'),
            'custom_fields' => ! empty($contact['custom_fields']) ? (is_string($contact['custom_fields']) ? json_decode($contact['custom_fields'], true) : $contact['custom_fields']) : (object) [],
            'last_reply_at' => $contact['last_reply_at'] ?? null,
            'created_at'    => $contact['created_at'] ?? '',
        ];

        return $this->respondSuccess($data, 'Contact details loaded.');
    }

    /**
     * Update WhatsApp Opt-in / Opt-out consent for a contact.
     * POST /api/v1/contacts/{idOrPhone}/consent
     *
     * Body payload (JSON):
     * {
     *   "opt_in": true,
     *   "source": "website_form"
     * }
     */
    public function consent(string $idOrPhone): ResponseInterface
    {
        $idOrPhone = trim($idOrPhone);
        if ($idOrPhone === '' || mb_strlen($idOrPhone) > 50) {
            return $this->respondError('Invalid contact identifier.', 400);
        }

        $contactModel = model(ContactModel::class);
        $contact = null;
        if (ctype_digit($idOrPhone)) {
            $contact = $contactModel->find((int) $idOrPhone);
        }
        if (! $contact) {
            $cleanPhone = preg_replace('/[^\d+]/', '', $idOrPhone);
            $contact = $contactModel->findByMobile($cleanPhone);
        }
        if (! $contact) {
            return $this->respondError('Contact not found.', 404);
        }

        $input = $this->getJsonPayload();
        if (! array_key_exists('opt_in', $input) && ! array_key_exists('wa_opt_in', $input)) {
            return $this->respondValidationError(['opt_in' => 'Field opt_in (boolean true/false) is required.']);
        }

        $rawOptIn = $input['opt_in'] ?? $input['wa_opt_in'];
        $shouldOptIn = filter_var($rawOptIn, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($shouldOptIn === null && is_numeric($rawOptIn)) {
            $shouldOptIn = ((int) $rawOptIn) === 1;
        }

        if ($shouldOptIn === null) {
            return $this->respondValidationError(['opt_in' => 'Invalid opt_in value. Must be true or false.']);
        }

        $source = (string) ($input['source'] ?? $input['opt_in_source'] ?? 'api');
        $contactId = (int) $contact['id'];
        $consentService = service('whatsAppConsent');

        if ($shouldOptIn) {
            $consentService->optIn($contactId, $source);
            $msg = 'WhatsApp opt-in consent recorded successfully.';
        } else {
            $consentService->optOut($contactId, $source);
            $msg = 'WhatsApp opt-out recorded successfully.';
        }

        $updated = $contactModel->find($contactId);

        return $this->respondSuccess([
            'id'            => $contactId,
            'phone'         => $updated['mobile'] ?? '',
            'name'          => $updated['name'] ?? '',
            'opt_in'        => (int) ($updated['wa_opt_in'] ?? 0) === 1,
            'opt_in_source' => $updated['wa_opt_in_source'] ?? null,
            'opt_in_at'     => $updated['wa_opt_in_at'] ?? null,
            'is_opted_out'  => ! empty($updated['wa_opted_out_at']),
            'opted_out_at'  => $updated['wa_opted_out_at'] ?? null,
        ], $msg);
    }

    /**
     * Search contacts with pagination.
     * GET /api/v1/contacts/search?q=query&page=1&per_page=25
     */
    public function search(): ResponseInterface
    {
        $q       = trim(strip_tags((string) $this->request->getGet('q')));
        if (mb_strlen($q) > 100) {
            $q = mb_substr($q, 0, 100);
        }

        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(5, (int) ($this->request->getGet('per_page') ?? 25)));

        $contactModel = model(ContactModel::class);
        $builder      = $contactModel->where('deleted_at', null);

        if ($q !== '') {
            $builder->groupStart()
                ->like('name', $q)
                ->orLike('mobile', $q)
                ->orLike('email', $q)
                ->groupEnd();
        }

        $total = $builder->countAllResults(false);
        $contacts = $builder->orderBy('id', 'DESC')
            ->findAll($perPage, ($page - 1) * $perPage);

        $items = array_map(static function (array $c): array {
            return [
                'id'         => (int) $c['id'],
                'phone'      => $c['mobile'] ?? '',
                'name'       => $c['name'] ?? '',
                'email'      => $c['email'] ?? '',
                'status'     => $c['status'] ?? 'active',
                'created_at' => $c['created_at'] ?? '',
            ];
        }, $contacts);

        return $this->respondSuccess([
            'items'        => $items,
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'total_pages'  => (int) ceil($total / $perPage),
        ], 'Contacts retrieved.');
    }
}
