<?php
declare(strict_types=1);

namespace MageOS\TemplateParser;

/**
 * Whether a difference from a filter without the output neutralizer is this engine's
 * deliberate one, and nothing else.
 *
 * Mage-OS 3.5.0 added `Template\DirectiveOutputNeutralizer`, which encodes `{{` in a resolved
 * directive's output so a value is never read as a directive. This engine encodes it on every
 * host. A filter from before it - Mage-OS before 3.5.0, Magento Open Source without the class
 * - leaves the braces as they are, and may then re-parse them: its str_replace() of each
 * directive's result runs over results already in place, so an address street of
 * `Hoofdstraat 1 {{var city}}` comes out as `Hoofdstraat 1 Utrecht` when the format also says
 * `{{var city}}`. Those renders differ, and this engine's output is the one meant: the
 * difference is known and kept, not a divergence to fix or fall back from.
 *
 * Kept, not assumed. A render is only called deliberate when the difference is FULLY
 * explained, decided against a second render of the same template with the neutralizer in
 * marking mode (Options::$markNeutralizedOutput): each `{{` it would have encoded is left as
 * OPEN, and END closes that directive's output. Then:
 *
 * 1. the marked render is the candidate up to brace encoding - decoded, both are the same
 *    text - so it is the same render, not a later one that came out differently;
 * 2. legacy matches the marked render with every value-borne construct - from OPEN to the
 *    first `}}` inside that directive's output - standing for whatever legacy made of it:
 *    the text as written, or what re-parsing it produced. Everything else must match byte
 *    for byte, the text of the value around the construct included. An OPEN with no `}}` in
 *    its output is a bare `{{`, which legacy prints as it is.
 *
 * So any other difference in the same render - an escaping, a branch, a byte outside the
 * value - leaves it a divergence. A re-parse that reaches past the value into the template's
 * own text does too; it is reported, never hidden.
 */
final class DeliberateDifference
{
    /** Private-use code points: no template or value has a reason to hold them. */
    public const OPEN = "\u{F8F0}";
    public const END = "\u{F8F1}";

    /** Marks a resolved directive's output where the neutralizer would have encoded it. */
    public static function mark(string $output): string
    {
        // Edge braces too are encoded only by the neutralizer; without it they stay, as the
        // older filter prints them.
        return str_contains($output, '{{')
            ? str_replace('{{', self::OPEN, $output) . self::END
            : $output;
    }

    /**
     * Whether `$candidate` differs from `$legacy` only by values whose `{{` this engine
     * encoded and the filter did not - see the class docblock.
     *
     * @param string $marked the same render with Options::$markNeutralizedOutput
     */
    public static function explains(string $candidate, string $marked, string $legacy): bool
    {
        if (!str_contains($marked, self::OPEN)
            || str_contains($legacy, self::OPEN) || str_contains($legacy, self::END)
            || str_contains($candidate, self::OPEN) || str_contains($candidate, self::END)
        ) {
            return false;
        }

        $decode = static fn (string $s): string => str_replace(['&#123;', self::OPEN, self::END], ['{', '{{', ''], $s);
        if ($decode($candidate) !== $decode($marked)) {
            return false;
        }

        return self::matches(self::segments($marked), $legacy);
    }

    /**
     * The marked render as the literal text between wildcards.
     *
     * @return list<string> literal, wildcard, literal, ... - always an odd count
     */
    private static function segments(string $marked): array
    {
        $segments = [];
        $literal = '';
        $at = 0;
        $open = strlen(self::OPEN);

        while (($i = strpos($marked, self::OPEN, $at)) !== false) {
            $literal .= substr($marked, $at, $i - $at);
            $end = strpos($marked, self::END, $i);
            $close = strpos($marked, '}}', $i + $open);

            if ($close !== false && ($end === false || $close < $end)) {
                $segments[] = str_replace(self::END, '', $literal);
                $literal = '';
                $at = $close + 2;
                continue;
            }

            // A bare `{{` in the value: no construct, so the filter has nothing to re-parse.
            $literal .= '{{';
            $at = $i + $open;
        }
        $segments[] = str_replace(self::END, '', $literal . substr($marked, $at));

        return $segments;
    }

    /**
     * Anchored glob match: `$segments` in order, any text between each pair, nothing before the
     * first or after the last. The earliest place each literal fits is always a safe choice,
     * so this is linear and never backtracks - unlike a regex, which also has a size limit an
     * email of literal text runs into.
     *
     * @param list<string> $segments
     */
    private static function matches(array $segments, string $subject): bool
    {
        $first = array_shift($segments);
        $last = $segments === [] ? null : array_pop($segments);
        if (!str_starts_with($subject, $first)) {
            return false;
        }
        if ($last === null) {
            return $subject === $first;
        }

        $at = strlen($first);
        foreach ($segments as $literal) {
            $found = $literal === '' ? $at : strpos($subject, $literal, $at);
            if ($found === false) {
                return false;
            }
            $at = $found + strlen($literal);
        }

        return strlen($subject) - strlen($last) >= $at && str_ends_with($subject, $last);
    }
}
