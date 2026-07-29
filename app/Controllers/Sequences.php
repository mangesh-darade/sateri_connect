<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\SequenceService;
use CodeIgniter\HTTP\ResponseInterface;

class Sequences extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('sequences.view')) {
            return $denied;
        }

        $db = db_connect();
        $rows = [];
        if ($db->tableExists('message_sequences')) {
            $rows = $db->table('message_sequences s')
                ->select('s.*, (SELECT COUNT(*) FROM sequence_steps st WHERE st.sequence_id = s.id) AS step_count,
                    (SELECT COUNT(*) FROM sequence_enrollments e WHERE e.sequence_id = s.id AND e.status = \'active\') AS active_enrollments')
                ->orderBy('s.id', 'DESC')
                ->get()
                ->getResultArray();
        }

        return $this->render('sequences/index', [
            'pageTitle' => 'Sequences',
            'sequences' => $rows,
        ]);
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

        return redirect()->back()->with('success', 'Contact enrolled.');
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('sequences.delete')) {
            return $denied;
        }

        $db = db_connect();
        $db->table('sequence_enrollments')->where('sequence_id', $id)->delete();
        $db->table('sequence_steps')->where('sequence_id', $id)->delete();
        $db->table('message_sequences')->where('id', $id)->delete();

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
