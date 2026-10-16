<?php

namespace Modules\Core\Support\Activity;

/**
 * Reads a write statement well enough to log it.
 *
 * Most gds screens write through the query builder (`DB::table()->update()`)
 * or raw SQL, which fire no model events — only QueryExecuted, with the SQL
 * and its bindings. This turns that into: what kind of change, which table,
 * and which columns were given which values. It does not pretend to be a SQL
 * parser; anything it cannot read is logged as the table and the op alone.
 */
class SqlWrite
{
    private const NAME = '((?:`[^`]+`|\w+)(?:\.(?:`[^`]+`|\w+))?)';

    /**
     * @return array{op:string, table:string, columns:array<string,mixed>, rows:int, where:array<string,mixed>}|null
     */
    public static function parse(string $sql, array $bindings, string $defaultDb): ?array
    {
        $bindings = array_map([self::class, 'scalar'], array_values($bindings));

        if (preg_match('/^\s*(?:insert|replace)\s+(?:(?:low_priority|delayed|high_priority|ignore)\s+)*into\s+' . self::NAME . '/i', $sql, $m)) {
            [$columns, $rows] = self::insertColumns($sql, $bindings);

            return ['op' => 'created', 'table' => self::table($m[1], $defaultDb),
                'columns' => $columns, 'rows' => $rows, 'where' => []];
        }

        if (preg_match('/^\s*update\s+(?:(?:low_priority|ignore)\s+)*' . self::NAME . '/i', $sql, $m)) {
            [$set, $where] = self::splitWhere($sql, $bindings, '/\sset\s/i');

            return ['op' => 'updated', 'table' => self::table($m[1], $defaultDb),
                'columns' => $set, 'rows' => 1, 'where' => $where];
        }

        if (preg_match('/^\s*delete\s+(?:(?:low_priority|quick|ignore)\s+)*(?:\w+\s+)?from\s+' . self::NAME . '/i', $sql, $m)) {
            [, $where] = self::splitWhere($sql, $bindings, null);

            return ['op' => 'deleted', 'table' => self::table($m[1], $defaultDb),
                'columns' => [], 'rows' => 1, 'where' => $where];
        }

        return null;
    }

    private static function table(string $name, string $defaultDb): string
    {
        $name = str_replace('`', '', $name);

        return str_contains($name, '.') ? $name : $defaultDb . '.' . $name;
    }

    /** First row of an INSERT … (cols) VALUES (?, …), and how many rows it wrote. */
    private static function insertColumns(string $sql, array $bindings): array
    {
        if (! preg_match('/\(\s*([^)]*?)\s*\)\s*values\s*(.*)$/is', $sql, $m)) {
            return [[], 1];
        }

        $cols = array_map(fn ($c) => trim(str_replace('`', '', $c)), explode(',', $m[1]));
        $values = preg_split('/\son\s+duplicate\s+key\s+update\s/i', $m[2])[0];
        $placeholders = substr_count($values, '?');
        $n = count($cols);

        // Only the plain shape — every value a placeholder — maps safely.
        if ($n === 0 || $placeholders === 0 || $placeholders % $n !== 0
            || preg_match('/[^?\s,()]/', $values)) {
            return [array_fill_keys($cols, null), 1];
        }

        return [array_combine($cols, array_slice($bindings, 0, $n)), intdiv($placeholders, $n)];
    }

    /**
     * Column => value for each placeholder, split at WHERE.
     *
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    private static function splitWhere(string $sql, array $bindings, ?string $setPattern): array
    {
        $wherePos = preg_match('/\swhere\s/i', $sql, $w, PREG_OFFSET_CAPTURE) ? $w[0][1] : strlen($sql);
        $setPos = $setPattern && preg_match($setPattern, $sql, $s, PREG_OFFSET_CAPTURE) ? $s[0][1] : null;

        $set = [];
        $where = [];
        $i = 0;
        $len = strlen($sql);
        $quote = null;

        for ($p = 0; $p < $len; $p++) {
            $ch = $sql[$p];
            if ($quote !== null) {
                if ($ch === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $quote = $ch;

                continue;
            }
            if ($ch !== '?') {
                continue;
            }

            $value = $bindings[$i++] ?? null;
            $before = substr($sql, max(0, $p - 160), min($p, 160));
            if (! preg_match('/([`\w.]+)`?\s*(?:=|<=>|!=|<>|>=|<=|>|<|\blike|\bin\s*\((?:\s*\?\s*,)*)\s*$/i', $before, $c)) {
                continue;
            }
            $col = str_replace('`', '', $c[1]);
            $col = str_contains($col, '.') ? substr($col, strrpos($col, '.') + 1) : $col;

            if ($p > $wherePos) {
                if (array_key_exists($col, $where)) {
                    $where[$col] = array_merge((array) $where[$col], [$value]);
                } else {
                    $where[$col] = $value;
                }
            } elseif ($setPos !== null && $p > $setPos) {
                $set[$col] = $value;
            }
        }

        return [$set, $where];
    }

    public static function scalar(mixed $v): mixed
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d H:i:s');
        }
        if ($v instanceof \BackedEnum) {
            return $v->value;
        }
        if (is_object($v)) {
            return method_exists($v, '__toString') ? (string) $v : get_class($v);
        }

        return $v;
    }
}
