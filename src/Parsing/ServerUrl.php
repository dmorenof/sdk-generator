<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Parsing;

/** Compile OpenAPI server defaults; the shared runtime retains URL execution policy. */
final class ServerUrl {
    public static function isSupported(string $url): bool {
        try { \Dmorenof\SdkRuntime\ServerUrl::normalize($url); return true; }
        catch (\InvalidArgumentException) { return false; }
    }

    public static function expand(array $server): string {
        $url = $server['url'] ?? null;
        if (!is_string($url)) { throw new \InvalidArgumentException('Server URL must be a string.'); }
        $variables = array_key_exists('variables', $server) ? $server['variables'] : [];
        if (!is_array($variables)) { throw new \InvalidArgumentException('Server variables must be an object.'); }
        $bindings = [];
        foreach ($variables as $name => $variable) {
            if (!is_array($variable) || !array_key_exists('default', $variable) || !is_string($variable['default'])) {
                throw new \InvalidArgumentException("Server variable '$name' requires a string default.");
            }
            if (array_key_exists('enum', $variable)) {
                $enum = $variable['enum'];
                if (!is_array($enum) || !array_is_list($enum) || $enum === [] || count(array_filter($enum, 'is_string')) !== count($enum)
                    || !in_array($variable['default'], $enum, true)) {
                    throw new \InvalidArgumentException("Server variable '$name' requires a nonempty string enum containing its default.");
                }
            }
            $bindings['{' . $name . '}'] = $variable['default'];
        }
        preg_match_all('/\{([^{}]+)\}/', $url, $matches);
        foreach ($matches[1] as $name) {
            if (!array_key_exists('{' . $name . '}', $bindings)) { throw new \InvalidArgumentException("Missing server variable '$name'."); }
        }
        return \Dmorenof\SdkRuntime\ServerUrl::normalize(strtr($url, $bindings));
    }

    public static function same(string $left, string $right): bool {
        return (string) new \GuzzleHttp\Psr7\Uri($left) === (string) new \GuzzleHttp\Psr7\Uri($right);
    }
}
