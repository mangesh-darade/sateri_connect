<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\ContactModel;
use RuntimeException;
use Throwable;

/**
 * Multi-step WhatsApp/email message sequences (Cheerio-style drips).
 */
class SequenceService
{
    protected QueueService $queue;
    protected ContactModel $contacts;
    protected ActivityLogger $logger;

    public function __construct(
        ?QueueService $queue = null,
        ?ContactModel $contacts = null,
        ?ActivityLogger $logger = null
    ) {
        $this->queue    = $queue ?? new QueueService();
        $this->contacts = $contacts ?? model(ContactModel::class);
        $this->logger   = $logger ?? new ActivityLogger();
    }

    /**
     * @param list<array{delay_minutes?:int,message_type?:string,template_name?:string,language?:string,body_text?:string}> $steps
     */
    public function create(
        string $name,
        array $steps,
        bool $exitOnReply = true,
        string $channel = 'whatsapp',
        ?int $userId = null,
        bool $isActive = true
    ): int
    {
        $db = db_connect();
        $now = date('Y-m-d H:i:s');
        $db->table('message_sequences')->insert([
            'name'          => $name,
            'channel'       => $channel,
            'is_active'     => $isActive ? 1 : 0,
            'exit_on_reply' => $exitOnReply ? 1 : 0,
            'created_by'    => $userId,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        $sequenceId = (int) $db->insertID();
        $this->replaceSteps($sequenceId, $steps);

        return $sequenceId;
    }

    /**
     * @param list<array{delay_minutes?:int,message_type?:string,template_name?:string,language?:string,body_text?:string}> $steps
     */
    public function replaceSteps(int $sequenceId, array $steps): void
    {
        $db = db_connect();
        $db->table('sequence_steps')->where('sequence_id', $sequenceId)->delete();
        $order = 1;
        $now = date('Y-m-d H:i:s');
        foreach ($steps as $step) {
            $db->table('sequence_steps')->insert([
                'sequence_id'   => $sequenceId,
                'step_order'    => $order++,
                'delay_minutes' => max(0, (int) ($step['delay_minutes'] ?? 0)),
                'message_type'  => (string) ($step['message_type'] ?? 'text'),
                'template_name' => $step['template_name'] ?? null,
                'language'      => (string) ($step['language'] ?? 'en'),
                'body_text'     => $step['body_text'] ?? null,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }
    }

    public function enroll(int $sequenceId, int $contactId): int
    {
        $db = db_connect();
        $seq = $db->table('message_sequences')->where('id', $sequenceId)->get()->getRowArray();
        if (! $seq || empty($seq['is_active'])) {
            throw new RuntimeException('Sequence not found or inactive.');
        }
        $first = $db->table('sequence_steps')
            ->where('sequence_id', $sequenceId)
            ->orderBy('step_order', 'ASC')
            ->get()
            ->getRowArray();
        if (! $first) {
            throw new RuntimeException('Sequence has no steps.');
        }

        $delay = (int) ($first['delay_minutes'] ?? 0);
        $nextRun = date('Y-m-d H:i:s', time() + max(0, $delay) * 60);
        $now = date('Y-m-d H:i:s');

        $existing = $db->table('sequence_enrollments')
            ->where(['sequence_id' => $sequenceId, 'contact_id' => $contactId])
            ->get()
            ->getRowArray();

        if ($existing) {
            $db->table('sequence_enrollments')->where('id', (int) $existing['id'])->update([
                'current_step' => 0,
                'status'       => 'active',
                'next_run_at'  => $nextRun,
                'updated_at'   => $now,
            ]);

            return (int) $existing['id'];
        }

        $db->table('sequence_enrollments')->insert([
            'sequence_id'  => $sequenceId,
            'contact_id'   => $contactId,
            'current_step' => 0,
            'status'       => 'active',
            'next_run_at'  => $nextRun,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        return (int) $db->insertID();
    }

    /** Exit enrollments when contact replies (if sequence.exit_on_reply). */
    public function onContactReply(int $contactId): int
    {
        $db = db_connect();
        if (! $db->tableExists('sequence_enrollments')) {
            return 0;
        }

        $rows = $db->table('sequence_enrollments e')
            ->select('e.id')
            ->join('message_sequences s', 's.id = e.sequence_id')
            ->where('e.contact_id', $contactId)
            ->where('e.status', 'active')
            ->where('s.exit_on_reply', 1)
            ->get()
            ->getResultArray();

        if ($rows === []) {
            return 0;
        }

        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        $db->table('sequence_enrollments')
            ->whereIn('id', $ids)
            ->update(['status' => 'exited', 'updated_at' => date('Y-m-d H:i:s')]);

        return count($ids);
    }

    public function processDue(int $limit = 50): int
    {
        $db = db_connect();
        if (! $db->tableExists('sequence_enrollments')) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $due = $db->table('sequence_enrollments e')
            ->select('e.*, s.is_active AS sequence_active, s.channel')
            ->join('message_sequences s', 's.id = e.sequence_id')
            ->where('e.status', 'active')
            ->where('s.is_active', 1)
            ->where('e.next_run_at <=', $now)
            ->orderBy('e.next_run_at', 'ASC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        $count = 0;
        foreach ($due as $enroll) {
            try {
                if ($this->sendNextStep($enroll)) {
                    $count++;
                }
            } catch (Throwable $e) {
                log_message('error', 'Sequence enrollment #{id} failed: {msg}', [
                    'id'  => $enroll['id'] ?? 0,
                    'msg' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $enroll
     */
    protected function sendNextStep(array $enroll): bool
    {
        $db = db_connect();
        $sequenceId = (int) $enroll['sequence_id'];
        $contactId  = (int) $enroll['contact_id'];
        $stepIndex  = (int) $enroll['current_step']; // 0-based before send

        $steps = $db->table('sequence_steps')
            ->where('sequence_id', $sequenceId)
            ->orderBy('step_order', 'ASC')
            ->get()
            ->getResultArray();

        if (! isset($steps[$stepIndex])) {
            $db->table('sequence_enrollments')->where('id', (int) $enroll['id'])->update([
                'status'     => 'completed',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            return false;
        }

        $step = $steps[$stepIndex];
        $type = (string) ($step['message_type'] ?? 'text');
        if ($type === 'template') {
            $this->queue->enqueue($contactId, 'template', [
                'template_name' => (string) ($step['template_name'] ?? ''),
                'language'      => (string) ($step['language'] ?? 'en'),
                'components'    => [],
            ], null, 4);
        } else {
            $text = (string) ($step['body_text'] ?? '');
            $contact = $this->contacts->find($contactId);
            if (is_array($contact)) {
                $text = str_replace(
                    ['{{contact.name}}', '{{contact.mobile}}', '{{name}}'],
                    [(string) ($contact['name'] ?? ''), (string) ($contact['mobile'] ?? ''), (string) ($contact['name'] ?? '')],
                    $text
                );
            }
            $this->queue->enqueue($contactId, 'text', ['text' => $text], null, 4);
        }

        $nextIndex = $stepIndex + 1;
        $now = date('Y-m-d H:i:s');
        if (! isset($steps[$nextIndex])) {
            $db->table('sequence_enrollments')->where('id', (int) $enroll['id'])->update([
                'current_step' => $nextIndex,
                'status'       => 'completed',
                'last_sent_at' => $now,
                'next_run_at'  => null,
                'updated_at'   => $now,
            ]);
        } else {
            $delay = (int) ($steps[$nextIndex]['delay_minutes'] ?? 0);
            $db->table('sequence_enrollments')->where('id', (int) $enroll['id'])->update([
                'current_step' => $nextIndex,
                'last_sent_at' => $now,
                'next_run_at'  => date('Y-m-d H:i:s', time() + max(0, $delay) * 60),
                'updated_at'   => $now,
            ]);
        }

        return true;
    }
}
