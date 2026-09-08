<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use MageOS\TemplateParser\Context;
use MageOS\TemplateParser\LegacyIncompatibility;
use MageOS\TemplateParser\LegacyIncompatibleError;
use MageOS\TemplateParser\Options;
use MageOS\TemplateParser\TemplateEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Compatible mode refuses nesting the legacy filter cannot render.
 *
 * Legacy manages exactly two levels with differing names; anything else raises a TypeError.
 * Compatible means bug-for-bug, so the capability matches: an engine that renders what the
 * old one crashes on is a better engine, not a compatible one, and `lenient` and `strict`
 * are the modes for wanting that.
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

    /** Three levels exceeds legacy even when the innermost name is new. */
    public function testThreeLevelsIsRefused(): void
    {
        $this->expectException(LegacyIncompatibleError::class);
        TemplateEngine::compatible()->render(
            '{{depend a}}{{if b}}{{depend c}}X{{/depend}}{{/if}}{{/depend}}',
            ['a' => 1, 'b' => 1, 'c' => 1]
        );
    }

    public function testDepthReportUsesTheLegacyLimitNotTheConfiguredOne(): void
    {
        // maxNestingDepth is 3, so this renders; but legacy manages only 2.
        // Every variable goes in the context: render() refuses a context AND a variables
        // array, which is how this test used to render with `xs` silently out of scope.
        $context = new Context(['a' => 1, 'b' => 1, 'c' => 1, 'xs' => ['q']]);
        $engine = TemplateEngine::withOptions(
            Options::compatible()->withMaxNestingDepth(4)->withRefuseLegacyIncompatible(false)
        );
        $engine->render('{{depend a}}{{if b}}{{for i in xs}}X{{/for}}{{/if}}{{/depend}}', context: $context);

        $found = $context->incompatibilities();
        self::assertCount(1, $found);
        self::assertSame(LegacyIncompatibility::NESTING_DEPTH, $found[0]->kind);
        self::assertStringContainsString('at most 2', $found[0]->message);
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
