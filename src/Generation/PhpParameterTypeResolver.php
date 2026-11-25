<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

use Dmorenof\SdkGenerator\Contract\SchemaDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaKind;
use InvalidArgumentException;

/** Resolve primitive HTTP parameters, including a nullable primitive anyOf. */
final class PhpParameterTypeResolver
{
    public static function nullableType(string $type, bool $nullable): string
    {
        if (!$nullable || in_array($type, ['mixed', 'null'], true)) { return $type; }
        return str_contains($type, '|') ? implode('|', array_unique([...explode('|', $type), 'null'])) : '?' . $type;
    }

    /** @return array{type:string,nullable:bool,enumValues:list<string|int|float|bool>,phpDoc?:string,validation?:array<string,mixed>} */
    public static function resolve(SchemaDefinition $schema, string $where, bool $allowArrays = false, array $models = []): array
    {
        $schema = \Dmorenof\SdkGenerator\Parsing\SchemaReferences::resolve($schema, $models);
        if ($schema->kind === SchemaKind::AnyOf && array_key_exists('schema', $schema->validation)) {
            $types = []; $nullable = false;
            foreach ($schema->branches as $branch) {
                if ($branch->kind === SchemaKind::Null) { $nullable = true; continue; }
                $types[] = match ($branch->kind) {
                    SchemaKind::String => 'string', SchemaKind::Integer => 'int', SchemaKind::Number => 'float', SchemaKind::Boolean => 'bool',
                    default => throw new InvalidArgumentException("Unsupported primitive union at $where."),
                };
            }
            if ($types === []) { throw new InvalidArgumentException("Only-null parameters have no supported wire encoding at $where."); }
            return ['type' => implode('|', array_unique($types)), 'nullable' => $nullable, 'enumValues' => [], 'validation' => $schema->validation];
        }
        // A parameter that is "a string, or a list" with no item type (GoCardless's `ids`: "more than one ID can be specified using a
        // comma-separated string") is sent as that string.
        if (in_array($schema->kind, [SchemaKind::AnyOf, SchemaKind::OneOf], true) && count($schema->branches) === 2) {
            $scalar = array_values(array_filter($schema->branches, static fn (SchemaDefinition $b): bool => $b->kind === SchemaKind::String && $b->enumValues === []));
            $list = array_values(array_filter($schema->branches, static fn (SchemaDefinition $b): bool => $b->kind === SchemaKind::Array
                && ($b->items === null || in_array($b->items->kind, [SchemaKind::Mixed], true))));
            if (count($scalar) === 1 && count($list) === 1) {
                return self::resolve($scalar[0], $where, $allowArrays, $models);
            }
        }
        $enumValues = $schema->enumValues;
        if ($schema->kind === SchemaKind::AnyOf) {
            $variants = $schema->branches;
            $nonNull = array_values(array_filter(
                $variants,
                static fn (SchemaDefinition $branch): bool => $branch->kind !== SchemaKind::Null,
            ));
            $hasNull = count($variants) !== count($nonNull);
            // A union of plain scalars of different types (`limit`: integer or string) is a native PHP union (`int|string`).
            $scalarKinds = [SchemaKind::String, SchemaKind::Integer, SchemaKind::Number, SchemaKind::Boolean];
            if (count($nonNull) >= 2 && array_filter($nonNull, static fn (SchemaDefinition $b): bool => !in_array($b->kind, $scalarKinds, true)
                || $b->nullable) === []) {
                $union = array_values(array_unique(array_map(static fn (SchemaDefinition $b): string => match ($b->kind) {
                    SchemaKind::String => 'string', SchemaKind::Integer => 'int', SchemaKind::Number => 'float', default => 'bool',
                }, $nonNull)));
                return ['type' => implode('|', $union), 'nullable' => $hasNull, 'enumValues' => [], 'validation' => $schema->validation];
            }
            if (!$hasNull || count($variants) !== 2 || count($nonNull) !== 1) {
                throw new InvalidArgumentException("Unsupported HTTP parameter schema at $where.");
            }
            $schema = $nonNull[0];
            $enumValues = $schema->enumValues;
            $nullable = true;
        } else {
            $nullable = $schema->nullable;
        }

        if ($allowArrays && $schema->kind === SchemaKind::Array) {
            if ($schema->items === null || $schema->items->nullable || $schema->enumValues !== []) {
                throw new InvalidArgumentException("Unsupported query array schema at $where.");
            }
            $item = self::resolve($schema->items, "$where.items", false, $models);
            if ($item['nullable'] || str_contains($item['type'], '|')) {
                throw new InvalidArgumentException("Nullable query items are not supported at $where.");
            }
            $itemRules = $item['validation'] ?? $schema->items->validation;
            if ($schema->items->pattern !== null) {
                $itemRules['pattern'] = $schema->items->pattern;
            }
            if ($item['enumValues'] !== []) {
                $itemRules['enum'] = $item['enumValues'];
            }
            return ['type' => 'array', 'nullable' => $nullable, 'enumValues' => [],
                'phpDoc' => 'list<' . ($item['type'] === 'float' ? 'int|float' : $item['type']) . '>',
                'validation' => $schema->validation + ['items' => ['type' => $item['type'], 'rules' => $itemRules]]];
        }
        $type = match ($schema->kind) {
            SchemaKind::String => 'string',
            SchemaKind::Integer => 'int',
            SchemaKind::Number => 'float',
            SchemaKind::Boolean => 'bool',
            default => throw new InvalidArgumentException("Unsupported HTTP parameter schema at $where."),
        };

        if ($enumValues !== []) {
            if (!in_array($schema->kind, [SchemaKind::String, SchemaKind::Integer], true)) {
                throw new InvalidArgumentException("Unsupported HTTP parameter enum type at $where.");
            }
            foreach ($enumValues as $value) {
                if (($schema->kind === SchemaKind::String && !is_string($value))
                    || ($schema->kind === SchemaKind::Integer && !is_int($value))) {
                    throw new InvalidArgumentException("Invalid HTTP parameter enum value at $where.");
                }
            }
        }

        $validation = $schema->validation;
        if ($schema->pattern !== null) { $validation['pattern'] = $schema->pattern; }
        return ['type' => $type, 'nullable' => $nullable, 'enumValues' => $enumValues, 'validation' => $validation];
    }
}
