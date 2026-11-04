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
 * Dots and encoded double underscores define explicit hierarchy. Flat
 * concatenated identifiers are grouped using common structural prefixes
 * across the full model list. Original schema identifiers still key references.
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
        $prefixes = self::inferFlattenedPrefixes($models);
        foreach ($models as $model) {
            $name = $model->name;
            $sourceSegments = array_map(
                static fn (string $segment): string => PhpNameTransliterator::pascal($segment, 'schema segment'),
                self::schemaSegments($name, $prefixes),
            );
            foreach ($sourceSegments as $segment) {
                $this->checkSegment($segment);
            }
            $resolvedName = implode('.', $sourceSegments);
            $generatedName = match ($model->schema->kind) {
                \Dmorenof\SdkGenerator\Contract\SchemaKind::AnyOf => self::prefixLastSegment($resolvedName, 'AnyOf'),
                \Dmorenof\SdkGenerator\Contract\SchemaKind::OneOf => self::prefixLastSegment($resolvedName, 'OneOf'),
                default => $resolvedName,
            };
            // The single DTOs tree preserves the full source hierarchy.
            // Directional behavior still comes from the runtime base class.
            $category = $model->schema->enumValues !== [] ? 'Enums' : 'DTOs';
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

    /**
     * Inference applies only to flat, concatenated schema IDs. Explicit
     * dotted/double-underscore namespace delimiters always take precedence.
     *
     * @param array<string,list<string>> $prefixes
     * @return list<string>
     */
    private static function schemaSegments(string $name, array $prefixes): array
    {
        if (!str_contains($name, '__')) {
            if (str_contains($name, '.')) {
                return explode('.', $name);
            }
            $tokens = self::pascalTokens($name);
            $family = $prefixes[strtolower($tokens[0] ?? '')] ?? [];
            if ($family === [] || count($tokens) <= count($family)
                || array_map('strtolower', array_slice($tokens, 0, count($family)))
                    !== array_map('strtolower', $family)) {
                return [$name];
            }
            $remainder = array_slice($tokens, count($family));
            $suffix = null;
            foreach (['ResponseSchema', 'RequestSchema', 'Response', 'Request'] as $candidate) {
                $suffixTokens = self::pascalTokens($candidate);
                $length = count($suffixTokens);
                if (count($remainder) > $length
                    && array_map('strtolower', array_slice($remainder, -$length))
                        === array_map('strtolower', $suffixTokens)) {
                    $suffix = $candidate;
                    $remainder = array_slice($remainder, 0, -$length);
                    break;
                }
            }
            $namespace = implode('', array_map('ucfirst', $family));
            if ($suffix !== null) {
                return [$namespace, implode('', array_map('ucfirst', $remainder)), $suffix];
            }
            return [$namespace, implode('', array_map('ucfirst', $remainder))];
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

    /**
     * Infer a stable shared prefix, not arbitrary word-by-word namespaces.
     * The first PascalCase token identifies a family; only token boundaries
     * shared by >=65% of that family can extend its root.
     *
     * @param list<ModelDefinition> $models
     * @return array<string,list<string>>
     */
    private static function inferFlattenedPrefixes(array $models): array
    {
        $families = [];
        foreach ($models as $model) {
            if (str_contains($model->name, '.') || str_contains($model->name, '__')) {
                continue;
            }
            $tokens = self::pascalTokens($model->name);
            if (count($tokens) >= 2) {
                $families[strtolower($tokens[0])][] = $tokens;
            }
        }
        $result = [];
        foreach ($families as $familyName => $members) {
            $count = count($members);
            if ($count < 3) {
                continue;
            }
            $threshold = max(3, (int) ceil($count * 0.65));
            $prefix = [];
            for ($index = 0; ; ++$index) {
                $votes = [];
                foreach ($members as $tokens) {
                    if (count($tokens) <= $index) {
                        continue;
                    }
                    if (array_map('strtolower', array_slice($tokens, 0, $index))
                        !== array_map('strtolower', $prefix)) {
                        continue;
                    }
                    $token = $tokens[$index];
                    $key = strtolower($token);
                    $votes[$key]['token'] ??= $token;
                    $votes[$key]['count'] = ($votes[$key]['count'] ?? 0) + 1;
                }
                if ($votes === []) {
                    break;
                }
                uasort($votes, static fn (array $a, array $b): int =>
                    $b['count'] <=> $a['count'] ?: strcmp($a['token'], $b['token']));
                $best = reset($votes);
                if ($best['count'] < $threshold) {
                    break;
                }
                $prefix[] = $best['token'];
            }
            if ($prefix !== []) {
                $result[$familyName] = $prefix;
            }
        }
        return $result;
    }

    /** @return list<string> */
    private static function pascalTokens(string $source): array
    {
        $latin = PhpNameTransliterator::latin($source);
        preg_match_all('/[A-Z]+(?=[A-Z][a-z]|[0-9]|$)|[A-Z]?[a-z]+[0-9]*|[0-9]+/', $latin, $matches);
        return $matches[0] ?? [];
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
    public function derived(string $hint, string $category, ?string $className = null, ?string $nameCategory = null): array
    {
        if (!in_array($category, ['Enums', 'Collections', 'DTOs', 'Requests', 'Responses'], true)) {
            throw new InvalidArgumentException("Unsupported derived type category: $category");
        }
        $key = $category . ':' . $hint . ':' . ($className ?? '') . ':' . ($nameCategory ?? '');
        if (isset($this->derivedEntries[$key])) {
            return $this->derivedEntries[$key];
        }
        $modelName = $this->matchingModelName($hint);
        $hintSegments = explode('.', $hint);
        $baseName = $className ?? self::compactTypeName(
            $modelName === null ? $hint : (string) end($hintSegments),
            $nameCategory ?? $category,
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
            $name = PhpNameTransliterator::pascal($name, 'schema name');
            $this->checkSegment($name);
            return [
                'src/' . $category . '/' . $name . '.php',
                '\\' . $this->namespace . '\\' . $category . '\\' . $name,
            ];
        }

        $segments = array_map(
            static fn (string $segment): string => PhpNameTransliterator::pascal($segment, 'schema segment'),
            explode('.', $name),
        );
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
        $sections = explode('.', str_replace(['_', '-'], ' ', PhpNameTransliterator::latin($hint)));
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
        $folders = array_map(
            static fn (string $segment): string => PhpNameTransliterator::pascal($segment, 'schema segment'),
            $folders,
        );
        $name = PhpNameTransliterator::pascal($name, 'schema name');
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
