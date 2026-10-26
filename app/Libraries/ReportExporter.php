<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Turns one table of report rows into a download: CSV, Excel (an HTML table Excel opens as a sheet, so no
 * library is needed) or a print-ready page that opens the browser's "Save as PDF" dialog.
 */
final class ReportExporter
{
    public const FORMATS = ['csv', 'excel', 'pdf'];

    public static function format(?string $raw): string
    {
        $f = strtolower(trim((string) $raw));

        return in_array($f, self::FORMATS, true) ? $f : 'csv';
    }

    /**
     * @param list<string>            $headers
     * @param list<list<scalar|null>> $rows
     * @param list<list<scalar|null>> $footer  extra rows under the table (totals)
     * @param array<string,string>    $meta    label => value lines shown above the table
     */
    public static function respond(
        ResponseInterface $response,
        string $format,
        string $title,
        string $filename,
        array $headers,
        array $rows,
        array $footer = [],
        array $meta = [],
    ): ResponseInterface {
        $format = self::format($format);

        if ($format === 'csv') {
            $out = fopen('php://temp', 'r+');
            fputcsv($out, $headers);
            foreach ($rows as $r) {
                fputcsv($out, array_map([self::class, 'safe'], $r));
            }
            foreach ($footer as $r) {
                fputcsv($out, array_map([self::class, 'safe'], $r));
            }
            rewind($out);
            $csv = (string) stream_get_contents($out);
            fclose($out);

            return $response
                ->setHeader('Content-Type', 'text/csv; charset=utf-8')
                ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '.csv"')
                ->setBody("\xEF\xBB\xBF" . $csv);
        }

        $app  = (string) service('settingsService')->get('app_name', 'Sateri Connect');
        $html = self::html($app, $title, $headers, $rows, $footer, $meta, $format === 'pdf');

        if ($format === 'excel') {
            return $response
                ->setHeader('Content-Type', 'application/vnd.ms-excel; charset=utf-8')
                ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '.xls"')
                ->setBody("\xEF\xBB\xBF" . $html);
        }

        return $response
            ->setHeader('Content-Type', 'text/html; charset=utf-8')
            ->setBody($html);
    }

    /** A cell that starts with = + - @ would run as a formula in Excel. */
    private static function safe(mixed $v): string
    {
        $s = (string) ($v ?? '');

        return preg_match('/^[=+\-@\t\r]/', $s) === 1 && ! is_numeric($s) ? "'" . $s : $s;
    }

    private static function html(string $app, string $title, array $headers, array $rows, array $footer, array $meta, bool $print): string
    {
        $e = static fn (mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

        $h = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $e($title) . '</title>'
            . '<style>body{font-family:Arial,Helvetica,sans-serif;color:#222;padding:24px}'
            . 'h1{font-size:20px;margin:0 0 4px}.meta{color:#666;font-size:12px;margin:2px 0}'
            . 'table{border-collapse:collapse;width:100%;margin-top:16px;font-size:12px}'
            . 'th,td{border:1px solid #ccc;padding:6px 8px;text-align:left}th{background:#f1f3f5}'
            . 'tfoot td{font-weight:bold;background:#fafafa}'
            . '@media print{body{padding:0}@page{size:A4 landscape;margin:12mm}}</style></head><body>'
            . '<h1>' . $e($app) . ' — ' . $e($title) . '</h1>';
        foreach ($meta as $label => $value) {
            $h .= '<div class="meta">' . $e($label) . ': ' . $e($value) . '</div>';
        }
        $h .= '<div class="meta">Generated: ' . $e(format_app_datetime(app_now_storage())) . '</div>';

        $h .= '<table><thead><tr>';
        foreach ($headers as $c) {
            $h .= '<th>' . $e($c) . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $h .= '<tr>';
            foreach ($r as $c) {
                $h .= '<td>' . $e($c) . '</td>';
            }
            $h .= '</tr>';
        }
        if ($rows === []) {
            $h .= '<tr><td colspan="' . count($headers) . '">No data for this selection.</td></tr>';
        }
        $h .= '</tbody>';
        if ($footer !== []) {
            $h .= '<tfoot>';
            foreach ($footer as $r) {
                $h .= '<tr>';
                foreach ($r as $c) {
                    $h .= '<td>' . $e($c) . '</td>';
                }
                $h .= '</tr>';
            }
            $h .= '</tfoot>';
        }
        $h .= '</table>';

        if ($print) {
            $h .= '<script>window.addEventListener("load",function(){window.print();});</script>';
        }

        return $h . '</body></html>';
    }
}
