<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

/**
 * What an OAuth 2.0 or OpenID Connect security scheme must declare, shared by the normalizer (which refuses a
 * malformed one) and the auditor (which reports it). Endpoints are not emitted: the runtime gets them from the
 * token provider the caller builds, so only the shape of the declaration is checked here.
 */
final class OAuthSchemeRules
{
    /** Required endpoint fields per flow, OpenAPI 3 names. */
    private const FLOWS = [
        'implicit' => ['authorizationUrl'],
        'password' => ['tokenUrl'],
        'clientCredentials' => ['tokenUrl'],
        'authorizationCode' => ['authorizationUrl', 'tokenUrl'],
    ];

    /** Swagger 2 flow names and their required endpoint fields. */
    private const SWAGGER_FLOWS = [
        'implicit' => ['authorizationUrl'],
        'password' => ['tokenUrl'],
        'application' => ['tokenUrl'],
        'accessCode' => ['authorizationUrl', 'tokenUrl'],
    ];

    /** @param array<mixed> $scheme The security scheme object, of type oauth2. */
    public static function oauth2Problem(array $scheme, bool $swagger): ?string
    {
        if ($swagger) {
            $flow = $scheme['flow'] ?? null;
            if (!is_string($flow) || !isset(self::SWAGGER_FLOWS[$flow])) {
                return 'An OAuth2 scheme must declare flow implicit, password, application or accessCode.';
            }
            return self::flowProblem($scheme, self::SWAGGER_FLOWS[$flow], $flow);
        }
        $flows = $scheme['flows'] ?? null;
        if (!is_array($flows) || $flows === [] || array_is_list($flows)) {
            return 'An OAuth2 scheme must declare at least one flow.';
        }
        foreach ($flows as $name => $flow) {
            if (!is_string($name) || !isset(self::FLOWS[$name]) || !is_array($flow)) {
                return 'Unknown OAuth2 flow ' . (is_string($name) ? $name : '') . '.';
            }
            $problem = self::flowProblem($flow, self::FLOWS[$name], $name);
            if ($problem !== null) {
                return $problem;
            }
        }
        return null;
    }

    /** @param array<mixed> $scheme The security scheme object, of type openIdConnect. */
    public static function openIdConnectProblem(array $scheme): ?string
    {
        $url = $scheme['openIdConnectUrl'] ?? null;
        return is_string($url) && $url !== '' ? null : 'An OpenID Connect scheme must declare openIdConnectUrl.';
    }

    /**
     * @param array<mixed> $flow
     * @param list<string> $required
     */
    private static function flowProblem(array $flow, array $required, string $name): ?string
    {
        foreach ($required as $field) {
            if (!is_string($flow[$field] ?? null) || $flow[$field] === '') {
                return "OAuth2 flow $name must declare $field.";
            }
        }
        $scopes = $flow['scopes'] ?? null;
        if (!is_array($scopes) || ($scopes !== [] && array_is_list($scopes))) {
            return "OAuth2 flow $name must declare scopes as an object.";
        }
        return null;
    }
}
