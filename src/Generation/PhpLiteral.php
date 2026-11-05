<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

use InvalidArgumentException;

/** Emits canonical modern PHP array literals, never legacy array(...). */
final class PhpLiteral
{
    public static function export(mixed $value, int $level = 0): string
    {
        if (!is_array($value)) {
            if (!is_null($value) && !is_bool($value) && !is_int($value)
                && !is_float($value) && !is_string($value)) {
                throw new InvalidArgumentException('Unsupported value in generated PHP literal.');
            }
            return var_export($value, true);
        }
        if ($value === []) {
            return '[]';
        }
        $rows = [];
        $prefix = str_repeat('    ', $level + 1);
        $list = array_is_list($value);
        foreach ($value as $key => $item) {
            $label = $list ? '' : self::export($key) . ' => ';
            $rows[] = $prefix . $label . self::export($item, $level + 1) . ',';
        }
        return "[\n" . implode("\n", $rows) . "\n" . str_repeat('    ', $level) . ']';
    }
}
