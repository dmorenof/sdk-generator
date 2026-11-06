<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

use Dmorenof\SdkGenerator\Diagnostics\Diagnostic;

final readonly class DocumentBundle
{
    public function __construct(
        public array $document,
        public string $source,
        public string $sourceSha256,
        /** @var array<string, string> Canonical source file => SHA-256. */
        public array $sources,
        private array $origins,
    ) {}

    public function mapDiagnostic(Diagnostic $diagnostic): Diagnostic
    {
        [$source, $pointer] = $this->location($diagnostic->pointer);
        $chain = [];
        foreach ($diagnostic->referenceChain as $reference) {
            if (str_starts_with($reference, '#/')) {
                [$file, $at] = $this->location(substr($reference, 1));
                $reference = $file === $this->source ? '#' . $at : $file . '#' . $at;
            }
            $chain[] = $reference;
        }
        return new Diagnostic($diagnostic->code, $diagnostic->severity, $pointer, $diagnostic->message,
            $source, $diagnostic->operationId, $chain);
    }

    private function location(string $pointer): array
    {
        $best = '';
        $origin = null;
        foreach ($this->origins as $prefix => $candidate) {
            if (($pointer === $prefix || str_starts_with($pointer, $prefix . '/')) && strlen($prefix) > strlen($best)) {
                $best = $prefix;
                $origin = $candidate;
            }
        }
        return $origin === null ? [$this->source, $pointer]
            : [$origin['source'], $origin['pointer'] . substr($pointer, strlen($best))];
    }
}
