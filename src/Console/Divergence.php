<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console;

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
        if ($this->legacy === null || $this->candidate === null) {
            return null;
        }

        $limit = min(strlen($this->legacy), strlen($this->candidate));
        for ($i = 0; $i < $limit; $i++) {
            if ($this->legacy[$i] !== $this->candidate[$i]) {
                return $i;
            }
        }

        return $limit;
    }

    /** @return array{0:string,1:string} the differing region on each side */
    public function excerpt(int $width = 60): array
    {
        $at = $this->firstDifference() ?? 0;
        $from = max(0, $at - 20);

        return [
            $this->legacy === null ? '(did not render)' : substr($this->legacy, $from, $width),
            $this->candidate === null ? '(did not render)' : substr($this->candidate, $from, $width),
        ];
    }
}
