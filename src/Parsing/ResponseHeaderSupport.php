<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

use Dmorenof\SdkGenerator\Contract\ResponseDefinition;

/**
 * Which declared response headers the shared runtime can type, and the metadata it needs for them.
 *
 * A header is supported when its schema is a scalar (`string`, `integer`, `number`, `boolean`) or a list of
 * scalars; the runtime then converts it on demand (`ResponseInfo::headers->get()`), checks it is present when
 * `required`, and applies simple rules (`minimum`, `maximum`, `minLength`, `maxLength`, `pattern`, `enum`).
 * Anything else stays readable as raw text and is reported by the audit as untyped.
 *
 * Header objects are read in both dialects: OpenAPI nests the schema (`schema: {type: integer}`), Swagger 2.0
 * puts it on the header itself (`type: integer`).
 */
final class ResponseHeaderSupport
{
    private const SCALARS = ['string', 'integer', 'number', 'boolean'];

    /**
     * The runtime `responseHeaders` metadata: status (or range, or `default`) => header name => specification.
     * Statuses without a typeable header are left out.
     *
     * @param list<ResponseDefinition> $responses
     * @return array<int|string, array<string, array<string, mixed>>>
     */
    public static function forResponses(array $responses): array
    {
        $byStatus = [];
        foreach ($responses as $response) {
            $headers = [];
            foreach ($response->headers as $name => $header) {
                // The Content-Type response header is ignored by the OpenAPI specification.
                if (!is_string($name) || strcasecmp($name, 'Content-Type') === 0 || !is_array($header)) {
                    continue;
                }
                $spec = self::spec($header);
                if ($spec !== null) {
                    $headers[$name] = $spec;
                }
            }
            if ($headers !== []) {
                $byStatus[ctype_digit($response->status) ? (int) $response->status : $response->status] = $headers;
            }
        }
        return $byStatus;
    }

    /**
     * Runtime specification of one header object, or null when the runtime cannot type it.
     *
     * @param array<string, mixed> $header
     * @return array{type: string, items?: string, required?: bool, rules?: array<string, mixed>}|null
     */
    public static function spec(array $header): ?array
    {
        $schema = isset($header['schema']) && is_array($header['schema']) ? $header['schema'] : (isset($header['type']) ? $header : null);
        if ($schema === null || isset($header['content'])) {
            return null;
        }
        $type = $schema['type'] ?? null;
        $spec = [];
        if (is_string($type) && in_array($type, self::SCALARS, true)) {
            $spec['type'] = $type;
        } elseif ($type === 'array') {
            $items = $schema['items']['type'] ?? null;
            // Simple style: the list travels comma-separated. Other collection formats are not representable.
            if (!is_string($items) || !in_array($items, self::SCALARS, true) || !in_array($schema['collectionFormat'] ?? 'csv', ['csv'], true)) {
                return null;
            }
            $spec['type'] = 'array';
            $spec['items'] = $items;
        } else {
            return null;
        }
        if (($header['required'] ?? false) === true) {
            $spec['required'] = true;
        }
        $rules = $spec['type'] === 'array' ? [] : self::rules($schema, $spec['type']);
        if ($rules !== []) {
            $spec['rules'] = $rules;
        }
        return $spec;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function rules(array $schema, string $type): array
    {
        $rules = [];
        if (in_array($type, ['integer', 'number'], true)) {
            foreach (['minimum', 'maximum'] as $keyword) {
                if (isset($schema[$keyword]) && (is_int($schema[$keyword]) || is_float($schema[$keyword]))) {
                    $rules[$keyword] = $schema[$keyword];
                }
            }
        }
        if ($type === 'string') {
            foreach (['minLength', 'maxLength'] as $keyword) {
                if (isset($schema[$keyword]) && is_int($schema[$keyword]) && $schema[$keyword] >= 0) {
                    $rules[$keyword] = $schema[$keyword];
                }
            }
            if (isset($schema['pattern']) && is_string($schema['pattern'])) {
                $rules['pattern'] = $schema['pattern'];
            }
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== [] && array_is_list($schema['enum'])
            && array_filter($schema['enum'], static fn ($value): bool => !is_scalar($value)) === []) {
            $rules['enum'] = $schema['enum'];
        }
        return $rules;
    }
}
