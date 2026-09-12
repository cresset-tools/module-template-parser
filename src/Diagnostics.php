<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

/**
 * Turns a byte offset into a human-readable location and source excerpt.
 */
final class Diagnostics
{
    private const CONTEXT_LINES = 2;

    private static ?string $indexedSource = null;

    /** @var int[] Line-start offsets for the most recently located source. */
    private static array $lineStarts = [];

    /**
     * @return array{line:int,column:int}
     *
     * Indexed rather than scanned. The obvious implementation copies the prefix with
     * substr() and counts newlines in it, which is O(offset) per call - fine for one error,
     * quadratic for a template that reports many. A policy-refused directive repeated
     * thousands of times is an ordinary shape, and it made a 2.7 MB template take ten
     * seconds in the shipped adapter's default configuration.
     */
    public static function locate(string $source, int $offset): array
    {
        $offset = max(0, min($offset, strlen($source)));
        $starts = self::lineStarts($source);

        // Binary search for the last line starting at or before the offset.
        $low = 0;
        $high = count($starts) - 1;
        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            if ($starts[$mid] <= $offset) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return ['line' => $low + 1, 'column' => $offset - $starts[$low] + 1];
    }

    /**
     * @return int[]
     *
     * Memoised for one source at a time, which is all a single render needs. The identity
     * check is a pointer comparison in PHP when the same string is passed back, so the
     * common case costs nothing.
     */
    private static function lineStarts(string $source): array
    {
        if (self::$indexedSource !== null && self::$indexedSource === $source) {
            return self::$lineStarts;
        }

        $starts = [0];
        $at = 0;
        while (($at = strpos($source, "\n", $at)) !== false) {
            $starts[] = ++$at;
        }

        self::$indexedSource = $source;
        self::$lineStarts = $starts;

        return $starts;
    }

    /**
     * Renders the offending line with a caret underneath, plus a little context.
     */
    public static function excerpt(string $source, int $offset): string
    {
        ['line' => $line, 'column' => $column] = self::locate($source, $offset);
        // Split on "\n" alone, because locate() counts "\n" alone. Splitting on \R here
        // would number the lines differently to the caret being placed on them, and a lone
        // CR or form feed in the template would draw the caret under the wrong line. It also
        // leaves the CR of a CRLF pair on the end of each line, which the rtrim below takes.
        $lines = explode("\n", $source);
        $first = max(1, $line - self::CONTEXT_LINES);
        $last = min(count($lines), $line + self::CONTEXT_LINES);
        $width = strlen((string)$last);

        $out = '';
        for ($i = $first; $i <= $last; $i++) {
            $text = $lines[$i - 1] ?? '';
            $out .= sprintf("  %{$width}d | %s\n", $i, rtrim($text, "\r"));
            if ($i === $line) {
                $out .= sprintf("  %s | %s^\n", str_repeat(' ', $width), str_repeat(' ', max(0, $column - 1)));
            }
        }

        return rtrim($out, "\n");
    }

    /**
     * Where two renders stop agreeing, or the length of the shorter when one is a prefix.
     *
     * Here rather than on either caller: `Console\Divergence` builds a report out of it and
     * `Magento\ShadowComparator` logs one line from a live render, and a runtime plugin
     * reaching into the CLI namespace for a byte loop is the wrong way round.
     */
    public static function firstDifferingByte(string $a, string $b): int
    {
        $limit = min(strlen($a), strlen($b));
        for ($i = 0; $i < $limit; $i++) {
            if ($a[$i] !== $b[$i]) {
                return $i;
            }
        }

        return $limit;
    }

    /**
     * Closest match from a candidate list, for "did you mean" hints.
     *
     * @param string[] $candidates
     */
    public static function suggest(string $needle, array $candidates): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        $limit = max(2, (int)floor(strlen($needle) / 2));

        foreach ($candidates as $candidate) {
            $distance = levenshtein(strtolower($needle), strtolower($candidate));
            if ($distance < $bestDistance && $distance <= $limit) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }
}
