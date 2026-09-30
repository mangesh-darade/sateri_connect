<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\Database\BaseBuilder;

/**
 * Contact list filters shared by the contacts table, contact export and inbox filters.
 */
class ContactListFilter
{
    public const ATTRIBUTE_OPS = [
        'equals'      => 'is',
        'not_equals'  => 'is not',
        'contains'    => 'contains',
        'starts_with' => 'starts with',
        'not_empty'   => 'has any value',
        'is_empty'    => 'is empty',
    ];

    /**
     * Normalise request input (GET params) into filter values.
     *
     * @param array<string, mixed> $in
     *
     * @return array{search: string, status: string, tag_id: int, assigned_to: int, consent: string, attr_key: string, attr_op: string, attr_value: string}
     */
    public static function fromInput(array $in): array
    {
        $search = $in['search'] ?? '';
        if (is_array($search)) {
            $search = $search['value'] ?? '';
        }
        $consent = (string) ($in['consent'] ?? '');
        if ($consent !== '' && ! service('whatsAppConsent')->hasConsentColumns()) {
            $consent = '';
        }
        $key = trim((string) ($in['attr_key'] ?? ''));
        $op  = (string) ($in['attr_op'] ?? 'equals');

        return [
            'search'      => trim((string) $search),
            'status'      => (string) ($in['status'] ?? ''),
            'tag_id'      => (int) ($in['tag_id'] ?? 0),
            'assigned_to' => (int) ($in['assigned_to'] ?? 0),
            'consent'     => $consent,
            'attr_key'    => preg_match(ContactAttributeService::KEY_PATTERN, $key) ? $key : '',
            'attr_op'     => isset(self::ATTRIBUTE_OPS[$op]) ? $op : 'equals',
            'attr_value'  => trim((string) ($in['attr_value'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $f output of fromInput()
     */
    public static function apply(BaseBuilder $builder, array $f, string $alias = 'c'): BaseBuilder
    {
        switch ($f['consent'] ?? '') {
            case 'opted_in':
                $builder->where("{$alias}.wa_opt_in", 1)->where("{$alias}.wa_opted_out_at", null);
                break;
            case 'no_opt_in':
                $builder->where("{$alias}.wa_opt_in", 0)->where("{$alias}.wa_opted_out_at", null);
                break;
            case 'opted_out':
                $builder->where("{$alias}.wa_opted_out_at IS NOT NULL", null, false);
                break;
            case 'suppressed':
                $builder->where("{$alias}.wa_suppressed_until >", date('Y-m-d H:i:s'));
                break;
        }
        if (($f['search'] ?? '') !== '') {
            $builder->groupStart()
                ->like("{$alias}.name", $f['search'])
                ->orLike("{$alias}.mobile", $f['search'])
                ->orLike("{$alias}.email", $f['search'])
                ->groupEnd();
        }
        if (($f['status'] ?? '') !== '') {
            $builder->where("{$alias}.status", $f['status']);
        }
        if ((int) ($f['tag_id'] ?? 0) > 0) {
            self::applyTag($builder, (int) $f['tag_id'], "{$alias}.id");
        }
        if ((int) ($f['assigned_to'] ?? 0) > 0) {
            $builder->where("{$alias}.assigned_to", (int) $f['assigned_to']);
        }
        if (($f['attr_key'] ?? '') !== '') {
            self::applyAttribute($builder, $f['attr_key'], (string) ($f['attr_op'] ?? 'equals'), (string) ($f['attr_value'] ?? ''), $alias);
        }

        return $builder;
    }

    public static function applyTag(BaseBuilder $builder, int $tagId, string $contactIdColumn): BaseBuilder
    {
        return $builder->where("{$contactIdColumn} IN (SELECT contact_id FROM contact_tags WHERE tag_id = " . $tagId . ')', null, false);
    }

    /**
     * Attribute condition on a core column or contacts.custom_fields JSON (case-insensitive).
     * A value-based operator with an empty value is ignored.
     */
    public static function applyAttribute(BaseBuilder $builder, string $key, string $op, string $value, string $alias = 'c'): BaseBuilder
    {
        if (! preg_match(ContactAttributeService::KEY_PATTERN, $key)) {
            return $builder;
        }
        $db   = $builder->db();
        $expr = in_array($key, ContactAttributes::coreKeys(), true)
            ? "{$alias}.`{$key}`"
            : "JSON_UNQUOTE(JSON_EXTRACT({$alias}.custom_fields, " . $db->escape('$."' . $key . '"') . '))';
        $expr = "LOWER(IFNULL({$expr}, ''))";
        $v    = mb_strtolower($value);

        if (! in_array($op, ['is_empty', 'not_empty'], true) && $value === '') {
            return $builder;
        }

        $sql = match ($op) {
            'is_empty'    => "({$expr} = '' OR {$expr} = 'null')",
            'not_empty'   => "({$expr} <> '' AND {$expr} <> 'null')",
            'not_equals'  => "{$expr} <> " . $db->escape($v),
            'contains'    => "{$expr} LIKE " . $db->escape('%' . $db->escapeLikeString($v) . '%') . " ESCAPE '!'",
            'starts_with' => "{$expr} LIKE " . $db->escape($db->escapeLikeString($v) . '%') . " ESCAPE '!'",
            default       => "{$expr} = " . $db->escape($v),
        };

        return $builder->where($sql, null, false);
    }
}
