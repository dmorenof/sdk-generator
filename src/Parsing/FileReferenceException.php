<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

use Dmorenof\SdkGenerator\Diagnostics\Diagnostic;

final class FileReferenceException extends \InvalidArgumentException
{
    public function __construct(public readonly Diagnostic $diagnostic)
    {
        parent::__construct($diagnostic->source . '#' . $diagnostic->pointer . ': ' . $diagnostic->message);
    }
}
