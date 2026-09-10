<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\TemplateEngine;
use Cresset\TemplateParser\UnknownVariableError;
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
            // A `$` makes a parameter a variable. Without one it is a literal, even when a
            // variable of that name exists - which is why the second case is not 'Hi Jan'.
            'trans placeholder'  => ['{{trans "Hi %n" n=$who}}', ['who' => 'Jan'], 'Hi Jan'],
            'trans literal arg'  => ['{{trans "Hi %n" n=who}}', ['who' => 'Jan'], 'Hi who'],
            'non-directive text' => ['{{Forgot Your Password?}}', [], '{{Forgot Your Password?}}'],
        ];
    }

    /**
     * Legacy renders a parameter it cannot resolve as nothing and says nothing about it,
     * which is how a subject line comes out reading "Welcome to ". Strict mode names it.
     */
    public function testUnresolvedDollarParameterIsEmptyExceptInStrictMode(): void
    {
        self::assertSame('Hi ', TemplateEngine::compatible()->render('{{trans "Hi %n" n=$nope}}'));
        self::assertSame('Hi ', TemplateEngine::lenient()->render('{{trans "Hi %n" n=$nope}}'));

        $this->expectException(UnknownVariableError::class);
        $this->engine->render('{{trans "Hi %n" n=$nope}}');
    }

    /**
     * An Evaluator built from options alone is built consistently.
     *
     * The CLI constructs one that way, and while the collaborators defaulted independently
     * `--mode=compatible` got the directive quirks with a strict resolver underneath: no
     * `{{var a%2Eb}}` decoding, no scalar-parent rule, none of it.
     */
    public function testOptionsAloneConfigureTheCollaboratorsToo(): void
    {
        $engine = new TemplateEngine(
            new \Cresset\TemplateParser\Parser(options: Options::compatible()),
            new \Cresset\TemplateParser\Evaluator(options: Options::compatible())
        );

        // rawurldecode in the resolver, and the trailing-'=' quirk in the parameter parser.
        self::assertSame('DEEP', $engine->render('{{var a%2Eb}}', ['a' => ['b' => 'DEEP']]));
        self::assertSame('T =', $engine->render('{{trans "T %s" s=}}'));
    }

    /**
     * {{for}} injects `loop.index`, zero-based, as ForDirective does.
     *
     * Legacy builds it with `setData('index', $loopIndex++)` from 0. Without it a template
     * that prints the index renders nothing here - the silent kind of regression, and one
     * the language reference caught rather than any test.
     */
    public function testForInjectsAZeroBasedLoopIndex(): void
    {
        self::assertSame(
            '[0][1][2]',
            $this->engine->render('{{for i in xs}}[{{var loop.index}}]{{/for}}', ['xs' => ['a', 'b', 'c']])
        );

        // Legacy overwrites whatever `loop` was in scope, so this does too.
        self::assertSame(
            '[0]',
            $this->engine->render('{{for i in xs}}[{{var loop.index}}]{{/for}}', ['xs' => ['a'], 'loop' => 'mine'])
        );
    }

    /**
     * Whitespace anywhere in a name is skipped, not just at the ends.
     *
     * Tokenizer\Variable::isWhiteSpace() skips " \t\n\r\0\x0B" at every position, so
     * `{{var a b}}` reads the variable `ab`. Trimming the segment ends instead made it read
     * one literally called `a b` - both resolve, to different data, which is the quiet kind
     * of divergence. The corpus cannot reach this: it has no variable whose name differs
     * from another only by an interior space.
     */
    public function testWhitespaceInsideANameIsSkipped(): void
    {
        $variables = ['ab' => 'TIGHT', 'a b' => 'SPACED'];
        $engine = TemplateEngine::compatible();

        self::assertSame('TIGHT', $engine->render('{{var a b}}', $variables));
        self::assertSame('TIGHT', $engine->render("{{var a\tb}}", $variables));
        self::assertSame('TIGHT', $engine->render("{{var a\x00b}}", $variables));

        // And through a path segment, not only the head.
        self::assertSame('DEEP', $engine->render('{{var x.a b}}', ['x' => ['ab' => 'DEEP']]));
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
        $this->expectException(\Cresset\TemplateParser\UnknownVariableError::class);
        $this->engine->render('{{for i in xs}}{{var i}}{{/for}}[{{var i}}]', ['xs' => ['a']]);
    }
}
