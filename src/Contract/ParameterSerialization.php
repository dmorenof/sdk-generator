<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Contract;

/** Explicit source options; null means the dialect/location default applies. */
final readonly class ParameterSerialization
{
    public function __construct(
        public ?string $style = null,
        public ?bool $explode = null,
        public ?bool $allowReserved = null,
        public ?bool $allowEmptyValue = null,
        public ?string $collectionFormat = null,
    ) {}
}
