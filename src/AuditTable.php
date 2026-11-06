<?php

namespace GlpiPlugin\Backupinfo;

/**
 * One audit section as GLPI 11's own table (templates/components/datatable.html.twig): column filters,
 * sort and pages, all applied here on the full row set, so a filter also finds rows beyond the first
 * page. Only the screen is filtered: every export keeps all rows and columns (user, 2026-11-06). Pure.
 */
final class AuditTable
{
    /** A column with at most this many distinct values is filtered by a multiple choice, else by text. */
    public const MAX_CHOICES = 15;

    private const MAX_TEXT = 100;

    /** @param list<string> $columns @return array<string, string> c0, c1, ... => label */
    public static function keys(array $columns): array
    {
        $out = [];
        foreach (array_values($columns) as $i => $label) {
            $out['c' . $i] = (string) $label;
        }
        return $out;
    }

    /**
     * Columns filtered by a choice list: few distinct short values (statuses, Computers, jobs), and
     * not one value per row (run ids, times).
     *
     * @param list<list<scalar|null>> $rows
     * @return array<string, array<string, string>> column key => value => value
     */
    public static function choices(array $columns, array $rows): array
    {
        $out = [];
        foreach (array_keys(array_values($columns)) as $i) {
            $values = [];
            foreach ($rows as $row) {
                $v = trim((string) ($row[$i] ?? ''));
                if ($v === '' || mb_strlen($v) > 60) {
                    continue;
                }
                $values[$v] = $v;
                if (count($values) > self::MAX_CHOICES) {
                    break;
                }
            }
            if ($values !== [] && count($values) <= self::MAX_CHOICES && count($values) < count($rows)) {
                uksort($values, 'strnatcasecmp');
                $out['c' . $i] = $values;
            }
        }
        return $out;
    }

    /**
     * The filters a request may carry: known columns only, text trimmed and cut, choice lists as
     * lists of strings. 'active' (the filter row is open) is kept.
     *
     * @param array<string, string> $keys
     * @param array<string, array<string, string>> $choices
     * @return array<string, string|list<string>>
     */
    public static function cleanFilters(mixed $raw, array $keys, array $choices): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        if (!empty($raw['active'])) {
            $out['active'] = '1';
        }
        foreach ($keys as $key => $label) {
            $value = $raw[$key] ?? null;
            if (isset($choices[$key])) {
                $list = array_values(array_unique(array_filter(
                    array_map(static fn($v): string => is_scalar($v) ? mb_substr(trim((string) $v), 0, self::MAX_TEXT) : '', (array) $value),
                    static fn(string $v): bool => $v !== ''
                )));
                if ($list !== []) {
                    $out[$key] = $list;
                }
            } elseif (is_scalar($value) && ($text = mb_substr(trim((string) $value), 0, self::MAX_TEXT)) !== '') {
                $out[$key] = $text;
            }
        }
        return $out;
    }

    /**
     * Rows matching every filter: a choice list matches the exact value, a text matches anywhere
     * in the cell, case-insensitive.
     *
     * @param list<list<scalar|null>> $rows
     * @param array<string, string|list<string>> $filters cleaned
     * @return list<list<scalar|null>>
     */
    public static function apply(array $rows, array $filters): array
    {
        $tests = [];
        foreach ($filters as $key => $value) {
            if ($key !== 'active' && preg_match('/^c(\d+)$/', (string) $key, $m) === 1) {
                $tests[(int) $m[1]] = $value;
            }
        }
        if ($tests === []) {
            return array_values($rows);
        }
        return array_values(array_filter($rows, static function (array $row) use ($tests): bool {
            foreach ($tests as $i => $want) {
                $cell = trim((string) ($row[$i] ?? ''));
                if (is_array($want) ? !in_array($cell, $want, true) : mb_stripos($cell, $want) === false) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Natural, case-insensitive sort on one column; ties keep their order.
     *
     * @param list<list<scalar|null>> $rows
     * @return list<list<scalar|null>>
     */
    public static function sort(array $rows, int $column, bool $desc): array
    {
        $indexed = [];
        foreach (array_values($rows) as $n => $row) {
            $indexed[] = [$n, $row];
        }
        usort($indexed, static function (array $a, array $b) use ($column, $desc): int {
            $c = strnatcasecmp((string) ($a[1][$column] ?? ''), (string) ($b[1][$column] ?? ''));
            return $c !== 0 ? ($desc ? -$c : $c) : $a[0] <=> $b[0];
        });
        return array_map(static fn(array $p): array => $p[1], $indexed);
    }

    /**
     * Context for components/datatable.html.twig from one section and the request (filters, sort,
     * order, start, glpilist_limit). The pager, sort links and filter row call reloadTab(), which the
     * audit page defines for its own sections.
     *
     * @param array{columns: list<string>, rows: list<list<scalar|null>>} $section
     * @param array<string, mixed> $get
     * @return array<string, mixed>
     */
    public static function view(string $id, array $section, array $get, int $defaultLimit): array
    {
        $keys    = self::keys($section['columns']);
        $choices = self::choices($section['columns'], $section['rows']);
        $filters = self::cleanFilters($get['filters'] ?? null, $keys, $choices);
        $rows    = self::apply($section['rows'], $filters);

        $sort  = is_string($get['sort'] ?? null) && isset($keys[$get['sort']]) ? $get['sort'] : null;
        $order = strtoupper((string) ($get['order'] ?? '')) === 'DESC' ? 'DESC' : 'ASC';
        if ($sort !== null) {
            $rows = self::sort($rows, (int) substr($sort, 1), $order === 'DESC');
        }

        $limit = (int) ($get['glpilist_limit'] ?? 0);
        $limit = $limit > 0 ? min($limit, 9999999) : max(1, $defaultLimit);
        $start = max(0, (int) ($get['start'] ?? 0));
        if ($start >= count($rows)) {
            $start = 0;
        }

        $entries = [];
        foreach (array_slice($rows, $start, $limit) as $row) {
            $entry = [];
            foreach (array_keys($keys) as $i => $key) {
                $entry[$key] = (string) ($row[$i] ?? '');
            }
            $entries[] = $entry;
        }

        return [
            'datatable_id'      => $id,
            'is_tab'            => true,
            'columns'           => $keys,
            'formatters'        => array_map(static fn(): string => 'array', $choices),
            'columns_values'    => $choices,
            'entries'           => $entries,
            'filters'           => $filters,
            'total_number'      => count($section['rows']),
            'filtered_number'   => count($rows),
            'start'             => $start,
            'limit'             => $limit,
            'sort'              => $sort,
            'order'             => $order,
            'additional_params' => '',
        ];
    }
}
