<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use MageOS\TemplateParser\Ast\DirectiveNode;
use MageOS\TemplateParser\Context;
use MageOS\TemplateParser\Options;
use MageOS\TemplateParser\TemplateEngine;
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
        self::assertStringContainsString('{{block', $out);
    }

    /**
     * Same-name nesting is refused, matching legacy's capability. The crash itself is not
     * reproduced - a refusal with a diagnostic beats a TypeError - but the template is
     * equally unrenderable either way, which is what bug-for-bug means here.
     */
    public function testSameNameNestingIsRefusedLikeLegacy(): void
    {
        $this->expectException(\MageOS\TemplateParser\LegacyIncompatibleError::class);
        $this->engine->render('{{depend a}}A{{depend b}}B{{/depend}}Z{{/depend}}', ['a' => 1, 'b' => 1]);
    }

    /** The degenerate fatals - empty directive names - are still rendered, not reproduced. */
    public function testDegenerateFatalsAreNotReproduced(): void
    {
        // Legacy raises a TypeError on an empty directive name; this renders it as text.
        self::assertSame('A{{100}}B', $this->engine->render('A{{100}}B', ['x' => 1]));
        self::assertSame('{{ var x }}', $this->engine->render('{{ var x }}', ['x' => 1]));
    }

    /** The nesting bound still applies. */
    public function testNestingLimitStillApplies(): void
    {
        // Legacy incompatibility bites first here, at two levels rather than four.
        $this->expectException(\MageOS\TemplateParser\LegacyIncompatibleError::class);
        $this->engine->render(
            '{{if a}}{{if a}}{{if a}}{{if a}}X{{/if}}{{/if}}{{/if}}{{/if}}',
            ['a' => 1]
        );

        $permissive = TemplateEngine::withOptions(
            Options::compatible()->withRefuseLegacyIncompatible(false)
        );
        $this->expectException(\MageOS\TemplateParser\NestingLimitError::class);
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
