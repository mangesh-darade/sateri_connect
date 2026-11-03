<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Inbox rules: auto-assign new chats to the least busy agent in a pool, and the first-response target
 * (SLA) in minutes. Stored as three settings keys (group "inbox_rules").
 */
class InboxRules
{
    public const MAX_SLA_MINUTES = 1440;

    private SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        $this->settings = $settings ?? service('settingsService');
    }

    /**
     * @return array{auto_assign:bool,agent_ids:list<int>,sla_minutes:int,csat_enabled:bool,csat_question:string,csat_thanks:string}
     */
    public function config(): array
    {
        $ids = json_decode((string) $this->settings->get('ir_agent_ids', ''), true);

        return [
            'auto_assign'   => in_array(strtolower(trim((string) $this->settings->get('ir_auto_assign', '0'))), ['1', 'true', 'yes', 'on'], true),
            'agent_ids'     => is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids)))) : [],
            'sla_minutes'   => max(0, min(self::MAX_SLA_MINUTES, (int) $this->settings->get('ir_sla_minutes', 0))),
            'csat_enabled'  => in_array(strtolower(trim((string) $this->settings->get('ir_csat_enabled', '0'))), ['1', 'true', 'yes', 'on'], true),
            'csat_question' => (string) $this->settings->get('ir_csat_question', 'How would you rate our support? Reply with a number from 1 (poor) to 5 (excellent).'),
            'csat_thanks'   => (string) $this->settings->get('ir_csat_thanks', 'Thank you for your feedback!'),
        ];
    }

    public function slaMinutes(): int
    {
        return $this->config()['sla_minutes'];
    }

    /**
     * @param array{auto_assign?:mixed,agent_ids?:mixed,sla_minutes?:mixed,csat_enabled?:mixed,csat_question?:mixed,csat_thanks?:mixed} $in
     *
     * @return array{ok:bool,status:int,message:string}
     */
    public function save(array $in): array
    {
        $sla = (int) ($in['sla_minutes'] ?? 0);
        if ($sla < 0 || $sla > self::MAX_SLA_MINUTES) {
            return ['ok' => false, 'status' => 422, 'message' => 'Response time target must be between 0 and ' . self::MAX_SLA_MINUTES . ' minutes.'];
        }

        // Only active users can be in the pool.
        $wanted = array_values(array_unique(array_filter(array_map('intval', (array) ($in['agent_ids'] ?? [])))));
        $active = array_map('intval', array_column(model(\App\Models\UserModel::class)->getActiveUsers(), 'id'));
        $ids    = array_values(array_intersect($wanted, $active));

        $enabled = ! empty($in['auto_assign']);
        if ($enabled && $ids === []) {
            return ['ok' => false, 'status' => 422, 'message' => 'Pick at least one agent for auto-assign.'];
        }

        $csatOn   = ! empty($in['csat_enabled']);
        $question = trim(mb_substr((string) ($in['csat_question'] ?? ''), 0, 500));
        if ($csatOn && $question === '') {
            return ['ok' => false, 'status' => 422, 'message' => 'Enter the satisfaction question to switch CSAT on.'];
        }

        $this->settings->set('ir_csat_enabled', $csatOn ? '1' : '0', 'inbox_rules');
        $this->settings->set('ir_csat_question', $question, 'inbox_rules');
        $this->settings->set('ir_csat_thanks', trim(mb_substr((string) ($in['csat_thanks'] ?? ''), 0, 500)), 'inbox_rules');
        $this->settings->set('ir_auto_assign', $enabled ? '1' : '0', 'inbox_rules');
        $this->settings->set('ir_agent_ids', (string) json_encode($ids), 'inbox_rules');
        $this->settings->set('ir_sla_minutes', (string) $sla, 'inbox_rules');

        return ['ok' => true, 'status' => 200, 'message' => 'Inbox rules saved.'];
    }

    /**
     * The pool agent with the fewest open / pending chats right now (lowest id on a tie), or null when
     * auto-assign is off or nobody in the pool is active.
     */
    public function pickAgent(): ?int
    {
        $cfg = $this->config();
        if (! $cfg['auto_assign'] || $cfg['agent_ids'] === []) {
            return null;
        }

        $open = "'" . InboxStatus::OPEN . "','" . InboxStatus::PENDING . "'";
        $row  = db_connect()->table('users u')
            ->select('u.id, COUNT(cv.id) AS open_chats')
            ->join('conversations cv', "cv.assigned_to = u.id AND cv.status IN ({$open})", 'left')
            ->where('u.status', 'active')
            ->whereIn('u.id', $cfg['agent_ids'])
            ->groupBy('u.id')
            ->orderBy('open_chats', 'ASC')
            ->orderBy('u.id', 'ASC')
            ->limit(1)
            ->get()
            ->getRowArray();

        return is_array($row) ? (int) $row['id'] : null;
    }
}
