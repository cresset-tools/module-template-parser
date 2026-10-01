<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

/**
 * Where the legacy filter would READ a template differently from this engine.
 *
 * Compatible mode reproduces the filter's rendering, but not every way its regexes carve a
 * template up. README "Quirks it does not reproduce" lists the places this engine reads the
 * source as written and the filter does not; each renders differently on purpose, and each
 * renders without raising. That is fine for `check` and `diff`, which say so, and wrong for
 * Parser mode, which would serve the difference. So the Magento layer asks this before
 * serving, and declines a template it flags: the filter renders it, as it always has.
 *
 * Every rule is a property of the legacy patterns themselves, checked on the source:
 *
 * - `{{for`: ForDirective scans its body rather than rendering it, so no body this engine
 *   renders is the filter's (see README "{{for}} is a deliberate divergence").
 * - an extent holding another `{{`, or opening on a third brace: CONSTRUCTION_PATTERN is `{{name(.*?)}}`, lazy to the
 *   FIRST `}}` after an opener. Where that stretch holds another `{{`, the filter reads one
 *   construct where this engine reads text and a directive - `.a{{{var c}}}`, `{{A{{var x}}`,
 *   a missing brace (`{{var a}, bye {{var a}}`), a quoted `{{` (`{{trans "a {{b}}"}}`).
 * - a quote still open at that first `}}`: `{{trans "a }}b"}}` ends there for the filter,
 *   while this engine reads the quoted string whole.
 * - `{{if` or `{{depend` running straight into more letters with a closer later on: IF_PATTERN
 *   needs no space after the name, so `{{iframe}}...{{/if}}` is an {{if}} with the condition
 *   `rame` there, and an unknown directive here.
 *
 * False positives cost a fallback, never output; a miss serves a difference. So the rules
 * err wide.
 */
final class LegacyReading
{
    /**
     * The first place the filter would read `$source` differently, or null.
     *
     * @return ?array{rule:string,offset:int}
     */
    public static function firstDifference(string $source): ?array
    {
        if (!str_contains($source, '{{')) {
            return null;
        }

        if (preg_match('/\{\{for\s/i', $source, $m, PREG_OFFSET_CAPTURE)) {
            return ['rule' => 'a {{for}} loop', 'offset' => $m[0][1]];
        }

        if (preg_match('/\{\{(if|depend)[a-z]/i', $source, $m, PREG_OFFSET_CAPTURE)
            && preg_match('/\{\{\/' . $m[1][0] . '\s*\}\}/i', $source)
        ) {
            return ['rule' => sprintf('a name the filter reads as {{%s}}', strtolower($m[1][0])), 'offset' => $m[0][1]];
        }

        $offset = 0;
        while (($open = strpos($source, '{{', $offset)) !== false) {
            $close = strpos($source, '}}', $open + 2);
            if ($close === false) {
                break;
            }
            $extent = substr($source, $open + 2, $close - $open - 2);

            // `{{{`: the filter's match starts at the first two braces, this engine's opener
            // is the last two - `.a{{{var c}}}` is one construct there, `{` and a directive here.
            if (str_contains($extent, '{{') || str_starts_with($extent, '{')) {
                return ['rule' => 'a construct the filter reads to the first }}', 'offset' => $open];
            }
            if (self::endsInsideAQuote($extent)) {
                return ['rule' => 'a quoted }} the filter ends the directive at', 'offset' => $open];
            }

            $offset = $close + 2;
        }

        return null;
    }

    /** Whether a quote opened in `$text` is still open at its end. */
    private static function endsInsideAQuote(string $text): bool
    {
        $quote = null;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($quote === null) {
                if ($char === '"' || $char === "'") {
                    $quote = $char;
                }
            } elseif ($char === $quote) {
                $quote = null;
            }
        }

        return $quote !== null;
    }
}
