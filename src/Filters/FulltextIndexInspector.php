<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiCore\Filters;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;
use Throwable;

/**
 * FulltextIndexInspector
 *
 * Answers "can this connection actually run MATCH() over these columns?".
 *
 * MySQL only uses a FULLTEXT index when the MATCH column list is *exactly* the
 * indexed one; anything else fails the whole statement with "Can't find
 * FULLTEXT index matching the column list" rather than degrading to a scan.
 * That is what turned a list filter into an HTTP 500 on an install whose index
 * migration had not run.
 *
 * Results are memoised per connection+table for the life of the process: the
 * schema does not change under a running request, and a list screen would
 * otherwise hit information_schema on every keystroke.
 */
final class FulltextIndexInspector
{
    /** @var array<string, list<list<string>>> cache key => one sorted column set per FULLTEXT index */
    private static array $cache = [];

    /**
     * @param BaseConnection<mixed, mixed> $db
     * @param list<string>                 $columns
     */
    public static function covers(BaseConnection $db, string $table, array $columns): bool
    {
        if ($columns === [] || ! self::supportsFulltext($db)) {
            return false;
        }

        $wanted = $columns;
        sort($wanted);

        foreach (self::indexedColumnSets($db, $table) as $indexed) {
            if ($indexed === $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only MySQL-family drivers get the MATCH path. SQLite's FTS is a separate
     * virtual-table feature with different syntax, and every other driver here
     * is served correctly by LIKE.
     *
     * @param BaseConnection<mixed, mixed> $db
     */
    public static function supportsFulltext(BaseConnection $db): bool
    {
        return in_array(strtolower($db->DBDriver), ['mysqli', 'mysql'], true);
    }

    /**
     * Drop the memoised schema. Tests that create or drop an index mid-run need
     * this; production does not.
     */
    public static function flushCache(): void
    {
        self::$cache = [];
    }

    /**
     * @param BaseConnection<mixed, mixed> $db
     *
     * @return list<list<string>>
     */
    private static function indexedColumnSets(BaseConnection $db, string $table): array
    {
        $key = strtolower($db->database) . '.' . strtolower($table);

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $sets = [];

        try {
            $query = $db->query(
                'SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX'
                . ' FROM information_schema.STATISTICS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_TYPE = ?'
                . ' ORDER BY INDEX_NAME, SEQ_IN_INDEX',
                [$table, 'FULLTEXT'],
            );

            $rows = $query instanceof ResultInterface ? $query->getResultArray() : [];

            $grouped = [];

            foreach ($rows as $row) {
                $grouped[(string) $row['INDEX_NAME']][] = (string) $row['COLUMN_NAME'];
            }

            foreach ($grouped as $columns) {
                sort($columns);
                $sets[] = $columns;
            }
        } catch (Throwable) {
            // A connection that cannot read information_schema (a restricted
            // grant, an unexpected engine) must not take the screen down with
            // it: report "no index" and let every profile degrade to LIKE.
            $sets = [];
        }

        return self::$cache[$key] = $sets;
    }
}
