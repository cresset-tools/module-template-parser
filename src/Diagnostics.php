<?php
declare(strict_types=1);

namespace MageOS\TemplateParser;

/**
 * Turns a byte offset into a human-readable location and source excerpt.
 */
final class Diagnostics
{
    private const CONTEXT_LINES = 2;

    /** @return array{line:int,column:int} */
    public static function locate(string $source, int $offset): array
    {
        $offset = max(0, min($offset, strlen($source)));
        $before = substr($source, 0, $offset);
        $line = substr_count($before, "\n") + 1;
        $lastNewline = strrpos($before, "\n");
        $column = $lastNewline === false ? $offset + 1 : $offset - $lastNewline;

        return ['line' => $line, 'column' => $column];
    }

    /**
     * Renders the offending line with a caret underneath, plus a little context.
     */
    public static function excerpt(string $source, int $offset): string
    {
        ['line' => $line, 'column' => $column] = self::locate($source, $offset);
        $lines = preg_split('/\R/', $source) ?: [];
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
