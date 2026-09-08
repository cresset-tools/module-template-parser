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

    public function __construct(private readonly \Cresset\TemplateParser\DirectiveSpec $spec = new \Cresset\TemplateParser\DirectiveSpec())
    {
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

            // Only a plausible construct needs the closer located.
            if ($knownClose <= $open) {
                $found = strpos($source, self::CLOSE, $afterOpen);
                $knownClose = $found === false ? -1 : $found;
            }
            if ($knownClose === -1) {
                break;                       // unterminated: the remainder is text
            }
            $close = $knownClose;

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
            $rest = ltrim(substr($window, 1 + strlen($m[1])));
            if ($rest !== '' && !str_starts_with($rest, self::CLOSE)) {
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

        return new Token(TokenType::DirectiveOpen, $raw, $offset, $name, trim($params));
    }

}
