<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Lexer;

/**
 * Splits template source into text and directive tokens.
 *
 * Deliberately conservative: anything that does not look like a directive is emitted as
 * text, because real templates contain `{{` sequences that are not directives at all
 * (translation strings, JS templates). A construct we do not recognise must survive
 * verbatim rather than being reinterpreted.
 */
final class Lexer
{
    private const OPEN = '{{';
    private const CLOSE = '}}';

    /** Directive names Magento accepts: lower-case, bounded length. */
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    /** Enough to see any legal directive name plus its delimiter. */
    private const MAX_PEEK = 64;

    private readonly \Cresset\TemplateParser\DirectiveSpec $spec;

    /*
     * Nullable, not `= new DirectiveSpec()`. Magento's DI compiler stores a constructor
     * default verbatim and writes generated/metadata with var_export(), which emits
     * `DirectiveSpec::__set_state(...)` for an object - a fatal on every production request,
     * and invisible in developer mode. This one was missed when the others were fixed
     * because the sweep that checked for it could not resolve classes in subdirectories.
     */
    public function __construct(?\Cresset\TemplateParser\DirectiveSpec $spec = null)
    {
        $this->spec = $spec ?? new \Cresset\TemplateParser\DirectiveSpec();
    }

    /**
     * @return Token[]
     */
    public function tokenize(string $source): array
    {
        $tokens = [];
        $length = strlen($source);
        $cursor = 0;
        $textStart = 0;
        $knownClose = -1;

        while ($cursor < $length) {
            $open = strpos($source, self::OPEN, $cursor);
            if ($open === false) {
                break;
            }
            $afterOpen = $open + strlen(self::OPEN);

            // Decide from a bounded window first. Materialising the whole `{{`..`}}` span
            // just to discover it is not a directive makes a brace-dense document
            // quadratic: `{{A{{A{{A...` copies to the same distant closer every time.
            $candidate = $this->peek(substr($source, $afterOpen, self::MAX_PEEK));
            if ($candidate === null) {
                $cursor = $afterOpen;
                continue;
            }

            // A `{{` with ANOTHER `{{` inside its span is not the opener - the inner one is.
            // `.a{{{var color}}}` is the common shape: someone writes CSS and pastes a
            // directive straight after the brace. Legacy matches the outer span with an empty
            // name, rescues it through SimpleDirective at the inner offset, and prints an
            // encoded literal; treating the stray braces as text and letting the real
            // directive resolve is both more useful and what this engine already does one
            // character along, for `{{A{{var x}}`.
            //
            // Advance ONE byte, not two: in `{{{` the inner opener overlaps the outer, so
            // skipping the whole `{{` would step straight over it. peek() is O(1) on the
            // window, so a run of braces stays linear.
            // Applies to a well-formed-looking name too, not only a degenerate one. A span
            // ends at the first `}}`, so one missing brace makes a directive run on and
            // swallow whatever structure follows:
            //
            //     {{if a}}A{{var b}B{{/if}}   ->   var's parameters become ` b}B{{/if`
            //
            // The closing tag vanishes into the parameters and the {{if}} looks unclosed;
            // with `{{else}}` in the way it is worse, because BOTH branches then render.
            // Nothing legitimate puts a `{{` inside a directive's parameters - legacy's own
            // lazy capture mangles that too - so the run-on reading is never the right one.
            $quotesClose = false;

            // Only a plausible construct needs the closer located.
            if ($knownClose <= $open) {
                $found = strpos($source, self::CLOSE, $afterOpen);
                $knownClose = $found === false ? -1 : $found;
            }
            if ($knownClose === -1) {
                break;                       // unterminated: the remainder is text
            }
            $close = $knownClose;

            // A quoted parameter may legitimately contain both `{{` and `}}`:
            //
            //     {{trans "a {{b}}"}}
            //
            // The legacy filter cannot express that - its lazy `(.*?)}}` stops at the first
            // closer wherever it is, and it renders the leftovers as text - but a lexer can,
            // and there is no reason to inherit the limitation. Only re-scan when the naive
            // span actually holds a quote, so the common case keeps the plain strpos and the
            // cached closer.
            $spanLength = $close - $afterOpen;
            if (self::closerMayBeQuoted($source, $afterOpen, $spanLength)) {
                // An unterminated quote falls back to the naive closer rather than eating
                // the rest of the document: `{{trans "unterminated}}` is malformed either
                // way, and the filter reads it as a directive whose text will not parse.
                $quoted = self::closeOutsideQuotes($source, $afterOpen);
                if ($quoted !== null) {
                    $close = $quoted;
                    $knownClose = -1;        // the cache holds the naive closer; drop it
                    $quotesClose = true;
                }
            } else {
                // Balance is what the flag below actually needs, and the walk is not the only
                // thing that proves it: closerMayBeQuoted() says no precisely when the span
                // holds no quote at all or an EVEN number of each with no escape. Reading
                // the flag as "the walk ran" instead left an unterminated `{{` inside a
                // quoted value unsheltered, so `{{trans "50{{ off"}}` - which the filter
                // renders as `50&#123;&#123; off` - was re-scanned from the inner brace and
                // refused for a directive name that does not start with a letter.
                $quotesClose = true;
            }

            // Quotes only shelter a `{{` when they are quotes. In `{{var c}"{{else}}` the
            // `"` never closes - it is HTML around a directive that lost a brace - so
            // honouring it would hide the {{else}} and both branches would render again.
            // closeOutsideQuotes() returning a position is the proof that they balance.
            if (self::openerInsideSpan($source, $afterOpen, $close, $quotesClose)) {
                $cursor = $open + 1;
                continue;
            }

            [$type, $name] = $candidate;
            $raw = substr($source, $open, $close + strlen(self::CLOSE) - $open);
            $inner = substr($source, $afterOpen, $close - $afterOpen);

            $token = $this->build($type, $name, $inner, $raw, $open);
            if ($token === null) {
                $cursor = $afterOpen;
                continue;
            }

            if ($open > $textStart) {
                $tokens[] = new Token(TokenType::Text, substr($source, $textStart, $open - $textStart), $textStart);
            }
            $tokens[] = $token;

            $cursor = $close + strlen(self::CLOSE);
            $textStart = $cursor;
            $knownClose = -1;
        }

        if ($textStart < $length) {
            $tokens[] = new Token(TokenType::Text, substr($source, $textStart), $textStart);
        }

        return $tokens;
    }

    /**
     * Cheap pre-classification from the first few bytes after `{{`.
     *
     * @return array{0:TokenType,1:string}|null null when this is certainly not a construct
     */
    /**
     * Whether the naive closer might be inside a quoted value, cheaply.
     *
     * This runs for every directive in the document, so it has to stay at C speed. If the
     * closer were inside a quote, that quote would be open at the closer - which means an ODD
     * number of its character precedes it. Even counts of both quote characters therefore
     * prove the naive closer is outside quotes, and the careful character walk can be
     * skipped. A backslash in the span could escape a quote, so that bails to the walk too.
     *
     * Wrong only in the safe direction: a false positive costs one scan, never a mis-parse.
     */
    private static function closerMayBeQuoted(string $source, int $from, int $length): bool
    {
        if ($length <= 0) {
            return false;
        }
        if (strcspn($source, '"\'\\\\', $from, $length) === $length) {
            return false;                    // no quote and no escape in the span at all
        }

        return substr_count($source, '"', $from, $length) % 2 === 1
            || substr_count($source, "'", $from, $length) % 2 === 1
            || substr_count($source, '\\\\', $from, $length) > 0;
    }

    /**
     * The offset of the closing `}}` that is not inside a quoted parameter value.
     *
     * Returns null when the construct never closes outside quotes, in which case the rest of
     * the source is text. A backslash escapes the next character, as the parameter tokenizer
     * treats it, so `"a\"b"` does not end early.
     */
    private static function closeOutsideQuotes(string $source, int $from): ?int
    {
        $length = strlen($source);
        $quote = null;

        for ($i = $from; $i < $length; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '}' && ($source[$i + 1] ?? '') === '}') {
                return $i;
            }
        }

        return null;
    }

    /**
     * Whether another `{{` opens inside this span before it closes.
     *
     * Looks from one byte BEFORE the window so the overlapping `{{{` case is seen: there the
     * inner opener is the outer's second brace plus the next one.
     */
    private static function openerInsideSpan(
        string $source,
        int $afterOpen,
        int $close,
        bool $respectQuotes
    ): bool {
        // Fast path first, because this runs for every directive in the document and the
        // answer is almost always no. From one byte back: the span starts on the outer's
        // SECOND brace, so a `{{` there is that brace plus a new one - the overlap `{{{`
        // produces. A quoted `{{` cannot be found by a scan that has not seen one at all.
        $inner = strpos($source, self::OPEN, $afterOpen - 1);
        if ($inner === false || $inner >= $close) {
            return false;
        }
        if (!$respectQuotes) {
            return true;
        }

        // Only now, with an opener really inside a span whose quotes balance, is it worth
        // walking the characters to find out which side of a quote it fell on.
        $span = substr($source, $afterOpen - 1, min(self::MAX_PEEK, $close - $afterOpen + 2));
        $length = strlen($span);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $span[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            // A quoted value may hold `{{` legitimately - `{{trans "a {{b}}"}}` - so an
            // opener only counts when it is part of the construct rather than of its text.
            if ($respectQuotes && ($char === '"' || $char === "'")) {
                $quote = $char;
            } elseif ($char === '{' && ($span[$i + 1] ?? '') === '{') {
                return true;
            }
        }

        return false;
    }

    private function peek(string $window): ?array
    {
        if ($window === '') {
            return null;
        }

        if ($window[0] === '/') {
            if (!preg_match('#^/([A-Za-z][A-Za-z0-9_]*)\s*\}?#', $window, $m)) {
                return [TokenType::Degenerate, ''];
            }
            $name = strtolower($m[1]);
            // Legacy's patterns all carry /i, so {{/IF}} closes an {{if}}.
            if (!preg_match(self::NAME_PATTERN, $name)
                || ($name !== $m[1] && !$this->spec->isKnown($name))
            ) {
                return null;
            }
            // A closing tag carries nothing but its name, so whatever follows must be the
            // closing delimiter. Deciding that here rather than in build() is what keeps
            // `{{/a}x{{/a}x...` linear - see the note on the open-directive case below.
            //
            // ltrim() alone is not enough: a window that is ALL whitespace after the name
            // leaves $rest empty, the guard passes, and build() then rejects the construct
            // only after copying the whole span - the same quadratic shape this check exists
            // to prevent, reached with `{{/a` plus 62 spaces. A window that runs out of
            // whitespace without reaching `}}` cannot be a closing tag either, because a
            // legal one is at most a name plus the delimiter and both fit.
            $rest = ltrim(substr($window, 1 + strlen($m[1])));
            if (!str_starts_with($rest, self::CLOSE)) {
                return null;
            }
            return [TokenType::DirectiveClose, $name];
        }

        // Anything not starting with a letter is what legacy chokes on.
        if (!preg_match('/^[A-Za-z]/', $window)) {
            return [TokenType::Degenerate, ''];
        }

        if (!preg_match('/^([A-Za-z][A-Za-z0-9_]*)([\s}]|$)/', $window, $m)) {
            return null;                    // e.g. `{{A{{A` - a name run into more braces
        }

        $name = strtolower($m[1]);
        if (!preg_match(self::NAME_PATTERN, $name)) {
            return null;
        }

        // A lower-case name reads as an intended directive even when unknown, so strict mode
        // can report the typo. An upper-case one is usually prose - `{{Forgot Your
        // Password?}}`, which legacy also hands back verbatim - so it is a directive only
        // when the name is unmistakably one. Legacy's reflection is case-insensitive, so
        // {{VAR name}} does resolve there.
        if ($name !== $m[1] && !$this->spec->isKnown($name)) {
            return null;
        }

        // When the name butts straight up against a `}`, the only construct that can follow
        // is the closing `}}`. Rejecting the rest here matters for more than tidiness:
        // build() can only reach the same verdict after substr()-ing the whole span out to a
        // distant closer, and since the cursor then advances just two bytes, a document of
        // `{{a}{{a}{{a}...` is quadratic. Measured 2 MB in ~20 s before this check.
        $rest = substr($window, strlen($m[1]));
        if ($rest !== '' && $rest[0] === '}' && !str_starts_with($rest, self::CLOSE)) {
            return null;
        }

        return [TokenType::DirectiveOpen, $name];
    }

    /** Builds the token now that the full span is known, re-checking what the window could not. */
    private function build(TokenType $type, string $name, string $inner, string $raw, int $offset): ?Token
    {
        if ($type === TokenType::Degenerate) {
            return new Token(TokenType::Degenerate, $raw, $offset);
        }

        if ($type === TokenType::DirectiveClose) {
            // The window saw `/name`; the full inner must be exactly that.
            return strtolower(rtrim($inner)) === '/' . $name
                ? new Token(TokenType::DirectiveClose, $raw, $offset, $name)
                : null;
        }

        $params = substr($inner, strlen($name));
        if ($params !== '' && !preg_match('/^\s/', $params)) {
            return null;                    // the name did not end where the window thought
        }

        // NOT trimmed. Legacy's `$construction[2]` is the raw remainder, and the whitespace
        // at its edges is load-bearing: `{{var x|escape }}` has the modifier `escape ` there,
        // which is not a modifier it knows, so the value goes out UNESCAPED. Trimming here
        // quietly repaired that - safer, but it made compatible mode disagree with the filter
        // on a template that looks like it escapes. Everything downstream skips leading and
        // trailing whitespace the way the tokenizers do.
        return new Token(TokenType::DirectiveOpen, $raw, $offset, $name, $params);
    }

}
