<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

use InvalidArgumentException;

/**
 * What the generator does with a document that breaks the specification in a way that has one obvious reading.
 *
 * Reading a contract is strict: the code that parses and emits states what the specification says and refuses the rest. Wherever
 * a published document commonly breaks a rule, that code asks this policy instead of deciding itself. Strict, the policy refuses
 * with the message the strict reader would give; repairing, it records what was done (where and what) and lets the reader carry
 * on. The notes are what `--inspect` prints as `repaired_defect` and what `--strict` turns into a refusal, so every repair is
 * visible and none is silent.
 */
final class RepairPolicy
{
    /** @var list<array{where: string, message: string}> */
    private array $notes = [];

    public function __construct(private readonly bool $enabled) {}

    /** Whether defects are repaired (and recorded) instead of refused. */
    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * What was repaired so far, each with where and what was done.
     *
     * @return list<array{where: string, message: string}>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /** Records one repair the reader has already decided on (the caller checked {@see enabled()}). */
    public function note(string $where, string $message): void
    {
        $this->notes[] = ['where' => $where, 'message' => $message];
    }

    /**
     * A defect with an obvious reading: refused when strict, recorded when repairing.
     *
     * @param string $refusal What the strict reader says.
     * @param string $repair What was done instead.
     */
    public function mend(string $where, string $refusal, string $repair): void
    {
        if (!$this->enabled) {
            throw new InvalidArgumentException($refusal);
        }
        $this->note($where, $repair);
    }
}
