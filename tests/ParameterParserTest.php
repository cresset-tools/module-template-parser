<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\ParameterParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParameterParserTest extends TestCase
{
    #[DataProvider('cases')]
    public function testParse(string $input, array $expected): void
    {
        self::assertSame($expected, (new ParameterParser())->parse($input));
    }

    public static function cases(): array
    {
        return [
            'bare'            => ['class=Foo', ['class' => 'Foo']],
            'double quoted'   => ['class="Foo\\Bar"', ['class' => 'Foo\\Bar']],
            'single quoted'   => ["class='Foo Bar'", ['class' => 'Foo Bar']],
            'multiple'        => ['a=1 b="two" c=3', ['a' => '1', 'b' => 'two', 'c' => '3']],
            // Both of these are Tokenizer\Parameter's, verified against it: getValue()
            // stops on the whitespace right after the '=', and tokenize() only records a
            // parameter when it reaches an '=' at all, so a valueless word is dropped.
            'spaces around =' => ['a = 1', ['a' => '']],
            'valueless word'  => ['raw', []],
            'trailing word'   => ['class=X foo', ['class' => 'X']],
            'empty then next' => ['a= b=$x', ['a' => '', 'b' => '$x']],
            // rawurldecode() runs before tokenizing, so an encoded '=' becomes a separator.
            'percent encoded' => ['a%3Db', ['a' => 'b']],
            // getValue() keeps the backslash unless what follows is another backslash, so an
            // escaped quote arrives WITH its backslash. Verified against Tokenizer\Parameter.
            'escaped quote'   => ['t="say \\"hi\\""', ['t' => 'say \\"hi\\"']],
            // The backslash rule applies to unquoted values too, which is the one that is
            // easiest to miss: the escaped space does not end the value, so `a` swallows the
            // rest of the blob and `template` is never a parameter at all.
            'escaped space'   => ['class=Foo\\ template=evil.phtml', ['class' => 'Foo\\ template=evil.phtml']],
            // A name accumulates across whitespace, because tokenize() does not reset it.
            'split name'      => ['a b=1', ['ab' => '1']],
            // trim()'s charlist, not ctype_space()'s: NUL separates, form feed does not.
            'nul separates'   => ["a=1\0b=2", ['a' => '1', 'b' => '2']],
            'form feed does not' => ["a=1\x0Cb=2", ['a' => "1\x0Cb=2"]],
            // An empty key becomes the placeholder `%`, which then matches every `%`.
            'empty key'       => ['=x', ['' => 'x']],
            'empty'           => ['', []],
            'unterminated'    => ['a="unclosed', ['a' => 'unclosed']],
        ];
    }

    /** A value can never run past the directive; the lexer bounded it already. */
    public function testValueCannotSwallowBraces(): void
    {
        $parsed = (new ParameterParser())->parse('class=A}}{{var x');
        self::assertSame('A}}{{var', $parsed['class']);
    }
}
