<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\DataTablesQuery;
use App\Libraries\SequenceService;
use CodeIgniter\HTTP\ResponseInterface;

class Sequences extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('sequences.view')) {
            return $denied;
        }

        return $this->render('sequences/index', [
            'pageTitle'    => 'Sequences',
            'hasSequences' => db_connect()->tableExists('message_sequences')
                && db_connect()->table('message_sequences')->countAllResults() > 0,
        ]);
    }

    /**
     * Server-side DataTables feed for the sequence list.
     */
    public function data(): ResponseInterface
    {
        if ($denied = $this->requirePermission('sequences.view')) {
            return $denied;
        }

        if (! db_connect()->tableExists('message_sequences')) {
            return $this->response->setJSON(['draw' => (int) $this->request->getGet('draw'), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        }

        $canEdit   = function_exists('can') && can('sequences.edit');
        $canDelete = function_exists('can') && can('sequences.delete');

        return $this->response->setJSON(DataTablesQuery::respond($this->request, [
            'builder'  => static fn () => db_connect()->table('message_sequences s')
                ->select('s.id, s.name, s.is_active')
                ->select('(SELECT COUNT(*) FROM sequence_steps st WHERE st.sequence_id = s.id) AS step_count', false)
                ->select('(SELECT COUNT(*) FROM sequence_enrollments e WHERE e.sequence_id = s.id AND e.status = \'active\') AS active_enrollments', false),
            'columns'  => [
                0 => 's.name',
                1 => '(SELECT COUNT(*) FROM sequence_steps st2 WHERE st2.sequence_id = s.id)',
                2 => '(SELECT COUNT(*) FROM sequence_enrollments e2 WHERE e2.sequence_id = s.id AND e2.status = \'active\')',
                3 => 's.is_active',
            ],
            'search'   => ['s.name'],
            'order'    => [0, 'asc'],
            'tiebreak' => 's.id',
            'map'      => static fn (array $r): array => [
                'id'          => (int) $r['id'],
                'name'        => (string) ($r['name'] ?? ''),
                'steps'       => (int) ($r['step_count'] ?? 0),
                'enrollments' => (int) ($r['active_enrollments'] ?? 0),
                'active'      => ! empty($r['is_active']),
                'edit_url'    => $canEdit ? site_url('sequences/' . (int) $r['id'] . '/edit') : '',
                'delete_url'  => $canDelete ? site_url('sequences/' . (int) $r['id'] . '/delete') : '',
            ],
        ]));
    }

    public function create(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('sequences.create')) {
            return $denied;
        }

        return $this->render('sequences/form', [
            'pageTitle' => 'Create sequence',
            'sequence'  => null,
            'steps'     => [['delay_minutes' => 0, 'message_type' => 'text', 'body_text' => '']],
        ]);
    }

    public function store(): ResponseInterface
    {
        if ($denied = $this->requirePermission('sequences.create')) {
            return $denied;
        }

        $name = trim((string) $this->request->getPost('name'));
        if ($name === '') {
            return redirect()->back()->withInput()->with('error', 'Name is required.');
        }

        $steps = $this->parseStepsFromPost();
        if ($steps === []) {
            return redirect()->back()->withInput()->with('error', 'Add at least one step.');
        }

        $id = (new SequenceService())->create(
            $name,
            $steps,
            (int) $this->request->getPost('exit_on_reply') === 1,
            'whatsapp',
            $this->userId(),
            (int) $this->request->getPost('is_active') === 1
        );

        log_activity('create', 'sequences', 'Sequence "' . $name . '" created', ['sequence_id' => (int) $id, 'steps' => count($steps)]);

        return redirect()->to(site_url('sequences/' . $id . '/edit'))->with('success', 'Sequence created.');
    }

    public function edit(int $id): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('sequences.edit')) {
            return $denied;
        }

        $db = db_connect();
        $sequence = $db->table('message_sequences')->where('id', $id)->get()->getRowArray();
        if (! $sequence) {
            return redirect()->to(site_url('sequences'))->with('error', 'Not found.');
        }
        $steps = $db->table('sequence_steps')->where('sequence_id', $id)->orderBy('step_order', 'ASC')->get()->getResultArray();

        return $this->render('sequences/form', [
            'pageTitle' => 'Edit sequence',
            'sequence'  => $sequence,
            'steps'     => $steps !== [] ? $steps : [['delay_minutes' => 0, 'message_type' => 'text', 'body_text' => '']],
        ]);
    }

    public function update(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('sequences.edit')) {
            return $denied;
        }

        $db = db_connect();
        $sequence = $db->table('message_sequences')->where('id', $id)->get()->getRowArray();
        if (! $sequence) {
            return redirect()->to(site_url('sequences'))->with('error', 'Not found.');
        }

        $name = trim((string) $this->request->getPost('name'));
        $db->table('message_sequences')->where('id', $id)->update([
            'name'          => $name !== '' ? $name : $sequence['name'],
            'is_active'     => (int) $this->request->getPost('is_active') === 1 ? 1 : 0,
            'exit_on_reply' => (int) $this->request->getPost('exit_on_reply') === 1 ? 1 : 0,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        $steps = $this->parseStepsFromPost();
        if ($steps !== []) {
            (new SequenceService())->replaceSteps($id, $steps);
        }

        log_activity('update', 'sequences', 'Sequence "' . ($name !== '' ? $name : (string) $sequence['name']) . '" updated', ['sequence_id' => $id, 'steps_replaced' => $steps !== []]);

        return redirect()->to(site_url('sequences/' . $id . '/edit'))->with('success', 'Sequence saved.');
    }

    public function enroll(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('sequences.edit')) {
            return $denied;
        }

        $contactId = (int) $this->request->getPost('contact_id');
        if ($contactId <= 0) {
            return redirect()->back()->with('error', 'contact_id required.');
        }

        try {
            (new SequenceService())->enroll($id, $contactId);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        log_activity('enroll', 'sequences', 'Contact enrolled in a sequence', ['sequence_id' => $id, 'contact_id' => $contactId]);

        return redirect()->back()->with('success', 'Contact enrolled.');
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('sequences.delete')) {
            return $denied;
        }

        $db = db_connect();
        $gone = $db->table('message_sequences')->select('name')->where('id', $id)->get()->getRowArray();
        $db->table('sequence_enrollments')->where('sequence_id', $id)->delete();
        $db->table('sequence_steps')->where('sequence_id', $id)->delete();
        $db->table('message_sequences')->where('id', $id)->delete();

        log_activity('delete', 'sequences', 'Sequence "' . (string) ($gone['name'] ?? ('#' . $id)) . '" deleted', ['sequence_id' => $id]);

        return redirect()->to(site_url('sequences'))->with('success', 'Sequence deleted.');
    }

    /**
     * @return list<array{delay_minutes:int,message_type:string,template_name:?string,language:string,body_text:?string}>
     */
    protected function parseStepsFromPost(): array
    {
        $delays = $this->request->getPost('step_delay') ?? [];
        $types  = $this->request->getPost('step_type') ?? [];
        $bodies = $this->request->getPost('step_body') ?? [];
        $tpls   = $this->request->getPost('step_template') ?? [];
        if (! is_array($delays)) {
            return [];
        }

        $out = [];
        foreach ($delays as $i => $delay) {
            $type = (string) ($types[$i] ?? 'text');
            $body = (string) ($bodies[$i] ?? '');
            $tpl  = trim((string) ($tpls[$i] ?? ''));
            if ($type === 'text' && trim($body) === '') {
                continue;
            }
            if ($type === 'template' && $tpl === '') {
                continue;
            }
            $out[] = [
                'delay_minutes' => max(0, (int) $delay),
                'message_type'  => $type === 'template' ? 'template' : 'text',
                'template_name' => $tpl !== '' ? $tpl : null,
                'language'      => 'en',
                'body_text'     => $body,
            ];
        }

        return $out;
    }
}
