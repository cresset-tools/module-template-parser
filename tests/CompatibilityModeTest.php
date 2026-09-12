<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Ast\DirectiveNode;
use Cresset\TemplateParser\Context;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\TemplateEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `compatible` mode reproduces the legacy filter's observable rendering, so it can be
 * switched on without changing what customers see — while keeping the structural safety
 * properties, which are not negotiable in any mode.
 *
 * Parity measured by tools/parity.php over the shared directive surface: 138/138.
 */
final class CompatibilityModeTest extends TestCase
{
    private TemplateEngine $engine;

    protected function setUp(): void
    {
        $this->engine = TemplateEngine::compatible();
    }

    /** Legacy tests `resolve(...) == ''`, which on PHP 8 makes 0, '0' and [] truthy. */
    #[DataProvider('legacyTruthiness')]
    public function testLegacyTruthiness(mixed $value, string $expected): void
    {
        self::assertSame($expected, $this->engine->render('{{if a}}Y{{else}}N{{/if}}', ['a' => $value]));
    }

    public static function legacyTruthiness(): array
    {
        return [
            'int 0 is TRUTHY'      => [0, 'Y'],
            "str '0' is TRUTHY"    => ['0', 'Y'],
            'empty array TRUTHY'   => [[], 'Y'],
            'float 0.0 is TRUTHY'  => [0.0, 'Y'],
            'null is falsy'        => [null, 'N'],
            'false is falsy'       => [false, 'N'],
            'empty string falsy'   => ['', 'N'],
            'true is truthy'       => [true, 'Y'],
            'non-empty is truthy'  => ['x', 'Y'],
        ];
    }

    /** Strict mode fixes it; the two modes must genuinely differ here. */
    public function testStrictModeUsesStandardTruthiness(): void
    {
        $strict = new TemplateEngine();
        foreach ([0, '0', []] as $falsy) {
            self::assertSame('N', $strict->render('{{if a}}Y{{else}}N{{/if}}', ['a' => $falsy]));
            self::assertSame('Y', $this->engine->render('{{if a}}Y{{else}}N{{/if}}', ['a' => $falsy]));
        }
    }

    /**
     * StrictResolver only attempts member access when the parent is an array or DataObject.
     * On a scalar parent the cursor never advances, so the parent itself is the result.
     */
    public function testScalarParentYieldsTheParent(): void
    {
        self::assertSame('Demo', $this->engine->render('{{var store.frontend_name}}', ['store' => 'Demo']));
    }

    /** But an array parent with a missing key DID attempt access, and yields nothing. */
    public function testArrayParentWithMissingKeyYieldsNothing(): void
    {
        self::assertSame('', $this->engine->render('{{var a.nosuch}}', ['a' => ['b' => 1]]));
        self::assertSame('', $this->engine->render('{{var a.b}}', ['a' => []]));
    }

    public function testResolvablePathsStillResolve(): void
    {
        self::assertSame('deep', $this->engine->render('{{var a.b}}', ['a' => ['b' => 'deep']]));
    }

    /** Legacy casts arrays, emitting the literal string "Array". */
    public function testArraysStringifyAsArray(): void
    {
        self::assertSame('Array', $this->engine->render('{{var a}}', ['a' => [1, 2]]));
        self::assertSame('', (new TemplateEngine())->render('{{var a}}', ['a' => [1, 2]]));
    }

    /** With no variables at all, legacy returns directives verbatim (the validation path). */
    public function testNoVariablesPassesDirectivesThrough(): void
    {
        self::assertSame('{{var x}}', $this->engine->render('{{var x}}', []));
        self::assertSame('{{if x}}Y{{/if}}', $this->engine->render('{{if x}}Y{{/if}}', []));
        self::assertSame('a{{var x}}b', $this->engine->render('a{{var x}}b', []));
    }

    // ---------------------------------------------------------------------------------
    // The properties compatible mode does NOT relax.
    // ---------------------------------------------------------------------------------

    /** The whole point: compatible output, but a value is still never source. */
    public function testDirectivesInDataAreStillNeverExecuted(): void
    {
        $instantiated = [];
        $engine = TemplateEngine::compatible();
        $engine->evaluator()->register('block', function (DirectiveNode $n) use (&$instantiated, $engine): string {
            $instantiated[] = $engine->evaluator()->params($n)['class'] ?? '';
            return '[X]';
        });

        $payload = '{{if city}}{{block class=Magento\Email\Block\Adminhtml\Template\Preview}}{{/if}}';
        $out = $engine->render('Billing: {{var addr|raw}}', ['addr' => $payload]);

        self::assertSame([], $instantiated);
        // The payload survives as text. Since the StyleSmuggler hardening, the legacy filter
        // encodes `{{` in resolved output and compatible mode reproduces that, so the braces
        // come back as entities - inert either way, and the point is that nothing ran.
        self::assertStringContainsString('block class=Magento\Email\Block', $out);
        self::assertStringContainsString('&#123;&#123;block', $out);
    }

    /**
     * Same-name nesting is refused, matching legacy's capability. The crash itself is not
     * reproduced - a refusal with a diagnostic beats a TypeError - but the template is
     * equally unrenderable either way, which is what bug-for-bug means here.
     */
    public function testSameNameNestingIsRefusedLikeLegacy(): void
    {
        $this->expectException(\Cresset\TemplateParser\LegacyIncompatibleError::class);
        $this->engine->render('{{depend a}}A{{depend b}}B{{/depend}}Z{{/depend}}', ['a' => 1, 'b' => 1]);
    }

    /**
     * Degenerate constructs - a {{...}} not starting with a letter - are refused too.
     * Legacy raises a TypeError on those, so a compatible engine must not render them.
     * See DegenerateConstructTest for the full shape-by-shape mapping.
     */
    public function testDegenerateConstructsAreRefused(): void
    {
        foreach (['A{{100}}B', '{{ var x }}', '{{}}'] as $template) {
            try {
                $this->engine->render($template, ['x' => 1]);
                self::fail('expected refusal for ' . $template);
            } catch (\Cresset\TemplateParser\LegacyIncompatibleError $e) {
                self::assertStringContainsString('does not start with a letter', $e->getMessage(), $template);
            }
        }
    }

    /**
     * The excerpt points at the directive that is actually incompatible.
     *
     * Every modifier-chain refusal used to read its position off a field that only the
     * {{var}} handler wrote, so a {{trans}} chain was reported at offset 0 - or, on a reused
     * engine, at wherever the last {{var}} in some earlier template had been. Compatible
     * mode refuses by default, so that excerpt is the first thing a user sees.
     */
    public function testAModifierRefusalPointsAtItsOwnDirective(): void
    {
        $template = "Hello\n{{var who}}\nand\n{{trans \"hi\"|nl2br:x}}";

        try {
            $this->engine->render($template, ['who' => 'you']);
            self::fail('expected a refusal');
        } catch (\Cresset\TemplateParser\LegacyIncompatibleError $e) {
            // Line 4 is the {{trans}}; line 2 is the {{var}} whose offset it used to borrow.
            self::assertStringContainsString('on line 4, column 1', $e->getMessage());
        }
    }

    /**
     * Inside a `{{for}}` body, only a real loop OPENER loses the exemption.
     *
     * Legacy's `ForDirective` does not render its body - it `str_replace`s each construct
     * with the variable resolution of that construct's parameter text - so nothing in there
     * reaches a directive processor and nothing in there can be a legacy fatal. The one
     * exception is a nested loop, which strands the outer `{{/for}}` and really does raise.
     *
     * The test for that exception was `str_starts_with($construct, '{{for')`, which is wider
     * than `LOOP_PATTERN`: `{{for2 a}}` is not a loop opener there, and refusing it claimed
     * a crash the filter does not have. Measured against a real filter at the time of this
     * commit, with `a = [['n'=>1],['n'=>2]]`:
     *
     *     {{for r in a}}[{{for2 x}}]{{/for}}              legacy `[][]`, renders here
     *     {{for r in a}}[{{format x in a}}y{{/for}}]{{/for}}   legacy TypeError, refused here
     *
     * No corpus case puts a `{{for`-prefixed construct inside a loop body, which is why
     * `testNoRefusalClaimsACrashTheFilterDoesNotHave` never saw it.
     */
    #[DataProvider('constructsInsideALoopBody')]
    public function testOnlyANestedLoopOpenerIsRefusedInsideALoopBody(string $body, bool $refused): void
    {
        $template = '{{for r in a}}[' . $body . ']{{/for}}';
        $rows = ['a' => [['n' => 1], ['n' => 2]]];

        if ($refused) {
            $this->expectException(\Cresset\TemplateParser\LegacyIncompatibleError::class);
            $this->engine->render($template, $rows);

            return;
        }

        self::assertIsString($this->engine->render($template, $rows));
    }

    public static function constructsInsideALoopBody(): array
    {
        return [
            // Not loop openers: LOOP_PATTERN needs a ` in ` before the closing braces.
            'digit in the name'  => ['{{for2 x}}', false],
            'dot after the name' => ['{{for.x}}', false],
            'longer name'        => ['{{forloop}}', false],
            'unrelated name'     => ['{{var2 x}}', false],
            // Loop openers, whatever the name looks like - the pattern matches `{{for` then
            // anything up to ` in`, so this is one to the filter as much as `{{for x in a}}`.
            'nested for'         => ['{{for s in a}}y{{/for}}', true],
            'nested format'      => ['{{format x in a}}y{{/for}}', true],
        ];
    }

    /** But prose beginning with a letter is rendered verbatim, exactly as legacy does. */
    public function testProseBeginningWithALetterIsRenderedVerbatim(): void
    {
        foreach (['{{Forgot Your Password?}}', '{{Sign In}}', 'a{{color:red}}b'] as $template) {
            self::assertSame($template, $this->engine->render($template, ['x' => 1]));
        }
    }

    /** Same-name nesting is refused before the depth bound is ever reached. */
    public function testLegacyIncompatibilityBitesBeforeTheNestingBound(): void
    {
        $this->expectException(\Cresset\TemplateParser\LegacyIncompatibleError::class);
        $this->engine->render(
            '{{if a}}{{if a}}{{if a}}{{if a}}X{{/if}}{{/if}}{{/if}}{{/if}}',
            ['a' => 1]
        );
    }

    /**
     * With that refusal off, the depth bound still applies.
     *
     * This used to be the second half of the test above, after an expectException that had
     * already fired - so it never ran, and NestingLimitError was asserted nowhere in this file.
     */
    public function testNestingLimitStillApplies(): void
    {
        $permissive = TemplateEngine::withOptions(
            Options::compatible()->withRefuseLegacyIncompatible(false)
        );

        $this->expectException(\Cresset\TemplateParser\NestingLimitError::class);
        $permissive->render('{{if a}}{{if a}}{{if a}}{{if a}}X{{/if}}{{/if}}{{/if}}{{/if}}', ['a' => 1]);
    }

    /** Deferral is still structured, not text in the output stream. */
    public function testDeferralIsStillStructured(): void
    {
        $context = new Context(['x' => 1]);
        $out = $this->engine->render('a{{inlinecss file="e.css"}}b', [], $context);
        self::assertSame('ab', $out);
        self::assertSame([['kind' => 'inlinecss', 'payload' => ['file' => 'e.css']]], $context->deferred());
    }

    public function testCompatibleIsLenientPlusQuirks(): void
    {
        $o = Options::compatible();
        self::assertFalse($o->strictSyntax);
        self::assertFalse($o->strictDirectives);
        self::assertFalse($o->strictVariables);
        self::assertTrue($o->legacyQuirks);
        self::assertSame(Options::DEFAULT_MAX_NESTING_DEPTH, $o->maxNestingDepth);
    }
}
