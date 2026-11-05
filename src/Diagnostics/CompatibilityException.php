<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Diagnostics;

final class CompatibilityException extends \InvalidArgumentException
{
    /** @param non-empty-list<Diagnostic> $diagnostics */
    public function __construct(public readonly array $diagnostics)
    {
        $first = $diagnostics[0];
        parent::__construct($first->source . '#' . $first->pointer . ': ' . $first->message);
    }
}
