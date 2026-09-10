<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Ast\DirectiveNode;
use Cresset\TemplateParser\NestingLimitError;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\SyntaxError;
use Cresset\TemplateParser\TemplateEngine;
use Cresset\TemplateParser\UnknownVariableError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A corpus of templates that are meant to fail, and the failure they are meant to produce.
 *
 * Every case is asserted twice: strict mode must raise the specific error, and compatible
 * mode must render without raising. Both halves matter - an engine that raises on
 * everything is as useless as one that raises on nothing.
 */
final class MalformedTemplateTest extends TestCase
{
    /**
     * Each case declares the error strict mode must raise, and whether the LEGACY filter
     * could render it at all - which is what compatible mode has to track.
     *
     * @return array<string,array{0:string,1:class-string,2:bool}>
     */
    public static function malformed(): array
    {
        return [
            // --- unbalanced structure ---
            'unclosed if'            => ['{{if a}}body', SyntaxError::class, false],
            'unclosed depend'        => ['{{depend a}}body', SyntaxError::class, false],
            'unclosed nested'        => ['{{if a}}{{depend b}}x{{/depend}}', SyntaxError::class, false],
            'stray close'            => ['body{{/if}}', SyntaxError::class, false],
            'stray close mismatched' => ['{{if a}}x{{/depend}}{{/if}}', SyntaxError::class, false],
            'close before open'      => ['{{/if}}{{if a}}x{{/if}}', SyntaxError::class, false],
            'double close'           => ['{{if a}}x{{/if}}{{/if}}', SyntaxError::class, false],
            'else outside if'        => ['{{depend a}}x{{else}}y{{/depend}}', SyntaxError::class, true],

            // --- unknown names ---
            'misspelled directive'   => ['{{vr a}}', SyntaxError::class, true],
            'invented directive'     => ['{{frobnicate a}}', SyntaxError::class, true],
            'misspelled close'       => ['{{if a}}x{{/iff}}', SyntaxError::class, false],

            // --- unknown variables ---
            'unknown var'            => ['{{var nope}}', UnknownVariableError::class, true],
            'unknown in condition'   => ['{{if nope}}x{{/if}}', UnknownVariableError::class, true],
            'unknown path segment'   => ['{{var a.nope}}', UnknownVariableError::class, true],

            // --- hostile input ---
            'deep nesting'           => [str_repeat('{{if a}}', 40) . 'x' . str_repeat('{{/if}}', 40), NestingLimitError::class, false],
        ];
    }

    /** @param class-string $expected */
    #[DataProvider('malformed')]
    public function testStrictModeRaisesTheRightError(string $template, string $expected, bool $_legacyOk): void
    {
        $this->expectException($expected);
        (new TemplateEngine())->render($template, ['a' => 1]);
    }

    /**
     * Compatible mode tracks legacy's capability, so what it does with a broken template
     * depends on whether legacy could render it. Unbalanced structure is a TypeError there,
     * so it is refused here; a misspelled directive name is handed back verbatim there, so
     * it renders here.
     */
    #[DataProvider('malformed')]
    public function testCompatibleModeFollowsLegacyCapability(string $template, string $_expected, bool $legacyCouldRender): void
    {
        $engine = TemplateEngine::compatible();

        if ($legacyCouldRender) {
            self::assertIsString($engine->render($template, ['a' => 1]));
            return;
        }

        $this->expectException(\Cresset\TemplateParser\LegacyIncompatibleError::class);
        $engine->render($template, ['a' => 1]);
    }

    /**
     * Permissive mode renders all of them - a merchant with a broken template stored in
     * their database gets degraded output rather than a failed order email.
     */
    #[DataProvider('malformed')]
    public function testPermissiveModeRendersAllOfThem(string $template, string $_expected, bool $_legacyOk): void
    {
        $engine = TemplateEngine::withOptions(
            \Cresset\TemplateParser\Options::compatible()->withRefuseLegacyIncompatible(false)
        );

        // The nesting bound is a resource limit, not a compatibility one, so it still applies.
        if (str_contains($template, str_repeat('{{if a}}', 5))) {
            $this->expectException(NestingLimitError::class);
        }

        $out = $engine->render($template, ['a' => 1]);

        // assertIsString() on a `: string` return can never fail. The claim is that broken
        // input degrades rather than raising, so assert the degraded output itself.
        self::assertStringNotContainsString('<?php', $out);
        self::assertSame($out, $engine->render($template, ['a' => 1]), 'rendering is not deterministic');
    }


    /** Hostile input must not execute, hang, or emit PHP, in any mode. */
    #[DataProvider('hostile')]
    public function testHostileInputIsInert(string $template, array $variables): void
    {
        $executed = [];
        $engine = TemplateEngine::compatible();
        $engine->evaluator()->register('block', function (DirectiveNode $n) use (&$executed, $engine): string {
            $executed[] = $engine->evaluator()->params($n)['class'] ?? '';
            return '';
        });

        $out = $engine->render($template, $variables);

        self::assertSame([], $executed, 'a directive arriving through data was executed');
        // The payload may well appear in the output - {{var|raw}} means "do not escape".
        // What matters is that it is inert text: no directive ran, and nothing evaluates it.
        // Re-rendering the OUTPUT must not find anything to run either, which is the actual
        // smuggling shape: a first pass that plants a construct a second pass executes.
        try {
            $engine->render($out, $variables);
        } catch (\Cresset\TemplateParser\TemplateError) {
            // Refusing to render the output is also "nothing ran" - what is being asserted
            // is the absence of execution, not the presence of a second rendering.
        }
        self::assertSame([], $executed, 'a second pass over the output executed a directive');
    }

    public static function hostile(): array
    {
        $block = '{{block class=Magento\Email\Block\Adminhtml\Template\Preview}}';
        return [
            'block via variable'      => ['{{var a|raw}}', ['a' => $block]],
            'block behind if'         => ['{{var a|raw}}', ['a' => '{{if x}}' . $block . '{{/if}}']],
            'stylesmuggler shape'     => ['{{var a|raw}}', ['a' =>
                '{{if postcode}}{{var postcode}}{{/if}}{{/var}}{{if postcode}}{{var postcode}}{{/if}}'
                . '{{if city}}' . $block . '{{/if}}']],
            'self referential var'    => ['{{var a|raw}}', ['a' => '{{var a}}']],
            'mutually referential'    => ['{{var a|raw}}{{var b|raw}}', ['a' => '{{var b}}', 'b' => '{{var a}}']],
            'php open tag in value'   => ['{{var a|raw}}', ['a' => '<?php system("id"); ?>']],
            'directive in a path'     => ['{{var a.b|raw}}', ['a' => ['b' => $block]]],
            'very long value'         => ['{{var a|raw}}', ['a' => str_repeat($block, 200)]],
            'many delimiters'         => ['{{var a|raw}}', ['a' => str_repeat('{{', 500) . str_repeat('}}', 500)]],
            'nested braces in value'  => ['{{var a|raw}}', ['a' => '{{{{{{var x}}}}}}']],
        ];
    }

    /** Without |raw, a value carrying markup is escaped. */
    public function testValuesAreEscapedWithoutRaw(): void
    {
        self::assertSame(
            '&lt;?php system(&quot;id&quot;); ?&gt;',
            TemplateEngine::compatible()->render('{{var a}}', ['a' => '<?php system("id"); ?>'])
        );
    }

    /** Pathological input must not take pathological time. */
    public function testAdversarialInputDoesNotDegradeBadly(): void
    {
        $engine = TemplateEngine::compatible();
        $template = str_repeat('{{var a}} text {{if a}}y{{/if}} ', 2000);

        $start = microtime(true);
        $engine->render($template, ['a' => 'v']);
        $elapsed = microtime(true) - $start;

        self::assertLessThan(2.0, $elapsed, 'rendering 2000 directives should not take seconds');
    }

    /**
     * A missing `}` does not eat the closing tag - the lexer refuses to run on.
     *
     * A span ends at the first `}}`, so `{{var b}` - one brace short - WOULD run on to
     * whatever `}}` comes next, which is the block's own closing tag. The tag then vanishes
     * into the parameters, the {{if}} looks unclosed, and with an {{else}} in the way both
     * branches render: one deleted brace in the stock header.html produced
     * `width="width="180"`, a duplicated unterminated attribute, silently.
     *
     * Nothing legitimate puts a `{{` inside a directive's parameters, so a second opener
     * inside a span means this `{{` is not the opener. Reading it as text leaves the closing
     * tag where the author put it - and lands byte-for-byte on what the filter renders,
     * neutralizer encoding included.
     */
    public function testAMissingBraceDoesNotSwallowTheClosingTag(): void
    {
        $engine = TemplateEngine::compatible();

        self::assertSame(
            'A&#123;&#123;var b}B',
            $engine->render('{{if a}}A{{var b}B{{/if}}', ['a' => 1, 'b' => 2])
        );

        // The shape from header.html: the {{else}} is seen, so only one branch renders.
        self::assertSame(
            'width="&#123;&#123;var c}"',
            $engine->render('{{if a}}width="{{var c}"{{else}}width="180"{{/if}}', ['a' => 1, 'c' => 5])
        );
    }

    /** A genuinely unclosed block still gets the plain message - nothing ate its tag. */
    public function testAGenuinelyUnclosedBlockStillSaysSo(): void
    {
        try {
            TemplateEngine::withOptions(Options::strict())->render('{{if a}}A', ['a' => 1]);
            self::fail('expected a syntax error');
        } catch (SyntaxError $e) {
            self::assertStringContainsString('Unclosed directive {{if}}', $e->getMessage());
            self::assertStringNotContainsString('swallowed', $e->getMessage());
        }
    }
}
