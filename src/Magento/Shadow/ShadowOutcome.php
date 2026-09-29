<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento\Shadow;

use Cresset\TemplateParser\TemplateError;

/**
 * What one Shadow comparison found.
 *
 * Four outcomes, because they call for four different things. An agreement is evidence for
 * switching. A divergence is a render Parser would have served differently. A refusal is a
 * construct this engine declines on purpose, which Parser mode answers by falling back to
 * legacy (#2) - safe, but a template that still leans on the old filter. A crash is anything
 * else the engine raised: a bug here, not a property of the template.
 *
 * The detail never holds rendered output. A rendered email carries a customer's name and
 * address, so a divergence is described by lengths, the first differing byte and the causes
 * the engine recorded - never by an excerpt of either side.
 */
final class ShadowOutcome
{
    public const AGREE = 'agree';
    public const DIVERGE = 'diverge';
    public const REFUSED = 'refused';
    public const CRASHED = 'crashed';

    /** Enough to name the cause; a template that trips hundreds of these repeats one. */
    private const MAX_CAUSES = 10;
    private const MAX_TEXT = 500;

    /** @param array<string,mixed> $detail */
    private function __construct(public readonly string $outcome, public readonly array $detail = [])
    {
    }

    public static function agreed(): self
    {
        return new self(self::AGREE);
    }

    /**
     * @param string[] $violations policy refusals the candidate render recorded
     * @param string[] $incompatibilities constructs it produced that legacy could not have
     */
    public static function diverged(
        int $legacyLength,
        int $candidateLength,
        ?int $firstDifferenceAt,
        array $violations,
        array $incompatibilities
    ): self {
        return new self(self::DIVERGE, [
            'legacy_length' => $legacyLength,
            'candidate_length' => $candidateLength,
            'first_difference_at' => $firstDifferenceAt,
            'policy_violations' => self::causes($violations),
            'legacy_incompatibilities' => self::causes($incompatibilities),
        ]);
    }

    /**
     * The problem and where, not the message: TemplateError's message carries an excerpt of
     * the source, which is the template rather than a customer's data, but is also long and
     * says nothing the line and column do not.
     */
    public static function refused(TemplateError $error): self
    {
        return new self(self::REFUSED, [
            'error' => $error::class,
            'problem' => self::clip($error->problem),
            'line' => $error->sourceLine,
            'column' => $error->sourceColumn,
            'hint' => $error->hint !== null ? self::clip($error->hint) : null,
        ]);
    }

    public static function crashed(\Throwable $error): self
    {
        return new self(self::CRASHED, [
            'error' => $error::class,
            'message' => self::clip($error->getMessage()),
        ]);
    }

    /**
     * @param string[] $causes
     * @return string[]
     */
    private static function causes(array $causes): array
    {
        return array_map(self::clip(...), array_slice(array_values($causes), 0, self::MAX_CAUSES));
    }

    private static function clip(string $text): string
    {
        return mb_strlen($text) > self::MAX_TEXT ? mb_substr($text, 0, self::MAX_TEXT) . '…' : $text;
    }
}
