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

        $name   = trim((string) ($input['name'] ?? ''));
        $email  = trim((string) ($input['email'] ?? ''));
        $notes  = trim((string) ($input['notes'] ?? ''));

        // Handle custom attributes
        $customFields = [];
        if (! empty($input['custom_attributes']) && is_array($input['custom_attributes'])) {
            $customFields = $input['custom_attributes'];
        } elseif (! empty($input['custom_fields']) && is_array($input['custom_fields'])) {
            $customFields = $input['custom_fields'];
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
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $updates['email'] = $email;
            }
            if ($notes !== '') {
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

            // Handle Tags
            $tagsInput = $input['tags'] ?? [];
            if (is_string($tagsInput)) {
                $tagsInput = array_map('trim', explode(',', $tagsInput));
            }

            $assignedTags = [];
            if (is_array($tagsInput) && $tagsInput !== []) {
                $tagModel = model(TagModel::class);
                $db = \Config\Database::connect();

                foreach ($tagsInput as $tagName) {
                    $tagName = trim((string) $tagName);
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

            $currentTags = model(TagModel::class)->getForContact($contactId);

            $responseData = [
                'id'            => $contactId,
                'phone'         => $contact['mobile'] ?? $phone,
                'name'          => $contact['name'] ?? '',
                'email'         => $contact['email'] ?? '',
                'status'        => $contact['status'] ?? 'active',
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
        $contactModel = model(ContactModel::class);

        $contact = null;
        if (ctype_digit($idOrPhone)) {
            $contact = $contactModel->find((int) $idOrPhone);
        }

        if (! $contact) {
            $contact = $contactModel->findByMobile($idOrPhone);
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
            'tags'          => array_column($tags, 'name'),
            'custom_fields' => ! empty($contact['custom_fields']) ? (is_string($contact['custom_fields']) ? json_decode($contact['custom_fields'], true) : $contact['custom_fields']) : (object) [],
            'last_reply_at' => $contact['last_reply_at'] ?? null,
            'created_at'    => $contact['created_at'] ?? '',
        ];

        return $this->respondSuccess($data, 'Contact details loaded.');
    }

    /**
     * Search contacts with pagination.
     * GET /api/v1/contacts/search?q=query&page=1&per_page=25
     */
    public function search(): ResponseInterface
    {
        $q       = trim((string) $this->request->getGet('q'));
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
