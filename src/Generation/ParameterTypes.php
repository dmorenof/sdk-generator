<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

/**
 * The PHP types the contract emitter chose for HTTP parameters that are not plain scalars, by operation ID,
 * location (path, query, header, cookie) and wire name. The emitters that write request and resource classes
 * read them instead of working the types out again.
 */
final readonly class ParameterTypes
{
    /**
     * @param array<string,array<string,array<array-key,string>>> $objects Operation ID / location / wire name => object type.
     * @param array<string,array<string,array<string,string>>> $enums Operation ID / location / wire name => enum type of a scalar.
     */
    public function __construct(
        public array $objects = [],
        public array $enums = [],
    ) {}
}
