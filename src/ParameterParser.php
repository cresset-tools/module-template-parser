<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

/**
 * Parses a directive parameter blob into key/value pairs.
 *
 * Unlike the legacy `(.*?)` capture, this consumes the blob with an explicit scanner, so a
 * parameter value can never swallow a `}}` and run on into unrelated template text.
 */
final class ParameterParser
{
    public function __construct(private readonly bool $legacyQuirks = false)
    {
    }

    /** @return array<string,string> */
    public function parse(string $params): array
    {
        // Tokenizer\AbstractTokenizer::setString() rawurldecodes before it tokenizes, so the
        // decoding happens BEFORE the blob is cut into parameters and can therefore create
        // parameters: `a%3D$x` is `a=$x`. Decoding here rather than per value keeps that, and
        // means the path guards downstream see the string the host will actually be given -
        // `%2e%2e%2f` reaches them as `../`, which is the form they refuse.
        $params = rawurldecode($params);

        $out = [];
        $len = strlen($params);
        $i = 0;

        while ($i < $len) {
            while ($i < $len && ctype_space($params[$i])) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }

            $keyStart = $i;
            while ($i < $len && !ctype_space($params[$i]) && $params[$i] !== '=') {
                $i++;
            }
            $key = substr($params, $keyStart, $i - $keyStart);
            if ($key === '') {
                $i++;
                continue;
            }

            while ($i < $len && ctype_space($params[$i])) {
                $i++;
            }

            if ($i >= $len || $params[$i] !== '=') {
                // Dropped, not stored as a flag. tokenize() only ever writes a parameter when
                // it reaches an '=', so `{{block class=X foo}}` has one parameter there and
                // `foo` is not reachable at all - inventing `foo => ''` was this engine's own
                // idea, and it changed what a directive saw.
                continue;
            }
            // next() refuses to step past the last character, so at the end of the blob the
            // cursor stays on the '=' and getValue() reads it as the value: `{{trans "%s" s=}}`
            // renders "=". Nonsense, and reproduced only where reproducing it is the point.
            if ($i + 1 >= $len) {
                $out[$key] = $this->legacyQuirks ? '=' : '';
                $i++;
                continue;
            }

            $i++;                          // consume '='
            $out[$key] = $this->readValue($params, $i, $len);
        }

        return $out;
    }

    private function readValue(string $s, int &$i, int $len): string
    {
        if ($i < $len && ($s[$i] === '"' || $s[$i] === "'")) {
            $quote = $s[$i];
            $i++;
            $buf = '';
            while ($i < $len) {
                if ($s[$i] === '\\' && $i + 1 < $len) {
                    // A backslash escapes the quote or another backslash; without the second
                    // rule a value ending in `\` consumes its own terminator and runs on
                    // into the following parameters.
                    if ($s[$i + 1] === $quote || $s[$i + 1] === '\\') {
                        $buf .= $s[$i + 1];
                        $i += 2;
                        continue;
                    }
                }
                if ($s[$i] === $quote) {
                    $i++;
                    return $buf;
                }
                $buf .= $s[$i];
                $i++;
            }
            return $buf;                   // unterminated quote: take the rest
        }

        // No whitespace skip before this. getValue() steps past the '=' and returns
        // immediately if what it lands on is whitespace, so `a= b=$x` is a='' and b='$x',
        // not a='b=$x'. Starting on a space here takes no characters and yields '', which is
        // the same answer, and leaves `b=$x` for the caller's next pass.
        $start = $i;
        while ($i < $len && !ctype_space($s[$i])) {
            $i++;
        }

        return substr($s, $start, $i - $start);
    }
}
