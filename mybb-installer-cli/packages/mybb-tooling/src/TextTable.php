<?php
declare(strict_types=1);

namespace MyBB\Tooling;

/** Small dependency-free formatter for human-readable CLI field/value output. */
final class TextTable
{
    public static function render(array $data): string
    {
        $rows = [['Field', 'Value']];
        foreach ($data as $key => $value) $rows[] = [self::clean((string)$key), self::clean(self::value($value))];
        $widths = [0, 0];
        foreach ($rows as $row) foreach ([0, 1] as $column) $widths[$column] = max($widths[$column], self::width($row[$column]));

        $border = '+' . str_repeat('-', $widths[0] + 2) . '+' . str_repeat('-', $widths[1] + 2) . "+\n";
        $output = $border;
        foreach ($rows as $index => $row) {
            $output .= '| ' . $row[0] . str_repeat(' ', $widths[0] - self::width($row[0]))
                . ' | ' . $row[1] . str_repeat(' ', $widths[1] - self::width($row[1])) . " |\n";
            if ($index === 0) $output .= $border;
        }
        return $output . $border;
    }

    private static function value(mixed $value): string
    {
        if ($value === null) return 'null';
        if (is_bool($value)) return $value ? 'yes' : 'no';
        if (is_scalar($value)) return (string)$value;
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[unavailable]';
    }

    private static function clean(string $value): string
    {
        $clean = preg_replace('/[\x00-\x1f\x7f]/', ' ', $value) ?? '';
        return str_replace('|', '\\|', $clean);
    }

    private static function width(string $value): int
    {
        if (function_exists('mb_strwidth')) {
            $width = mb_strwidth($value, 'UTF-8');
            if (is_int($width)) return $width;
        }
        return strlen($value);
    }
}
