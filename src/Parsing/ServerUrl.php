<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

/** Literal server subset which generated operation metadata can execute. */
final class ServerUrl
{
    public static function isSupported(string $url): bool
    {
        $parts = parse_url($url);
        return $parts !== false && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && isset($parts['host']) && $parts['host'] !== ''
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment'])
            && preg_match('/[\x00-\x20\x7f{}]/', $url) === 0;
    }
}
