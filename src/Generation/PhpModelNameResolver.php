<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

use Dmorenof\SdkGenerator\Contract\ModelDefinition;
use InvalidArgumentException;

/**
 * Map source schema identifiers to stable PHP hierarchies.
 *
 * Casafari.Crm.Api.Dtos.Agency.AgencyDto
 *   -> src/DTOs/Casafari/Crm/Api/Dtos/Agency/AgencyDto.php
 *   -> \Vendor\Sdk\DTOs\Casafari\Crm\Api\Dtos\Agency\AgencyDto
 *
 * The namespace of the SDK is a separate concern: it is explicitly configured
 * or derived from OpenAPI/Swagger info.title, NEVER inferred from model paths.
 *
 * Dots and encoded double underscores describe schema hierarchy. Encoded
 * technical source folders are omitted; source identifiers still key references.
 */
final class PhpModelNameResolver
{
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/D';
    private const RESERVED = [
        'abstract','and','array','as','break','callable','case','catch',
        'class','clone','const','continue','declare','default','die','do',
        'echo','else','elseif','empty','enddeclare','endfor','endforeach',
        'endif','endswitch','endwhile','enum','eval','exit','extends','final',
        'finally','fn','for','foreach','function','global','goto','if',
        'implements','include','include_once','instanceof','insteadof',
        'interface','isset','list','match','namespace','new','or','print',
        'private','protected','public','readonly','require','require_once',
        'return','static','switch','throw','trait','try','unset','use','var',
        'while','xor','yield','true','false','null','int','float','string',
        'bool','void','object','mixed','never','self','parent',
    ];

    /** @var array<string, string> */
    private array $paths;
    /** @var array<string, string> */
    private array $types;
    /** @var array<string, list<string>> Original schema ID => resolved hierarchy. */
    private array $segments = [];
    /** @var array<string, string> */
    private array $occupiedPaths = [];
    /** @var array<string, array{path:string,type:string,namespace:string,class:string}> */
    private array $derivedEntries = [];

    /** @param list<ModelDefinition> $models
    *  @param array<string, 'DTO'|'Request'|'Response'> $roles
    */
    public function __construct(private string $namespace, array $models, array $roles = [])
    {
        $paths = [];
        $types = [];
        $occupied = [];
        foreach ($models as $model) {
            $name = $model->name;
            $sourceSegments = self::schemaSegments($name);
            foreach ($sourceSegments as $segment) {
                $this->checkSegment($segment);
            }
            $resolvedName = implode('.', $sourceSegments);
            $generatedName = match ($model->schema->kind) {
                \Dmorenof\SdkGenerator\Contract\SchemaKind::AnyOf => self::prefixLastSegment($resolvedName, 'AnyOf'),
                \Dmorenof\SdkGenerator\Contract\SchemaKind::OneOf => self::prefixLastSegment($resolvedName, 'OneOf'),
                default => $resolvedName,
            };
            $category = $model->schema->enumValues !== [] ? 'Enums' : match ($roles[$name] ?? 'DTO') {
                'Request' => 'Requests',
                'Response' => 'Responses',
                default => 'DTOs',
            };
            [$path, $type] = $this->resolve($generatedName, $category);
            // On case-insensitive filesystems, path collisions must also fail.
            $key = strtolower($path);
            if (isset($occupied[$key])) {
                throw new InvalidArgumentException(
                    "Generated PHP schema name collision: '$name' and '{$occupied[$key]}' both map to $path.",
                );
            }
            $occupied[$key] = $name;
            $this->occupiedPaths[$key] = $name;
            $paths[$name] = $path;
            $types[$name] = $type;
            $this->segments[$name] = explode('.', $generatedName);
        }
        $this->paths = $paths;
        $this->types = $types;
    }

    public function path(string $schemaName): string
    {
        return $this->paths[$schemaName]
            ?? throw new InvalidArgumentException("Unknown model reference: $schemaName.");
    }

    /** @return list<string> */
    private static function schemaSegments(string $name): array
    {
        if (!str_contains($name, '__')) {
            return explode('.', $name);
        }

        $segments = preg_split('/__|\./', $name);
        // A final numeric token identifies a variant, not a PHP namespace.
        if (count($segments) > 1 && ctype_digit(end($segments))) {
            $variant = array_pop($segments);
            $segments[array_key_last($segments)] .= $variant;
        }

        // Merged contracts may concatenate a provider prefix and a source root,
        // e.g. ProviderApisrc__structures or ProviderApiapi__schema.
        $next = strtolower($segments[1] ?? '');
        if (in_array($next, ['structures', 'schema', 'schemas'], true)
            && preg_match('/^(.+)(src|api)$/D', $segments[0], $match) === 1) {
            $segments[0] = $match[1];
        }

        $class = array_pop($segments);
        $folders = [];
        foreach ($segments as $index => $segment) {
            if (in_array(strtolower($segment), ['src', 'structures', 'schema', 'schemas'], true)
                || ($index === 0 && $segment === 'api' && in_array($next, ['schema', 'schemas'], true))) {
                continue;
            }
            $folders[] = ucfirst($segment);
        }
        return [...$folders, ucfirst($class)];
    }

    public function fqcn(string $schemaName): string
    {
        return $this->types[$schemaName]
            ?? throw new InvalidArgumentException("Unknown model reference: $schemaName.");
    }

    /**
     * Keep the full source hierarchy for derived collections and enums too.
     *
     * @return array{path:string,type:string,namespace:string,class:string}
     */
    public function derived(string $hint, string $category, ?string $className = null): array
    {
        if (!in_array($category, ['Enums', 'Collections', 'DTOs', 'Requests', 'Responses'], true)) {
            throw new InvalidArgumentException("Unsupported derived type category: $category");
        }
        $key = $category . ':' . $hint . ':' . ($className ?? '');
        if (isset($this->derivedEntries[$key])) {
            return $this->derivedEntries[$key];
        }
        $modelName = $this->matchingModelName($hint);
        $hintSegments = explode('.', $hint);
        $baseName = $className ?? self::compactTypeName(
            $modelName === null ? $hint : (string) end($hintSegments),
            $category,
        );
        $name = $baseName;
        [$path, $type] = $this->resolveDerived($hint, $name, $category);
        $pathKey = strtolower($path);
        if (isset($this->occupiedPaths[$pathKey])) {
            $name .= ucfirst(substr(hash('sha256', $hint), 0, 7));
            [$path, $type] = $this->resolveDerived($hint, $name, $category);
            $pathKey = strtolower($path);
        }
        if (isset($this->occupiedPaths[$pathKey])) {
            throw new InvalidArgumentException("Generated PHP type collision for $hint at $path.");
        }
        $this->occupiedPaths[$pathKey] = $hint;
        $index = strrpos($type, '\\');
        return $this->derivedEntries[$key] = [
            'path' => $path,
            'type' => $type,
            'namespace' => substr($type, 0, $index),
            'class' => substr($type, $index + 1),
        ];
    }

    public static function prefixLastSegment(string $name, string $prefix): string
    {
        $separator = strrpos($name, '.');
        if ($separator === false) {
            return $prefix . ucfirst($name);
        }
        return substr($name, 0, $separator + 1) . $prefix . ucfirst(substr($name, $separator + 1));
    }

    /** @return array{string,string} */
    private function resolve(string $name, string $category): array
    {
        if (!str_contains($name, '.')) {
            $this->checkSegment($name);
            return [
                'src/' . $category . '/' . $name . '.php',
                '\\' . $this->namespace . '\\' . $category . '\\' . $name,
            ];
        }

        $segments = explode('.', $name);
        foreach ($segments as $segment) {
            $this->checkSegment($segment);
        }
        $path = implode('/', $segments);
        $namespace = implode('\\', $segments);
        return [
            'src/' . $category . '/' . $path . '.php',
            '\\' . $this->namespace . '\\' . $category . '\\' . $namespace,
        ];
    }

    private static function compactTypeName(string $hint, string $category): string
    {
        $sections = explode('.', str_replace(['_', '-'], ' ', $hint));
        $words = [];
        foreach ($sections as $index => $section) {
            if ($index < count($sections) - 1) {
                $sectionWords = preg_split('/\s+/', trim($section), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $words = [...$words, ...$sectionWords];
                continue;
            }
            $section = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $section) ?? $section;
            $words = [...$words, ...(preg_split('/\s+/', trim($section), -1, PREG_SPLIT_NO_EMPTY) ?: [])];
        }
        $words = array_values(array_filter(
            $words,
            static fn (string $word): bool => !in_array(strtolower($word), [
                'api', 'v1', 'v2', 'v3', 'get', 'post', 'put', 'patch', 'delete',
                'response', 'request', 'variant', 'item', 'array', 'enum',
                'any', 'all', 'one', 'of', 'inline',
            ], true),
        ));
        if ($words === []) {
            $words = ['Generated'];
        }
        $isMap = $category === 'DTOs' && strtolower((string) end($words)) === 'map';
        $tail = array_slice($words, $isMap ? -3 : -2);
        $name = implode('', array_map('ucfirst', $tail));
        $suffix = match ($category) {
            'Enums' => 'Enum',
            'Collections' => 'Collection',
            'Requests' => 'Request',
            'Responses' => 'Response',
            default => $isMap ? '' : 'DTO',
        };
        if (preg_match('/^[A-Za-z_]/', $name) !== 1) {
            $name = 'Value' . $name;
        }
        return $name . $suffix;
    }

    /** @return array{string,string} */
    private function resolveDerived(string $hint, string $name, string $category): array
    {
        $segments = explode('.', $hint);
        if (str_starts_with($hint, 'Errors.')) {
            array_pop($segments);
            return $this->resolve(implode('.', [...$segments, $name]), $category);
        }
        if (count($segments) < 2) {
            return $this->resolve($name, $category);
        }

        $modelName = $this->matchingModelName($hint);
        if ($modelName === null) {
            return $this->resolve($name, $category);
        }
        $modelSegments = explode('.', $modelName);
        $folders = $this->segments[$modelName];
        $segments = explode('.', $hint);
        $propertySegments = array_slice($segments, count($modelSegments), -1);
        $folders = [...$folders, ...$propertySegments];
        foreach ([...$folders, $name] as $segment) {
            $this->checkSegment($segment);
        }
        $relative = implode('/', [...$folders, $name]);
        $qualified = implode('\\', [...$folders, $name]);
        return [
            'src/' . $category . '/' . $relative . '.php',
            '\\' . $this->namespace . '\\' . $category . '\\' . $qualified,
        ];
    }

    private function matchingModelName(string $hint): ?string
    {
        $modelName = null;
        foreach (array_keys($this->paths) as $candidate) {
            if (str_starts_with($hint, $candidate . '.')
                && ($modelName === null || strlen($candidate) > strlen($modelName))) {
                $modelName = $candidate;
            }
        }
        return $modelName;
    }

    private function checkSegment(string $segment): void
    {
        if (preg_match(self::IDENTIFIER, $segment) !== 1
            || in_array(strtolower($segment), self::RESERVED, true)) {
            throw new InvalidArgumentException("Invalid PHP schema namespace or class segment: $segment");
        }
    }
}
