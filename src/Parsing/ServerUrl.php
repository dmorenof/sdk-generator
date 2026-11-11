<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Parsing;

/** Compile OpenAPI server defaults; the shared runtime retains URL execution policy. */
final class ServerUrl {
    public static function isSupported(string $url): bool {
        try { self::checked($url); return true; }
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
        return self::checked(strtr($url, $bindings));
    }

    /**
     * An absolute server must be literal HTTP(S); a relative one is kept as written, because the runtime resolves it
     * against the client base URL (so it is only checked for characters that could not form a path).
     */
    private static function checked(string $url): string {
        if (\Dmorenof\SdkRuntime\ServerUrl::isAbsolute($url)) { return \Dmorenof\SdkRuntime\ServerUrl::normalize($url); }
        if ($url === '' || preg_match('/[\x00-\x20\x7f{}?#]/', $url) === 1) {
            throw new \InvalidArgumentException('A relative server must be a plain path without whitespace, query or fragment.');
        }
        return $url;
    }

    public static function isRelative(string $url): bool {
        return !\Dmorenof\SdkRuntime\ServerUrl::isAbsolute($url);
    }

    public static function same(string $left, string $right): bool {
        return (string) new \GuzzleHttp\Psr7\Uri($left) === (string) new \GuzzleHttp\Psr7\Uri($right);
    }
}
