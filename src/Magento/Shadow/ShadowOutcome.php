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
 * legacy - safe, but a template that still leans on the old filter. A crash is anything
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

    /**
     * The candidate render completed but skipped something by policy - a layout handle not
     * allowed, a directive the render policy refuses. Declined, because what it skipped the
     * filter would have rendered.
     *
     * @param string[] $violations
     */
    public static function policyRefused(array $violations): self
    {
        return new self(self::REFUSED, [
            'error' => 'policy',
            'problem' => self::clip('the render policy refused part of this render: ' . implode('; ', self::causes($violations))),
            'line' => null,
            'column' => null,
            'hint' => 'allow it - for a layout handle, add it to AllowlistedLayoutRenderer\'s allowedHandles in di.xml - or change the template',
            'policy_violations' => self::causes($violations),
        ]);
    }

    /**
     * The host raised during the candidate render - a block's exception, a validator - and
     * the adapter turned it into its own error text, as the filter's catch does.
     *
     * A refusal rather than a crash: it is not this engine failing, and Parser mode answers it
     * the same way, by handing the render to legacy so the customer gets the filter's own
     * handling of that exception - its production message, its critical log - rather than
     * this engine's imitation of it.
     */
    public static function hostRaised(\Throwable $error): self
    {
        return new self(self::REFUSED, [
            'error' => $error::class,
            'problem' => self::clip('the host raised while rendering: ' . $error->getMessage()),
            'line' => null,
            'column' => null,
            'hint' => null,
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
