<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Context;
use Cresset\TemplateParser\LegacyIncompatibility;
use Cresset\TemplateParser\LegacyIncompatibleError;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\TemplateEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Compatible mode refuses nesting the legacy filter cannot render.
 *
 * The legacy constraint is about REPEATED NAMES, not depth: a directive cannot contain
 * itself, at any distance, because its regex body is lazy and the fragment it hands on
 * carries an unclosed copy. Distinct names nest fine - verified against the real filter,
 * all six orderings of {{if}}, {{depend}} and {{for}} render three deep. Three is the
 * practical ceiling only because there are three body-taking directives to choose from.
 *
 * Compatible means bug-for-bug, so the capability matches - see LegacyParityTest's
 * testCompatibleModeRefusesWhatLegacyCannotRender for the contract. `lenient` and `strict`
 * are the modes for wanting the improvement.
 *
 * Opting out with withRefuseLegacyIncompatible(false) renders the construct and records it
 * on the Context instead, for anyone who wants the improvement but still needs to know which
 * templates have stopped being runnable on the old filter.
 */
final class LegacyNestingReportTest extends TestCase
{
    /** What legacy can actually do - verified against the unpatched filter. */
    #[DataProvider('legacySupported')]
    public function testSupportedNestingIsNotReported(string $template): void
    {
        $context = new Context(['a' => 1, 'b' => 1, 'c' => 1]);
        TemplateEngine::compatible()->render($template, [], $context);

        self::assertSame([], $context->incompatibilities(), 'legacy renders this, so nothing to report');
    }

    public static function legacySupported(): array
    {
        return [
            'depend > if'         => ['{{depend a}}A{{if b}}B{{/if}}Z{{/depend}}'],
            'if > depend'         => ['{{if a}}A{{depend b}}B{{/depend}}Z{{/if}}'],
            'single level'        => ['{{if a}}A{{/if}}'],
            'siblings same name'  => ['{{depend a}}A{{/depend}}{{depend b}}B{{/depend}}'],
            'no nesting at all'   => ['{{var a}}'],
        ];
    }

    /** By default, compatible mode is exactly as capable as legacy - so it refuses. */
    #[DataProvider('legacyUnsupported')]
    public function testUnsupportedNestingIsRefusedByDefault(string $template, string $kind, string $needle): void
    {
        try {
            TemplateEngine::compatible()->render($template, ['a' => 1, 'b' => 1, 'c' => 1]);
            self::fail('compatible mode should refuse what legacy cannot render');
        } catch (LegacyIncompatibleError $e) {
            self::assertStringContainsString($needle, $e->getMessage());
        }
    }

    /** Opting out renders it and records the fact instead. */
    #[DataProvider('legacyUnsupported')]
    public function testOptingOutRendersAndReports(string $template, string $kind, string $needle): void
    {
        $context = new Context(['a' => 1, 'b' => 1, 'c' => 1]);
        $engine = TemplateEngine::withOptions(
            Options::compatible()->withRefuseLegacyIncompatible(false)
        );
        $out = $engine->render($template, [], $context);

        self::assertSame('ABZ', $out);

        $found = $context->incompatibilities();
        self::assertCount(1, $found);
        self::assertSame($kind, $found[0]->kind);
        self::assertStringContainsString($needle, $found[0]->message);
        self::assertSame(1, $found[0]->line);
    }

    public static function legacyUnsupported(): array
    {
        return [
            'depend > depend' => [
                '{{depend a}}A{{depend b}}B{{/depend}}Z{{/depend}}',
                LegacyIncompatibility::SAME_NAME_NESTING,
                '{{depend}} nested inside {{depend}}',
            ],
            'if > if' => [
                '{{if a}}A{{if b}}B{{/if}}Z{{/if}}',
                LegacyIncompatibility::SAME_NAME_NESTING,
                '{{if}} nested inside {{if}}',
            ],
        ];
    }

    /** A directive inside itself is refused even when the argument differs and an {{if}} sits between. */
    public function testARepeatedNameIsRefusedWithADifferentArgument(): void
    {
        $this->expectException(LegacyIncompatibleError::class);
        TemplateEngine::compatible()->render(
            '{{depend a}}{{if b}}{{depend c}}X{{/depend}}{{/if}}{{/depend}}',
            ['a' => 1, 'b' => 1, 'c' => 1]
        );
    }

    /**
     * Three levels of DISTINCT names is not a legacy failure, and must not be reported as one.
     *
     * This test previously asserted the opposite, because the engine had a depth bound of
     * two that the legacy filter does not have. All six orderings render on the real filter;
     * refusing them made compatible mode reject templates that work in production today.
     */
    #[DataProvider('distinctThreeLevelNestings')]
    public function testThreeDistinctLevelsAreNotAnIncompatibility(string $template): void
    {
        $context = new Context(['a' => 1, 'b' => 1, 'xs' => [['n' => 1]]]);
        TemplateEngine::compatible()->render($template, context: $context);

        self::assertSame([], $context->incompatibilities(), $template . ' was reported as incompatible');
    }

    public static function distinctThreeLevelNestings(): array
    {
        return [
            'if>depend>for' => ['{{if a}}{{depend b}}{{for i in xs}}X{{/for}}{{/depend}}{{/if}}'],
            'if>for>depend' => ['{{if a}}{{for i in xs}}{{depend b}}X{{/depend}}{{/for}}{{/if}}'],
            'depend>if>for' => ['{{depend a}}{{if b}}{{for i in xs}}X{{/for}}{{/if}}{{/depend}}'],
            'depend>for>if' => ['{{depend a}}{{for i in xs}}{{if b}}X{{/if}}{{/for}}{{/depend}}'],
            'for>if>depend' => ['{{for i in xs}}{{if a}}{{depend b}}X{{/depend}}{{/if}}{{/for}}'],
            'for>depend>if' => ['{{for i in xs}}{{depend a}}{{if b}}X{{/if}}{{/depend}}{{/for}}'],
        ];
    }

    /** A repeated name still is one, however far apart. */
    public function testARepeatedNameIsStillRefusedAtAnyDistance(): void
    {
        $context = new Context(['a' => 1, 'b' => 1, 'xs' => [['n' => 1]]]);
        $engine = TemplateEngine::withOptions(
            Options::compatible()->withMaxNestingDepth(5)->withRefuseLegacyIncompatible(false)
        );
        $engine->render('{{depend a}}{{if b}}{{depend a}}X{{/depend}}{{/if}}{{/depend}}', context: $context);

        $found = $context->incompatibilities();
        self::assertCount(1, $found);
        self::assertSame(LegacyIncompatibility::SAME_NAME_NESTING, $found[0]->kind);
    }

    /** The refusal message says why, and how to allow it. */
    public function testRefusalExplainsItself(): void
    {
        $engine = TemplateEngine::compatible();

        try {
            $engine->render('{{if a}}A{{if b}}B{{/if}}Z{{/if}}', ['a' => 1, 'b' => 1]);
            self::fail('expected the construct to be refused');
        } catch (LegacyIncompatibleError $e) {
            self::assertStringContainsString('{{if}} nested inside {{if}}', $e->getMessage());
            self::assertStringContainsString('renders here but not on the legacy filter', $e->getMessage());
        }
    }

    public function testRefuseModeStillAllowsWhatLegacySupports(): void
    {
        $engine = TemplateEngine::compatible();
        self::assertSame(
            'ABZ',
            $engine->render('{{depend a}}A{{if b}}B{{/if}}Z{{/depend}}', ['a' => 1, 'b' => 1])
        );
    }

    /** Reporting is a compatibility concern; the non-legacy modes stay quiet. */
    public function testStrictAndLenientModesDoNotReport(): void
    {
        foreach ([new TemplateEngine(), TemplateEngine::lenient()] as $engine) {
            $context = new Context(['a' => 1, 'b' => 1]);
            $engine->render('{{if a}}A{{if b}}B{{/if}}Z{{/if}}', [], $context);
            self::assertSame([], $context->incompatibilities());
        }
    }

    public function testIncompatibilityDescribesItsLocation(): void
    {
        $context = new Context(['a' => 1, 'b' => 1]);
        TemplateEngine::withOptions(Options::compatible()->withRefuseLegacyIncompatible(false))->render(
            "line one\n{{if a}}A{{if b}}B{{/if}}Z{{/if}}",
            [],
            $context
        );

        $found = $context->incompatibilities()[0];
        self::assertSame(2, $found->line);
        self::assertStringContainsString('line 2', $found->describe());
    }
}
