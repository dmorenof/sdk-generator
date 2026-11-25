<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

use Dmorenof\SdkGenerator\Contract\ModelDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaKind;

/**
 * Data a `deepObject` parameter or form field can carry at any depth (`a[b][0][c]=v`): scalars, objects, lists and unions of
 * those. The runtime writes them with the `nested` format. A property name with brackets would be ambiguous, so it is refused.
 */
final class NestedSupport
{
    private const DEPTH = 12;

    /** @param list<ModelDefinition> $models */
    public static function schema(SchemaDefinition $schema, array $models, int $depth = 0): bool
    {
        if ($depth > self::DEPTH) {
            return false;
        }
        try { $schema = SchemaReferences::resolve($schema, $models); }
        catch (\InvalidArgumentException) { return false; }
        // Real contracts nest the same models over and over (Stripe's expandable objects): each schema is judged once, and one
        // met again while it is being judged (a cycle) is taken as fitting, which only a different part of it can contradict.
        static $seen = null;
        $seen ??= new \WeakMap();
        if (isset($seen[$schema])) {
            return $seen[$schema];
        }
        $seen[$schema] = true;
        return $seen[$schema] = self::judge($schema, $models, $depth);
    }

    /** @param list<ModelDefinition> $models */
    private static function judge(SchemaDefinition $schema, array $models, int $depth): bool
    {
        if ($schema->discriminator !== null) {
            return false;
        }
        return match ($schema->kind) {
            // Bytes are not text to put in a `a[b]=` field.
            SchemaKind::String => $schema->format !== 'binary',
            SchemaKind::Integer, SchemaKind::Number, SchemaKind::Boolean, SchemaKind::Mixed => true,
            SchemaKind::Array => $schema->items !== null && self::schema($schema->items, $models, $depth + 1),
            SchemaKind::AnyOf, SchemaKind::OneOf => $schema->branches !== []
                && array_filter($schema->branches, static fn (SchemaDefinition $branch): bool => !self::schema($branch, $models, $depth + 1)) === [],
            SchemaKind::Object => self::object($schema, $models, $depth),
            default => false,
        };
    }

    /** @param list<ModelDefinition> $models */
    private static function object(SchemaDefinition $schema, array $models, int $depth): bool
    {
        foreach ($schema->properties as $property) {
            if (!self::key($property->wireName) || !self::schema($property->schema, $models, $depth + 1)) {
                return false;
            }
        }
        // An object that says it is open to anything (`true`) has fields the SDK cannot name; one that says nothing is built from its declared fields.
        return $schema->additionalProperties !== true
            && (!($schema->additionalProperties instanceof SchemaDefinition) || self::schema($schema->additionalProperties, $models, $depth + 1));
    }

    /** @param array<mixed> $document */
    public static function raw(mixed $schema, array $document, int $depth = 0): bool
    {
        if ($depth > self::DEPTH || !is_array($schema)) {
            return false;
        }
        if (isset($schema['$ref'])) {
            try { $schema = SchemaReferences::raw($schema, $document); }
            catch (\InvalidArgumentException|\TypeError) { return false; }
        }
        if (isset($schema['discriminator']) || isset($schema['allOf']) || isset($schema['not'])) {
            return false;
        }
        foreach (['anyOf', 'oneOf'] as $composition) {
            if (isset($schema[$composition])) {
                if (!is_array($schema[$composition]) || $schema[$composition] === []) {
                    return false;
                }
                foreach ($schema[$composition] as $branch) {
                    if (!self::raw($branch, $document, $depth + 1)) {
                        return false;
                    }
                }
                return true;
            }
        }
        $type = $schema['type'] ?? null;
        $types = is_array($type) ? array_values(array_diff($type, ['null'])) : [$type];
        if ($type === null && !isset($schema['properties']) && !isset($schema['items'])) {
            return true; // Any value.
        }
        if (count($types) !== 1) {
            return false;
        }
        if ($types[0] === 'array') {
            return self::raw($schema['items'] ?? null, $document, $depth + 1);
        }
        if ($types[0] === 'object' || ($type === null && isset($schema['properties']))) {
            foreach ($schema['properties'] ?? [] as $name => $property) {
                if (!self::key((string) $name) || !self::raw($property, $document, $depth + 1)) {
                    return false;
                }
            }
            $additional = $schema['additionalProperties'] ?? null;
            return $additional !== true && (!is_array($additional) || self::raw($additional, $document, $depth + 1));
        }
        return in_array($types[0], ['string', 'integer', 'number', 'boolean'], true) && ($schema['format'] ?? null) !== 'binary';
    }

    private static function key(string $key): bool
    {
        return $key !== '' && strpbrk($key, '[]') === false;
    }
}
