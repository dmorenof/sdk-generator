<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

/** Escape specification prose once for every generated declaration. */
final class PhpDoc
{
    public static function hasArray(string $type): bool
    {
        return preg_match('/(?:^|[|?])array(?:$|\|)/', $type) === 1;
    }

    public static function type(string $type): string
    {
        if (str_starts_with($type, '?')) { $type = substr($type, 1) . '|null'; }
        return preg_replace('/\barray\b/', 'list<mixed>', $type) ?? $type;
    }

    /** @param array<string,mixed> $annotations @param list<string> $tags */
    public static function render(array $annotations, array $tags = [], string $indent = '    '): string
    {
        $lines = [];
        foreach (['summary', 'title', 'description'] as $key) {
            if (is_string($annotations[$key] ?? null) && trim($annotations[$key]) !== '') {
                if ($lines !== []) { $lines[] = ''; }
                foreach (preg_split('/\R/u', $annotations[$key]) ?: [] as $line) {
                    $lines[] = str_replace(['*/', '@', "\0"], ['* /', '&#64;', ''], $line);
                }
            }
        }
        if (array_key_exists('example', $annotations)) {
            $example = json_encode($annotations['example'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $lines[] = 'Example: ' . str_replace(['*/', '@'], ['* /', '&#64;'], $example);
        }
        if (is_array($annotations['externalDocs'] ?? null) && is_string($annotations['externalDocs']['url'] ?? null)) {
            $lines[] = 'Documentation: ' . str_replace(['*/', '@', "\r", "\n", "\0"], ['* /', '&#64;', ' ', ' ', ''], $annotations['externalDocs']['url']);
        }
        if (($annotations['deprecated'] ?? false) === true) { $tags[] = '@deprecated'; }
        if (is_string($annotations['format'] ?? null)) {
            $lines[] = 'Format: ' . str_replace(['*/', '@', "\0", "\r", "\n"], ['* /', '&#64;', '', ' ', ' '], $annotations['format']);
        }
        if ($lines === [] && $tags === []) { return ''; }
        if ($lines !== [] && $tags !== []) { $lines[] = ''; }
        return $indent . "/**\n" . implode('', array_map(static fn (string $line): string => $indent . ' *' . ($line === '' ? '' : ' ' . $line) . "\n", array_merge($lines, $tags))) . $indent . " */\n";
    }
}
