<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use MageOS\TemplateParser\TemplateEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvaluatorTest extends TestCase
{
    private TemplateEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new TemplateEngine();
    }

    #[DataProvider('renderCases')]
    public function testRender(string $source, array $vars, string $expected): void
    {
        self::assertSame($expected, $this->engine->render($source, $vars));
    }

    public static function renderCases(): array
    {
        return [
            'plain text'         => ['hello', [], 'hello'],
            'var'                => ['{{var x}}', ['x' => 'v'], 'v'],
            'var escaped'        => ['{{var x}}', ['x' => '<i>'], '&lt;i&gt;'],
            'var raw'            => ['{{var x|raw}}', ['x' => '<i>'], '<i>'],
            // The value carries a character escaping WOULD change, so this case also pins
            // down that a known modifier still escapes first; "a\nb" alone passed against a
            // renderer that had stopped escaping entirely.
            'var nl2br'          => ["{{var x|nl2br}}", ['x' => "<b>\nx"], "&lt;b&gt;<br />\nx"],
            'if true'            => ['{{if a}}Y{{/if}}', ['a' => 1], 'Y'],
            'if false'           => ['{{if a}}Y{{/if}}', ['a' => 0], ''],
            'if else true'       => ['{{if a}}Y{{else}}N{{/if}}', ['a' => 1], 'Y'],
            'if else false'      => ['{{if a}}Y{{else}}N{{/if}}', ['a' => ''], 'N'],
            'depend set'         => ['{{depend a}}Y{{/depend}}', ['a' => 'x'], 'Y'],
            'depend falsy'       => ['{{depend a}}Y{{/depend}}', ['a' => ''], ''],
            'nested'             => ['{{if a}}{{depend b}}Z{{/depend}}{{/if}}', ['a' => 1, 'b' => 1], 'Z'],
            'dotted array'       => ['{{var a.b}}', ['a' => ['b' => 'deep']], 'deep'],
            'for loop'           => ['{{for i in xs}}[{{var i}}]{{/for}}', ['xs' => ['a', 'b']], '[a][b]'],
            'for empty'          => ['{{for i in xs}}x{{/for}}', ['xs' => []], ''],
            'trans literal'      => ['{{trans "Hello"}}', [], 'Hello'],
            'trans placeholder'  => ['{{trans "Hi %n" n=who}}', ['who' => 'Jan'], 'Hi Jan'],
            'non-directive text' => ['{{Forgot Your Password?}}', [], '{{Forgot Your Password?}}'],
        ];
    }

    public function testUnknownDirectiveIsEmittedVerbatimInLenientMode(): void
    {
        // No handler registered for `layout`; it must round-trip rather than vanish or run.
        self::assertSame('{{layout handle=foo}}', TemplateEngine::lenient()->render('{{layout handle=foo}}'));
        self::assertSame('a{{nope x}}b', TemplateEngine::lenient()->render('a{{nope x}}b'));
        self::assertSame('[]', TemplateEngine::lenient()->render('[{{var nope}}]'));
    }

    public function testForLoopScopeDoesNotLeak(): void
    {
        // Lenient: the loop variable is simply gone once the loop ends.
        self::assertSame(
            'a[]',
            TemplateEngine::lenient()->render('{{for i in xs}}{{var i}}{{/for}}[{{var i}}]', ['xs' => ['a']])
        );

        // Strict: reading it afterwards is a mistake worth reporting.
        $this->expectException(\MageOS\TemplateParser\UnknownVariableError::class);
        $this->engine->render('{{for i in xs}}{{var i}}{{/for}}[{{var i}}]', ['xs' => ['a']]);
    }
}
