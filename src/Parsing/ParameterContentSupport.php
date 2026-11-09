<?php

declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

use Dmorenof\SdkGenerator\Contract\ParameterDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaKind;

/** One content representation, shared by normalization, audit and emission. */
final class ParameterContentSupport
{
    /** @return array{mediaType:string,schema:array}|null */
    public static function raw(array $parameter): ?array
    {
        $content = $parameter['content'] ?? null;
        if (!is_array($content) || count($content) !== 1 || array_key_exists('schema', $parameter)
            || array_intersect(array_keys($parameter), ['style', 'explode', 'allowReserved', 'collectionFormat']) !== []
            || !in_array($parameter['in'] ?? null, ['path', 'query', 'header', 'cookie'], true)) {
            return null;
        }
        $media = strtolower((string) array_key_first($content));
        $definition = reset($content);
        if (!in_array($media, ['application/json', 'text/plain'], true) || !is_array($definition)
            || !is_array($definition['schema'] ?? null) || !empty($definition['encoding'])
            || (($parameter['in'] ?? '') === 'cookie' && $media !== 'text/plain')) {
            return null;
        }
        if (($parameter['in'] ?? '') === 'cookie') {
            try {
                \Dmorenof\SdkRuntime\CookieHeader::pair($parameter['name'] ?? '', '');
            } catch (\UnexpectedValueException|\TypeError) {
                return null;
            }
        }
        return ['mediaType' => $media, 'schema' => $definition['schema']];
    }

    /** @param list<\Dmorenof\SdkGenerator\Contract\ModelDefinition> $models */
    public static function supported(ParameterDefinition $parameter, array $models): bool
    {
        if (count($parameter->content) !== 1 || !in_array(strtolower($parameter->content[0]->mediaType), ['application/json', 'text/plain'], true)
            || $parameter->content[0]->encoding !== []
            || $parameter->serialization->style !== null || $parameter->serialization->explode !== null
            || $parameter->serialization->allowReserved !== null || $parameter->serialization->collectionFormat !== null
            || !in_array($parameter->location, ['path', 'query', 'header', 'cookie'], true)) {
            return false;
        }
        $media = strtolower($parameter->content[0]->mediaType);
        $schema = $parameter->schema;
        if ($schema->kind === SchemaKind::Reference) {
            $resolved = null;
            foreach ($models as $model) {
                if ($model->name === $schema->reference) {
                    $resolved = $model->schema;
                    break;
                }
            }
            if ($resolved === null || !in_array($resolved->kind, [SchemaKind::Object, SchemaKind::Array, SchemaKind::AllOf], true)) {
                return false;
            }
            $schema = $resolved;
        }
        if ($schema->nullable || $schema->kind === SchemaKind::Mixed || $schema->kind === SchemaKind::Null) {
            return false;
        }
        if ($media === 'text/plain') {
            return $schema->kind === SchemaKind::String && $parameter->schema->kind === SchemaKind::String;
        }
        return $parameter->location !== 'cookie' && in_array($schema->kind, [SchemaKind::String, SchemaKind::Integer, SchemaKind::Number, SchemaKind::Boolean, SchemaKind::Object, SchemaKind::Array, SchemaKind::AllOf], true);
    }
}
