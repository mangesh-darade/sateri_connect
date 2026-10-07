<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Self-healing database schema.
 *
 * Source of truth: app/Database/Schema/tables.php — generated from a fully migrated database with
 * `php spark schema:snapshot`, plus inline `$schemaColumns` on models that need extra tables.
 *
 * Only ever CREATE TABLE / ADD COLUMN / ADD INDEX. Never modifies or drops existing columns, and
 * skips foreign keys (those stay in migrations). Problems are logged, never shown to the user.
 */
class SchemaRepairService
{
    public const SNAPSHOT_FILE = APPPATH . 'Database/Schema/tables.php';

    protected const NAME_PATTERN = '/^[A-Za-z0-9_]{1,64}$/';

    /** @var array<string, array{columns: array<string, string>, indexes: array<string, string>, options: string}>|null */
    protected static ?array $snapshot = null;

    /** @var array<string, true> */
    protected static array $verified = [];

    /**
     * @return array<string, array{columns: array<string, string>, indexes: array<string, string>, options: string}>
     */
    public static function snapshot(): array
    {
        if (self::$snapshot === null) {
            $data = is_file(self::SNAPSHOT_FILE) ? require self::SNAPSHOT_FILE : [];
            self::$snapshot = is_array($data) ? $data : [];
        }

        return self::$snapshot;
    }

    /**
     * Repair every snapshot table (+ extra definitions) once per database per day / snapshot change.
     *
     * @param array<string, array{columns: array<string, string>, indexes?: array<string, string>, options?: string}> $extra
     *
     * @return array<string, list<string>> table => changes
     */
    public function repairDatabase(BaseConnection $db, array $extra = [], bool $force = false): array
    {
        $database = (string) $db->getDatabase();
        if (! $this->isMysql($db) || ! $this->isTenantDatabase($database)) {
            return [];
        }

        $definitions = array_merge(self::snapshot(), $extra);
        $key         = 'schema_db_' . md5($database . '|' . serialize($definitions));

        if (! $force && (isset(self::$verified[$key]) || cache($key))) {
            self::$verified[$key] = true;

            return [];
        }
        self::$verified[$key] = true;

        try {
            $db->query('SELECT 1');
        } catch (Throwable $e) {
            cache()->save($key, 1, 600);
            log_message('error', 'Schema auto-repair: cannot connect to {db}: {msg}', ['db' => $database, 'msg' => $e->getMessage()]);

            return ['_error' => ['cannot connect: ' . $e->getMessage()]];
        }

        $report = [];
        $failed = false;
        foreach ($definitions as $table => $definition) {
            try {
                $changes = $this->repairTable($db, (string) $table, $definition);
                if ($changes !== []) {
                    $report[$table] = $changes;
                }
            } catch (Throwable $e) {
                $failed = true;
                log_message('error', 'Schema auto-repair failed for {table} on {db}: {msg}', [
                    'table' => $table,
                    'db'    => $database,
                    'msg'   => $e->getMessage(),
                ]);
            }
        }

        // Failed tables are retried in 10 minutes, not on every request.
        cache()->save($key, 1, $failed ? 600 : DAY);

        if ($report !== []) {
            log_message('notice', 'Schema auto-repair on {db}: {changes}', [
                'db'      => $database,
                'changes' => json_encode($report, JSON_UNESCAPED_SLASHES),
            ]);
        }

        return $report;
    }

    /**
     * Check (or repair) one client database — used by the platform Database Health screen.
     *
     * state: ok | needs_repair | attention (manual fix needed) | error
     *
     * @return array{key: string, database: string, state: string, message: string,
     *               issues: array<string, list<string>>, changes: array<string, list<string>>, checked_at: string}
     */
    public function inspectTenant(string $key, bool $repair = false): array
    {
        $result = [
            'key'        => $key,
            'database'   => '',
            'state'      => 'error',
            'message'    => '',
            'issues'     => [],
            'changes'    => [],
            'checked_at' => date('Y-m-d H:i:s'),
        ];

        if (! (new TenantConnection())->apply($key, 'platform')) {
            $result['message'] = 'Client not found or inactive.';

            return $result;
        }

        $db                 = db_connect();
        $result['database'] = (string) $db->getDatabase();

        try {
            $db->query('SELECT 1');
        } catch (Throwable $e) {
            $result['message'] = 'Cannot connect to the client database. Check DB name, user and password for this client.';
            log_message('error', 'Schema health: cannot connect to {db}: {msg}', ['db' => $result['database'], 'msg' => $e->getMessage()]);

            return $result;
        }

        if ($repair) {
            $result['changes'] = $this->repairDatabase($db, [], true);
            unset($result['changes']['_error']);
            $done = array_filter(array_map(
                static fn (array $c) => array_values(array_filter($c, static fn ($x) => ! str_starts_with($x, 'needs manual fix'))),
                $result['changes']
            ));
            if ($done !== []) {
                log_activity('schema_repair', 'settings', 'Platform admin repaired database schema', ['changes' => $done]);
            }
        }

        foreach (self::snapshot() as $table => $definition) {
            try {
                $issues = $this->repairTable($db, (string) $table, $definition, true);
            } catch (Throwable $e) {
                $issues = ['check failed: ' . $e->getMessage()];
            }
            if ($issues !== []) {
                $result['issues'][$table] = $issues;
            }
        }

        $flat   = array_merge([], ...array_values($result['issues']));
        $manual = array_filter($flat, static fn ($x) => ! str_starts_with($x, 'missing'));

        $result['state'] = match (true) {
            $flat === []   => 'ok',
            $manual === [] => 'needs_repair',
            default        => 'attention',
        };
        $result['message'] = match ($result['state']) {
            'ok'           => $repair && $result['changes'] !== [] ? 'Repaired — database is up to date.' : 'Database is up to date.',
            'needs_repair' => count($flat) . ' item(s) missing — click Repair.',
            default        => 'Some items need a manual fix (see details).',
        };

        return $result;
    }

    /**
     * @param array{columns: array<string, string>, indexes?: array<string, string>, options?: string} $definition
     *
     * @param bool $dryRun only report what is missing ("missing column x"), change nothing
     *
     * @return list<string>
     */
    public function repairTable(BaseConnection $db, string $table, array $definition, bool $dryRun = false): array
    {
        $this->validate($table, $definition);

        $prefixed = $db->prefixTable($table);
        $columns  = $definition['columns'];
        $indexes  = $definition['indexes'] ?? [];
        $verb     = $dryRun ? 'missing' : 'added';

        if (! $db->tableExists($table, false)) {
            if ($dryRun) {
                return ['missing table'];
            }
            $parts = [];
            foreach ($columns as $name => $sql) {
                $parts[] = '`' . $name . '` ' . $sql;
            }
            foreach ($indexes as $sql) {
                $parts[] = $sql;
            }
            $db->query('CREATE TABLE IF NOT EXISTS `' . $prefixed . "` (\n  " . implode(",\n  ", $parts) . "\n) " . ($definition['options'] ?? ''));

            return ['created table'];
        }

        $changes = [];

        // Not getFieldNames(): it is cached per connection and goes stale after ALTER.
        $existing = array_map('strtolower', array_column($db->query('SHOW COLUMNS FROM `' . $prefixed . '`')->getResultArray(), 'Field'));
        $previous = null;
        foreach ($columns as $name => $sql) {
            if (! in_array(strtolower($name), $existing, true)) {
                // AUTO_INCREMENT columns must be keys — never bolt one onto an existing table.
                if (stripos($sql, 'AUTO_INCREMENT') !== false) {
                    log_message('warning', 'Schema auto-repair skipped AUTO_INCREMENT column {table}.{col}', ['table' => $table, 'col' => $name]);
                    $changes[] = 'needs manual fix: column ' . $name . ' (auto-increment)';
                    $previous  = $name;

                    continue;
                }
                $position = $previous !== null && in_array(strtolower($previous), $existing, true) ? ' AFTER `' . $previous . '`' : '';
                if (! $dryRun) {
                    $db->query('ALTER TABLE `' . $prefixed . '` ADD COLUMN `' . $name . '` ' . $sql . $position);
                    $existing[] = strtolower($name);
                }
                $changes[] = $verb . ' column ' . $name;
            }
            $previous = $name;
        }

        $current = array_change_key_case(
            array_flip(array_column($db->query('SHOW INDEX FROM `' . $prefixed . '`')->getResultArray(), 'Key_name')),
            CASE_LOWER
        );
        foreach ($indexes as $name => $sql) {
            if (isset($current[strtolower($name)]) || strtoupper($name) === 'PRIMARY') {
                continue;
            }
            if (stripos($sql, 'UNIQUE') === 0 && $this->hasDuplicates($db, $prefixed, $sql)) {
                log_message('warning', 'Schema auto-repair skipped unique index {table}.{idx}: duplicate rows exist', ['table' => $table, 'idx' => $name]);
                $changes[] = 'needs manual fix: unique index ' . $name . ' (duplicate rows)';

                continue;
            }
            if (! $dryRun) {
                $db->query('ALTER TABLE `' . $prefixed . '` ADD ' . $sql);
            }
            $changes[] = $verb . ' index ' . $name;
        }

        return $changes;
    }

    /**
     * Read table definitions from a live, fully migrated database (used by schema:snapshot).
     *
     * @return array<string, array{columns: array<string, string>, indexes: array<string, string>, options: string}>
     */
    public function readDefinitions(BaseConnection $db, array $skip = ['migrations']): array
    {
        $out = [];
        foreach ($db->listTables(true) as $table) {
            $table = (string) $table;
            if (in_array($table, $skip, true)) {
                continue;
            }

            $create = (string) ($db->query('SHOW CREATE TABLE `' . $table . '`')->getRowArray()['Create Table'] ?? '');
            $lines  = preg_split('/\R/', $create) ?: [];
            $def    = ['columns' => [], 'indexes' => [], 'options' => ''];

            foreach ($lines as $line) {
                $line = rtrim(trim($line), ',');
                if (preg_match('/^`([^`]+)`\s+(.+)$/', $line, $m)) {
                    $def['columns'][$m[1]] = $m[2];
                } elseif (preg_match('/^PRIMARY KEY\s/', $line)) {
                    $def['indexes']['PRIMARY'] = $line;
                } elseif (preg_match('/^(?:UNIQUE |FULLTEXT |SPATIAL )?KEY `([^`]+)`/', $line, $m)) {
                    $def['indexes'][$m[1]] = $line;
                } elseif (str_starts_with($line, ') ')) {
                    $def['options'] = trim((string) preg_replace('/\s*AUTO_INCREMENT=\d+/', '', substr($line, 2)));
                }
                // CONSTRAINT ... FOREIGN KEY lines are intentionally ignored.
            }

            $out[$table] = $def;
        }
        ksort($out);

        return $out;
    }

    /**
     * @param array<string, mixed> $definition
     */
    protected function validate(string $table, array $definition): void
    {
        if (! preg_match(self::NAME_PATTERN, $table)) {
            throw new \InvalidArgumentException('Invalid table name: ' . $table);
        }
        if (empty($definition['columns']) || ! is_array($definition['columns'])) {
            throw new \InvalidArgumentException('No columns defined for ' . $table);
        }

        $sqlParts = array_merge(array_values($definition['columns']), array_values($definition['indexes'] ?? []), [$definition['options'] ?? '']);
        foreach (array_keys($definition['columns']) as $name) {
            if (! preg_match(self::NAME_PATTERN, (string) $name)) {
                throw new \InvalidArgumentException('Invalid column name: ' . $table . '.' . $name);
            }
        }
        foreach (array_keys($definition['indexes'] ?? []) as $name) {
            if (! preg_match(self::NAME_PATTERN, (string) $name)) {
                throw new \InvalidArgumentException('Invalid index name: ' . $table . '.' . $name);
            }
        }
        foreach ($sqlParts as $sql) {
            // One statement per definition: no separators or comments outside quoted literals ('#6B7280' is fine).
            $code = (string) preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/", "''", (string) $sql);
            if (preg_match('/;|--|\/\*|#/', $code)) {
                throw new \InvalidArgumentException('Unsafe schema definition for ' . $table);
            }
        }
    }

    protected function hasDuplicates(BaseConnection $db, string $prefixed, string $indexSql): bool
    {
        if (! preg_match('/\(([^)]+)\)\s*$/', $indexSql, $m)) {
            return false;
        }
        $list = array_map(static fn ($c) => '`' . trim(preg_replace('/\(\d+\)/', '', $c), " `") . '`', explode(',', $m[1]));
        $cols = implode(', ', $list);
        // Rows with a NULL key column never conflict in a UNIQUE index.
        $where = implode(' AND ', array_map(static fn ($c) => $c . ' IS NOT NULL', $list));
        $row   = $db->query("SELECT 1 FROM `{$prefixed}` WHERE {$where} GROUP BY {$cols} HAVING COUNT(*) > 1 LIMIT 1")->getRowArray();

        return $row !== null;
    }

    /**
     * Client tables belong only in a resolved tenant DB — never in the master/platform DB
     * (used by login and other pages before a tenant is known).
     */
    protected function isTenantDatabase(string $database): bool
    {
        $master = (string) (config('Tenancy')->masterDatabase ?? '');

        return TenantContext::has()
            && $database !== ''
            && strcasecmp($database, $master) !== 0;
    }

    protected function isMysql(BaseConnection $db): bool
    {
        return str_contains(strtolower((string) $db->DBDriver), 'mysql');
    }
}
