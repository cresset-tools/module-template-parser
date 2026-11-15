<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Lexer;

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

    /**
     * Directive names Magento accepts: lower-case, bounded length.
     *
     * `$` rather than `\z` is safe here only because the subject is always a capture from
     * `[A-Za-z][A-Za-z0-9_]*`, which cannot hold a newline. The guards in PathGuard and
     * HostDirectives take values straight from template text and use `\z`, because there `$`
     * also matches before a trailing newline and let one through.
     */
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    /** Enough to see any legal directive name plus its delimiter. */
    private const MAX_PEEK = 64;

    private readonly \MageOS\TemplateParser\DirectiveSpec $spec;

    /*
     * Nullable, not `= new DirectiveSpec()` - a constructor default is fatal under the DI
     * compiler. See TemplateEngine.
     */
    public function __construct(?\MageOS\TemplateParser\DirectiveSpec $spec = null)
    {
        $this->spec = $spec ?? new \MageOS\TemplateParser\DirectiveSpec();
    }

    /**
     * The run-on sites from the last tokenize() that the legacy filter dies on: offset,
     * candidate name, and the paired directive the swallow leaves without a body or an opener.
     *
     * Only those. A run-on of a void directive over plain text merely loses output there, and
     * listing it bought nothing: the parser refuses only the fatal ones, and with every
     * run-on listed, sixteen harmless ones filled the cap and hid a fatal one after them.
     * Still capped - a document of broken openers is a run-on at nearly every brace - but now
     * only fatal sites count against it.
     *
     * @return list<array{0:int,1:string,2:string}>
     */
    public function runOns(): array
    {
        return $this->runOns;
    }

    /** @var list<array{0:int,1:string,2:string}> */
    private array $runOns = [];

    private const MAX_RUN_ONS = 16;

    /** Characters a directive name is spelled with, as peek() reads one. */
    private const NAME_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';

    /** What a walk stopped at: an unquoted `{{`, an unquoted `}}`, or the end of the source. */
    private const STOP_OPEN = 1;
    private const STOP_CLOSE = 2;
    private const STOP_END = 3;

    /**
     * The last walk inside each quote character: where it entered and where the quote closed,
     * or -1 for never. A later walk entering the same character inside that stretch is in the
     * same state from the next byte on, so it closes in the same place; without this, a run of
     * unterminated quotes was walked to the end of the source once per opener.
     *
     * @var array<string,array{0:int,1:int}>
     */
    private array $quoteWalks = [];

    /** @var array{0:int,1:int,2:int,3:int} from, to, and the parity of `"` and `'` between */
    private array $parity = [-1, -1, 0, 0];

    /** @var ?list<array{0:int,1:string}> where each paired directive opens, found on first need */
    private ?array $blockOpeners = null;

    private int $nextBlockOpener = 0;

    /** @return Token[] */
    public function tokenize(string $source): array
    {
        $this->runOns = [];
        $this->quoteWalks = [];
        $this->parity = [-1, -1, 0, 0];
        $this->blockOpeners = null;
        $this->nextBlockOpener = 0;

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

            // Only a plausible construct needs the closer located.
            if ($knownClose <= $open) {
                $found = strpos($source, self::CLOSE, $afterOpen);
                $knownClose = $found === false ? -1 : $found;
            }
            if ($knownClose === -1) {
                break;                       // unterminated: the remainder is text
            }

            $close = $this->closeOf($source, $afterOpen, $knownClose);
            if ($close === null) {
                // A `{{` with ANOTHER `{{` inside its span is not the opener - the inner one
                // is. `.a{{{var color}}}` is the common shape: someone writes CSS and pastes a
                // directive straight after the brace. Legacy matches the outer span with an
                // empty name, rescues it through SimpleDirective at the inner offset, and
                // prints an encoded literal; treating the stray braces as text and letting the
                // real directive resolve is both more useful and what this engine already does
                // one character along, for `{{A{{var x}}`.
                //
                // Applies to a well-formed-looking name too. A span ends at the first `}}`, so
                // one missing brace makes a directive run on and swallow what follows:
                //
                //     {{if a}}A{{var b}B{{/if}}   ->   var's parameters become ` b}B{{/if`
                //
                // The closing tag vanishes into the parameters and the {{if}} looks unclosed;
                // with `{{else}}` in the way it is worse, because BOTH branches then render.
                // Nothing legitimate puts an UNQUOTED `{{` inside a directive's parameters, so
                // the run-on reading is never the right one.
                //
                // Where this engine and the legacy regex part company the parser is told: here
                // the opener is text and the directive after it renders; there, the lazy
                // `(.*?)}}` swallows everything out to the first `}}` - which can leave a
                // paired directive with no body or a closer with no opener, a legacy fatal.
                $culprit = $this->runOnCulprit($source, $candidate[1], $afterOpen, $knownClose);
                if ($culprit !== null && count($this->runOns) < self::MAX_RUN_ONS) {
                    $this->runOns[] = [$open, $candidate[1], $culprit];
                }

                // Advance ONE byte, not two: in `{{{` the inner opener overlaps the outer, so
                // skipping the whole `{{` would step straight over it.
                $cursor = $open + 1;
                continue;
            }
            if ($close !== $knownClose) {
                $knownClose = -1;            // the cache holds the naive closer; drop it
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
     * Whether this engine closes the construct opened just before `$afterOpen` anywhere but at
     * `$firstCloser`, the first `}}` - which is where the legacy filter's lazy `(.*?)}}`
     * always closes it. LegacyReading asks, so that what the Magento layer declines is exactly
     * what this lexer reads differently, by the same rules.
     */
    public function readsPastFirstCloser(string $source, int $afterOpen, int $firstCloser): bool
    {
        return $this->closeOf($source, $afterOpen, $firstCloser) !== $firstCloser;
    }

    /**
     * Where the construct opened just before `$afterOpen` closes, or null when another `{{`
     * opens inside it first - a run-on.
     *
     * A quoted parameter may legitimately contain both `{{` and `}}`:
     *
     *     {{trans "a {{b}}"}}
     *
     * The legacy filter cannot express that - its lazy `(.*?)}}` stops at the first closer
     * wherever it is - but a lexer can, and there is no reason to inherit the limitation. So
     * quotes shelter both, by the rules in walk(). They only move the closer, though, when the
     * span to the first `}}` holds an ODD number of a quote character, so that a quote may be
     * open there. A backslash is not a reason to look further: an escaped quote leaves the
     * count even, so `{{trans "a\"b}}c"}}` closes at the first `}}` - where the filter closes
     * it too, its construction regex being lazy and escape-blind.
     *
     * Linear over a document: a walk stops at the first unquoted `{{` or `}}`, so a run-on
     * costs only the distance to the brace that ends it, and the quote count is kept as a
     * running parity against the shared first closer rather than recounted per opener.
     */
    private function closeOf(string $source, int $afterOpen, int $naive): ?int
    {
        // `{{{`: the opener's second brace and the next one open a construct of their own.
        if (($source[$afterOpen] ?? '') === '{') {
            return null;
        }

        [$stop, $at] = $this->walk($source, $afterOpen, true);
        if ($stop === self::STOP_OPEN && $at < $naive) {
            return null;                     // an unquoted `{{` before the first `}}`
        }
        if (!$this->oddQuotes($source, $afterOpen, $naive)) {
            return $naive;
        }

        // A quote may be open at the first `}}`: the closer is the first one outside quotes.
        [$closeStop, $closeAt] = $stop === self::STOP_CLOSE ? [$stop, $at] : $this->walk($source, $afterOpen, false);
        if ($closeStop === self::STOP_CLOSE) {
            // Unless an unquoted `{{` comes first, beyond the first `}}`.
            return $stop === self::STOP_OPEN && $at < $closeAt ? null : $closeAt;
        }

        // The quotes never balance. `{{trans "unterminated}}` is malformed either way, so the
        // first `}}` stands - and with nothing shown to be quoted, any `{{` before it runs on.
        $inner = strpos($source, self::OPEN, $afterOpen - 1);

        return $inner !== false && $inner < $naive ? null : $naive;
    }

    /**
     * The first `{{` (when `$stopAtOpen`) or `}}` outside a quoted value, from `$afterOpen`.
     *
     * A quote opens a value only where a value starts - right after `=`, or as the first thing
     * after the directive's name - and closes it only where it ends a word, not followed by a
     * letter or digit. Both halves are the apostrophe: `{{trans 'Don't forget'}}` is one
     * string in the filter, whose trans pattern accepts a closing quote only before whitespace
     * or the end, and reading `n't` as its end opened a new "quote" that swallowed the
     * document out to the next apostrophe. A backslash escapes the next character inside a
     * quote, as the parameter tokenizer reads it.
     *
     * @return array{0:int,1:int} STOP_OPEN, STOP_CLOSE or STOP_END, and where
     */
    private function walk(string $source, int $afterOpen, bool $stopAtOpen): array
    {
        $length = strlen($source);
        $nameEnd = self::nameEnd($source, $afterOpen);
        $stops = $stopAtOpen ? '{}"\'' : '}"\'';

        for ($i = $afterOpen; ; ) {
            $i += strcspn($source, $stops, $i);
            if ($i >= $length) {
                return [self::STOP_END, $length];
            }

            $char = $source[$i];
            if ($char === '{' || $char === '}') {
                if (($source[$i + 1] ?? '') === $char) {
                    return [$char === '{' ? self::STOP_OPEN : self::STOP_CLOSE, $i];
                }
                $i++;
                continue;
            }

            if (!self::opensValue($source, $nameEnd, $i)) {
                $i++;
                continue;
            }
            $end = $this->quoteEnd($source, $i, $char);
            if ($end === null) {
                return [self::STOP_END, $length];
            }
            $i = $end + 1;
        }
    }

    /** Where the quote opened at `$from` closes, or null when it never does. */
    private function quoteEnd(string $source, int $from, string $quote): ?int
    {
        // Inside the last walk's quoted stretch for this character: the same state from the
        // next byte on, so the same end. See $quoteWalks.
        $last = $this->quoteWalks[$quote] ?? null;
        if ($last !== null && $from > $last[0] && ($last[1] === -1 || $from < $last[1])) {
            return $last[1] === -1 ? null : $last[1];
        }

        $length = strlen($source);
        $end = -1;
        for ($i = $from + 1; $i < $length; ) {
            $i += strcspn($source, '\\' . $quote, $i);
            if ($i >= $length) {
                break;
            }
            if ($source[$i] === '\\') {
                $i += 2;
                continue;
            }
            if (!self::continuesAWord($source, $i + 1)) {
                $end = $i;
                break;
            }
            $i++;
        }

        $this->quoteWalks[$quote] = [$from, $end];

        return $end === -1 ? null : $end;
    }

    /** Whether the quote at `$at` starts a value: right after `=`, or first after the name. */
    private static function opensValue(string $source, int $nameEnd, int $at): bool
    {
        $before = $at - 1;
        while ($before >= $nameEnd && self::isSpace($source[$before])) {
            $before--;
        }

        return $before < $nameEnd || $source[$before] === '=';
    }

    /** A letter or digit, or a byte of a multibyte character - which is a letter too. */
    private static function continuesAWord(string $source, int $at): bool
    {
        if (!isset($source[$at])) {
            return false;
        }
        $byte = $source[$at];

        return ctype_alnum($byte) || ord($byte) >= 0x80;
    }

    private static function isSpace(string $char): bool
    {
        return $char === ' ' || $char === "\t" || $char === "\n" || $char === "\r" || $char === "\f" || $char === "\v";
    }

    /** Where the directive's name ends: after `name` or `/name`, at `$afterOpen` for neither. */
    private static function nameEnd(string $source, int $afterOpen): int
    {
        $at = $afterOpen + (($source[$afterOpen] ?? '') === '/' ? 1 : 0);

        return $at + strspn($source, self::NAME_CHARS, $at);
    }

    /**
     * Whether `"` or `'` occurs an odd number of times in [$from, $to).
     *
     * Kept as a running parity: the openers of a run-on chain share one first closer, so each
     * is the last one's count minus the bytes between them, rather than a recount of the span.
     */
    private function oddQuotes(string $source, int $from, int $to): bool
    {
        [$lastFrom, $lastTo, $double, $single] = $this->parity;
        if ($lastTo === $to && $lastFrom <= $from && $lastFrom >= 0) {
            $double ^= substr_count($source, '"', $lastFrom, $from - $lastFrom) % 2;
            $single ^= substr_count($source, "'", $lastFrom, $from - $lastFrom) % 2;
        } else {
            $double = substr_count($source, '"', $from, $to - $from) % 2;
            $single = substr_count($source, "'", $from, $to - $from) % 2;
        }
        $this->parity = [$from, $to, $double, $single];

        return $double === 1 || $single === 1;
    }

    /**
     * Why the filter dies on this run-on, as the paired directive it breaks - or null when it
     * merely loses output.
     *
     *   `Hi {{var a}, bye {{var a}}`   swallowed, renders `Hi ` - a divergence, not a crash
     *   `A{{if a} B {{var a}} C`       `if` with no body group - TypeError
     *   `Hi {{var a}, {{if a}}Y{{/if}}` the `{{if}}` opener is swallowed, so `{{/if}}` is a
     *                                  close with no opener - TypeError
     *
     * So it is a paired directive: the run-on candidate itself, or one opened inside the
     * stretch legacy swallows - which runs to the first `}}`, quotes or not. Found against a
     * list of every paired opener in the source, walked forward once.
     */
    private function runOnCulprit(string $source, string $name, int $afterOpen, int $firstCloser): ?string
    {
        if ($this->spec->isBlock($name)) {
            return $name;
        }

        $this->blockOpeners ??= $this->blockOpeners($source);
        while (isset($this->blockOpeners[$this->nextBlockOpener])
            && $this->blockOpeners[$this->nextBlockOpener][0] < $afterOpen
        ) {
            $this->nextBlockOpener++;
        }
        $next = $this->blockOpeners[$this->nextBlockOpener] ?? null;

        return $next !== null && $next[0] < $firstCloser ? $next[1] : null;
    }

    /** @return list<array{0:int,1:string}> offset and name of every paired directive's opener */
    private function blockOpeners(string $source): array
    {
        $found = [];
        $offset = 0;
        // One match at a time: a match table over a large source is how this once OOMed.
        while (preg_match('/\{\{([a-zA-Z]{1,10})\b/', $source, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $name = strtolower($m[1][0]);
            if ($this->spec->isBlock($name)) {
                $found[] = [$m[0][1], $name];
            }
            $offset = $m[0][1] + 2;
        }

        return $found;
    }

    /**
     * Cheap pre-classification from the first few bytes after `{{`.
     *
     * @return array{0:TokenType,1:string}|null null when this is certainly not a construct
     */
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
