<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ContactModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Topbar quick search: a few contacts, templates and chat messages in one call.
 * Each group is returned only when the user holds that module's view permission.
 */
class Search extends BaseController
{
    private const PER_GROUP = 5;

    public function index(): ResponseInterface
    {
        $q = trim((string) ($this->request->getGet('q') ?? ''));
        $q = mb_substr($q, 0, 100);

        $out = ['contacts' => [], 'templates' => [], 'messages' => []];
        if (mb_strlen($q) < 2) {
            return $this->jsonResponse(true, $out);
        }

        if (can('contacts.view')) {
            foreach (model(ContactModel::class)->search($q, self::PER_GROUP) as $c) {
                $out['contacts'][] = [
                    'id'     => (int) $c['id'],
                    'name'   => (string) ($c['name'] ?? ''),
                    'mobile' => (string) ($c['mobile'] ?? ''),
                    'email'  => (string) ($c['email'] ?? ''),
                ];
            }
        }

        if (can('templates.view')) {
            $out['templates'] = db_connect()->table('templates')
                ->select('id, name, language, status')
                ->like('name', $q)
                ->orderBy('name', 'ASC')
                ->limit(self::PER_GROUP)
                ->get()
                ->getResultArray();
        }

        // Message text has no index: require 3+ chars and cap rows so the scan stays cheap.
        if (can('chat.view') && mb_strlen($q) >= 3) {
            $out['messages'] = db_connect()->table('messages m')
                ->select('m.id, m.contact_id, m.content, m.created_at, c.name AS contact_name, c.mobile')
                ->join('contacts c', 'c.id = m.contact_id')
                ->like('m.content', $q)
                ->orderBy('m.id', 'DESC')
                ->limit(self::PER_GROUP)
                ->get()
                ->getResultArray();
        }

        return $this->jsonResponse(true, $out);
    }
}
