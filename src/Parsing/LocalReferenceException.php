<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

final class LocalReferenceException extends \InvalidArgumentException
{
    /** @param list<string> $referenceChain */
    public function __construct(
        public readonly string $diagnosticCode,
        string $message,
        public readonly array $referenceChain,
        public readonly ?string $pointer = null,
    ) {
        parent::__construct($message . ($referenceChain === [] ? '' : ' [' . implode(' -> ', $referenceChain) . ']'));
    }
}
