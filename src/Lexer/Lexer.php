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

    /** Directive names Magento accepts: lower-case, bounded length. */
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    /**
     * @return Token[]
     */
    public function tokenize(string $source): array
    {
        $tokens = [];
        $length = strlen($source);
        $cursor = 0;
        $textStart = 0;

        while ($cursor < $length) {
            $open = strpos($source, self::OPEN, $cursor);
            if ($open === false) {
                break;
            }

            $close = strpos($source, self::CLOSE, $open + strlen(self::OPEN));
            if ($close === false) {
                // Unterminated: the remainder is text.
                break;
            }

            $inner = substr($source, $open + strlen(self::OPEN), $close - $open - strlen(self::OPEN));
            $token = $this->classify($inner, substr($source, $open, $close + strlen(self::CLOSE) - $open), $open);

            if ($token === null) {
                // Not a directive. Skip past the opener and keep accumulating text.
                $cursor = $open + strlen(self::OPEN);
                continue;
            }

            if ($open > $textStart) {
                $tokens[] = new Token(TokenType::Text, substr($source, $textStart, $open - $textStart), $textStart);
            }
            $tokens[] = $token;

            $cursor = $close + strlen(self::CLOSE);
            $textStart = $cursor;
        }

        if ($textStart < $length) {
            $tokens[] = new Token(TokenType::Text, substr($source, $textStart), $textStart);
        }

        return $tokens;
    }

    /**
     * Returns a directive token, or null when the construct is not a directive.
     */
    private function classify(string $inner, string $raw, int $offset): ?Token
    {
        if ($inner === '') {
            return null;
        }

        // The name must begin immediately after `{{` and be lower-case. Real templates
        // always write it that way; `{{Forgot Your Password?}}` and `{{ b }}` are prose,
        // and treating them as directives is how a parser starts executing content that
        // was never meant to be a directive.
        if ($inner[0] === '/') {
            $name = substr($inner, 1);
            return preg_match(self::NAME_PATTERN, $name)
                ? new Token(TokenType::DirectiveClose, $raw, $offset, $name)
                : null;
        }

        if (!preg_match('/^([a-z][a-z0-9_]*)(\s[\s\S]*)?$/', $inner, $m)) {
            return null;
        }

        if (!preg_match(self::NAME_PATTERN, $m[1])) {
            return null;
        }

        return new Token(TokenType::DirectiveOpen, $raw, $offset, $m[1], trim($m[2] ?? ''));
    }
}
