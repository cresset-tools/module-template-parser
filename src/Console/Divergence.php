<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console;

use Cresset\TemplateParser\Diagnostics;

/** One template that renders differently, with enough context to see why. */
class Divergence
{
    public function __construct(
        public readonly TemplateSubject $subject,
        public readonly ?string $legacy,
        public readonly ?string $candidate,
        public readonly ?string $note,
    ) {
    }

    /** Byte offset of the first difference, or null when one side did not render. */
    public function firstDifference(): ?int
    {
        return $this->legacy === null || $this->candidate === null
            ? null
            : Diagnostics::firstDifferingByte($this->legacy, $this->candidate);
    }

    /**
     * @return array{0:string,1:string} the differing region on each side
     *
     * The lead-in is clamped to the width rather than fixed, because a fixed 20 bytes of it
     * at `--show=20` - the floor DiffCommand clamps to - spends the whole excerpt before
     * reaching the difference, and both sides then print the same text. At the default width
     * of 60 and anything wider the clamp is inert; below that it is what keeps the caret in
     * frame.
     */
    public function excerpt(int $width = 60): array
    {
        $at = $this->firstDifference() ?? 0;
        $from = max(0, $at - min(20, intdiv($width, 3)));

        return [
            $this->legacy === null ? '(did not render)' : substr($this->legacy, $from, $width),
            $this->candidate === null ? '(did not render)' : substr($this->candidate, $from, $width),
        ];
    }
}
