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
            'spaces around =' => ['a = 1', ['a' => '1']],
            'flag'            => ['raw', ['raw' => '']],
            'escaped quote'   => ['t="say \\"hi\\""', ['t' => 'say "hi"']],
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
