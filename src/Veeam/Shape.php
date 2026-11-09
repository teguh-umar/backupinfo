<?php

namespace GlpiPlugin\Backupinfo\Veeam;

/**
 * Probe shape helpers: check which dot-path fields a row is missing.
 */
final class Shape
{
    /**
     * @param list<string> $required dot paths such as result.result
     * @return list<string> the paths absent from the row
     */
    public static function missing(array $row, array $required): array
    {
        $missing = [];
        foreach ($required as $path) {
            $parts = explode('.', $path);
            $current = $row;
            $found = true;
            foreach ($parts as $part) {
                if (!is_array($current) || !array_key_exists($part, $current)) {
                    $found = false;
                    break;
                }
                $current = $current[$part];
            }
            if (!$found) {
                $missing[] = $path;
            }
        }
        return $missing;
    }
}
