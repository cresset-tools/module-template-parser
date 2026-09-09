<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

/**
 * Parses a directive parameter blob into key/value pairs.
 *
 * A faithful port of Magento\Framework\Filter\Template\Tokenizer\Parameter, cursor semantics
 * and all, because every directive that takes `name=value` pairs goes through that class and
 * its rules are not the ones anyone would guess. A scanner written to be reasonable instead
 * disagreed with it five different ways, and each disagreement handed a directive parameters
 * the filter never produced - `class=Foo\ template=x.phtml` is ONE garbage class there and
 * was a class plus a live `template` here.
 *
 * Safety is not lost by being faithful: the blob this receives was already delimited by the
 * lexer, so unlike legacy's `(.*?)` capture a value still cannot run past `}}` into
 * unrelated template text.
 */
final class ParameterParser
{
    /** @return array<string,string> */
    public function parse(string $params): array
    {
        // setString() rawurldecodes before it tokenizes, so the decoding happens BEFORE the
        // blob is cut into parameters and can therefore create them: `a%3D$x` is `a=$x`. It
        // also means the path guards downstream see the string the host will be given -
        // `%2e%2e%2f` reaches them as `../`, which is the form they refuse.
        $source = rawurldecode($params);
        $length = strlen($source);
        if ($length === 0) {
            // isWhiteSpace() answers true for an empty string whatever the cursor says, so
            // legacy's loop falls straight out. Reading $source[0] here would not.
            return [];
        }

        $out = [];
        $name = '';
        $offset = 0;

        do {
            $char = $source[$offset];

            // NOT a reset of $name. tokenize() `continue`s here without clearing it, so a
            // name accumulates across whitespace: `a b=1` is the single parameter `ab`.
            if (self::isSpace($char)) {
                continue;
            }

            if ($char !== '=') {
                $name .= $char;
                continue;
            }

            $out[$name] = $this->readValue($source, $offset, $length);
            $name = '';
        } while (self::advance($offset, $length));

        // A trailing name with no `=` is dropped: tokenize() only ever writes a parameter
        // when it reaches one.
        return $out;
    }

    /**
     * getValue(): everything from just past the `=` to the next unescaped separator.
     *
     * The backslash branch applies to quoted AND unquoted values, which is the rule that is
     * easiest to miss and hardest to guess: in `a=1\ b=2` the escaped space does not end the
     * value, so the whole rest of the blob belongs to `a`. It also keeps the backslash unless
     * what follows is another backslash, so `"x\"y"` holds a backslash and a quote.
     */
    private function readValue(string $source, int &$offset, int $length): string
    {
        // next() refuses to step past the last character, so at the end of the blob the
        // cursor stays on the `=` and it becomes the value: `{{trans "%s" s=}}` prints `=`.
        self::advance($offset, $length);

        $char = $source[$offset];
        if (self::isSpace($char)) {
            return '';                  // whitespace right after `=` is an empty value
        }

        $quote = ($char === '"' || $char === "'") ? $char : null;
        $value = $quote === null ? $char : '';

        while (self::advance($offset, $length)) {
            $char = $source[$offset];

            if ($quote === null ? self::isSpace($char) : $char === $quote) {
                break;
            }

            if ($char === '\\') {
                self::advance($offset, $length);
                if ($source[$offset] !== '\\') {
                    $value .= '\\';
                }
                $value .= $source[$offset];
                continue;
            }

            $value .= $char;
        }

        return $value;
    }

    /** next(): advances unless already on the last character. */
    private static function advance(int &$offset, int $length): bool
    {
        if ($offset + 1 >= $length) {
            return false;
        }
        $offset++;

        return true;
    }

    /**
     * isWhiteSpace(): `trim($c) !== $c`, so the set is trim's default charlist.
     *
     * Not ctype_space(): that set has form feed and not NUL, and trim's has NUL and not form
     * feed. Both differences decide where a value ends, and getting it wrong the ctype way
     * split `a=1\fb=2` into two parameters where the filter has one.
     */
    private static function isSpace(string $char): bool
    {
        return trim($char) !== $char;
    }
}
