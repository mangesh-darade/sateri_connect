<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Full contacts CSV: core fields, groups, WhatsApp consent and one column per attribute.
 * Honours the same filters as the contacts list.
 */
class ContactExportService
{
    /**
     * @param array<string, mixed> $filters output of ContactListFilter::fromInput()
     */
    public function csv(array $filters): string
    {
        $db   = db_connect();
        $rows = ContactListFilter::apply(
            $db->table('contacts c')
                ->select('c.*, GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ", ") AS groups_list')
                ->join('contact_tags ct', 'ct.contact_id = c.id', 'left')
                ->join('tags t', 't.id = ct.tag_id', 'left')
                ->where('c.deleted_at', null)
                ->groupBy('c.id')
                ->orderBy('c.id', 'ASC'),
            $filters
        )->get()->getResultArray();

        $consent = service('whatsAppConsent')->hasConsentColumns();

        // Attribute columns: defined attributes first, then any other key present in the export.
        $attrKeys = array_keys(service('contactAttributes')->definitions());
        foreach ($rows as &$row) {
            $cf = json_decode((string) ($row['custom_fields'] ?? ''), true);
            $row['custom_fields'] = is_array($cf) ? $cf : [];
            foreach (array_keys($row['custom_fields']) as $key) {
                $key = (string) $key;
                if ($key !== '' && ! str_starts_with($key, '_') && ! in_array($key, $attrKeys, true)) {
                    $attrKeys[] = $key;
                }
            }
        }
        unset($row);

        $header = ['id', 'name', 'mobile', 'email', 'country', 'status', 'groups', 'birthday', 'notes'];
        if ($consent) {
            array_push($header, 'whatsapp_opt_in', 'opt_in_source', 'opt_in_at', 'opted_out_at');
        }
        array_push($header, 'last_message_at', 'created_at');
        $header = array_merge($header, $attrKeys);

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // Excel: read Marathi / Hindi names as UTF-8
        fputcsv($out, array_map([$this, 'safeCell'], $header), ',', '"', '');
        foreach ($rows as $c) {
            $line = [
                $c['id'], $c['name'] ?? '', $c['mobile'] ?? '', $c['email'] ?? '', $c['country'] ?? '',
                $c['status'] ?? '', $c['groups_list'] ?? '', $c['birthday'] ?? '', $c['notes'] ?? '',
            ];
            if ($consent) {
                $optedOut = ! empty($c['wa_opted_out_at']);
                array_push(
                    $line,
                    $optedOut ? 'Opted out' : ((int) ($c['wa_opt_in'] ?? 0) === 1 ? 'Yes' : 'No'),
                    $c['wa_opt_in_source'] ?? '',
                    $c['wa_opt_in_at'] ?? '',
                    $c['wa_opted_out_at'] ?? ''
                );
            }
            array_push($line, $c['last_message_at'] ?? '', $c['created_at'] ?? '');
            foreach ($attrKeys as $key) {
                $v = $c['custom_fields'][$key] ?? '';
                $line[] = is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            fputcsv($out, array_map([$this, 'safeCell'], $line), ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * Stop spreadsheet formula injection (=, @, and +/- not followed by a number).
     */
    public static function safeCell(mixed $value): string
    {
        $value = (string) $value;
        if ($value !== '' && (in_array($value[0], ['=', '@', "\t", "\r"], true) || (in_array($value[0], ['+', '-'], true) && ! is_numeric(substr($value, 1))))) {
            return "'" . $value;
        }

        return $value;
    }
}
