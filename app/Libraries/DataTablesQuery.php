<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\HTTP\IncomingRequest;

/**
 * Server-side DataTables in one call: search, sort, filters and paging all run in SQL, so a table with
 * millions of rows only ever loads one page.
 *
 *   return $this->response->setJSON(DataTablesQuery::respond($this->request, [
 *       'builder' => fn () => db_connect()->table('message_queue q')->select('q.*, c.name AS contact_name')
 *                              ->join('contacts c', 'c.id = q.contact_id', 'left'),
 *       'columns' => [0 => 'q.id', 1 => 'c.name', 2 => 'q.status'],   // DataTables column index => SQL (only sortable ones)
 *       'search'  => ['c.name', 'c.mobile', 'q.status'],              // LIKE %term% on these
 *       'filters' => function (BaseBuilder $b, array $get): void { if (($get['status'] ?? '') !== '') { $b->where('q.status', $get['status']); } },
 *       'order'   => [0, 'desc'],
 *       'tiebreak'=> 'q.id',
 *       'map'     => fn (array $row): array => [...],                 // display-ready row (escape in JS, not here)
 *   ]));
 *
 * `builder` must return a FRESH builder each call (base table, joins and fixed conditions such as soft-delete or
 * tenant scope). It is called three times: total, filtered, page.
 */
final class DataTablesQuery
{
    public const MAX_LENGTH = 100;

    /**
     * @param array{
     *   builder: callable(): BaseBuilder,
     *   columns?: array<int, string>,
     *   search?: list<string>,
     *   filters?: callable(BaseBuilder, array<string, mixed>): void,
     *   order?: array{0:int,1:string},
     *   tiebreak?: string,
     *   map?: callable(array<string, mixed>): array<string, mixed>,
     *   count?: string,
     *   maxLength?: int
     * } $cfg
     *
     * @return array{draw:int,recordsTotal:int,recordsFiltered:int,data:list<array<string, mixed>>}
     */
    public static function respond(IncomingRequest $request, array $cfg): array
    {
        $raw       = $request->getGet() ?? [];
        // Array-valued params (?status[]=x) would raise "Array to string" warnings (HTML 500); filters only get scalars.
        $get       = array_filter($raw, 'is_scalar');
        $columns   = $cfg['columns'] ?? [];
        $rawSearch = is_array($raw['search'] ?? null) ? ($raw['search']['value'] ?? '') : '';
        $search    = is_scalar($rawSearch) ? mb_substr(trim((string) $rawSearch), 0, 200) : '';
        $start     = max(0, (int) ($get['start'] ?? 0));
        $maxLength = (int) ($cfg['maxLength'] ?? self::MAX_LENGTH);
        $length    = max(1, min($maxLength, (int) ($get['length'] ?? 10)));
        $rawOrder  = is_array($raw['order'][0] ?? null) ? $raw['order'][0] : [];
        $count     = (string) ($cfg['count'] ?? '');

        [$defCol, $defDir] = $cfg['order'] ?? [0, 'desc'];
        $colIdx = is_scalar($rawOrder['column'] ?? null) ? (int) $rawOrder['column'] : (int) $defCol;
        $dir    = strtolower(is_scalar($rawOrder['dir'] ?? null) ? (string) $rawOrder['dir'] : (string) $defDir) === 'asc' ? 'ASC' : 'DESC';
        // Only whitelisted columns can be sorted on; anything else falls back to the default.
        $orderSql = $columns[$colIdx] ?? ($columns[(int) $defCol] ?? null);

        $scope = static function (BaseBuilder $b) use ($cfg, $get, $search): BaseBuilder {
            if (isset($cfg['filters'])) {
                ($cfg['filters'])($b, $get);
            }
            if ($search !== '' && ! empty($cfg['search'])) {
                $b->groupStart();
                foreach (array_values($cfg['search']) as $i => $col) {
                    $i === 0 ? $b->like($col, $search) : $b->orLike($col, $search);
                }
                $b->groupEnd();
            }

            return $b;
        };
        $total = static function (BaseBuilder $b) use ($count): int {
            return $count !== '' ? (int) $b->select("COUNT(DISTINCT {$count}) AS n", false)->get()->getRow('n')
                : (int) $b->countAllResults();
        };

        $recordsTotal    = $total(($cfg['builder'])());
        $recordsFiltered = $search === '' && ! isset($cfg['filters'])
            ? $recordsTotal
            : $total($scope(($cfg['builder'])()));

        $page = $scope(($cfg['builder'])());
        if ($orderSql !== null) {
            $page->orderBy($orderSql, $dir, false);
        }
        if (! empty($cfg['tiebreak'])) {
            $page->orderBy((string) $cfg['tiebreak'], $dir === 'ASC' ? 'ASC' : 'DESC', false);
        }
        $rows = $page->limit($length, $start)->get()->getResultArray();

        $map  = $cfg['map'] ?? null;
        $data = $map === null ? $rows : array_map($map, $rows);

        return [
            'draw'            => (int) ($get['draw'] ?? 0),
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => array_values($data),
        ];
    }
}
