<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Parsing;

/** Turns OpenAPI Server Objects into the runtime's selectable-server metadata. */
final class ServerSupport {
    /**
     * A declaration is dynamic when a consumer has something to choose or fill in: several servers or any variable.
     * A single plain server stays a fixed `serverUrl` (operations) or the configured base URL (root).
     *
     * @param list<array<string, mixed>> $definitions
     */
    public static function isDynamic(array $definitions): bool {
        if (count($definitions) > 1) { return true; }
        return ($definitions[0]['variables'] ?? []) !== [];
    }

    /**
     * Runtime `servers` entries: the raw URL template plus each variable's default and enum, in preference order.
     *
     * @param list<array<string, mixed>> $definitions Already validated by ServerUrl::expand().
     * @return list<array{url: string, variables?: array<string, array{default: string, enum?: list<string>}>}>
     */
    public static function specs(array $definitions): array {
        $specs = [];
        foreach ($definitions as $server) {
            $spec = ['url' => $server['url']];
            foreach ($server['variables'] ?? [] as $name => $variable) {
                $spec['variables'][(string) $name] = ['default' => $variable['default']] + (isset($variable['enum']) ? ['enum' => $variable['enum']] : []);
            }
            $specs[] = $spec;
        }
        return $specs;
    }
}
